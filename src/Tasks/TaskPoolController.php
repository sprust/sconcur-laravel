<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\Control\ControlCommandDto;
use SConcur\WaitGroup;

/**
 * The coroutine that watches for a reason to stop and then sees the stop through.
 *
 * It is a member of the same group as the tasks and does no work of its own, which is
 * the point: something has to execute PHP on a regular tick for a pending signal handler
 * to run at all, and something has to poll the control channel. A pool whose tasks were
 * all parked in the extension would notice neither.
 *
 * It never touches a task's coroutine — nothing can. It writes to the shared state, and
 * each task's loop reads it between ticks. The one forcible tool is unwinding the whole
 * group, which is why it is the last resort and why it is only ever used on a full stop.
 */
class TaskPoolController
{
    /** Raised by the signal handler, read on the next tick. */
    protected bool $signalled = false;

    /** Handled commands are never replayed; this is the floor for the next one. */
    protected float $handledUpTo;

    /** Whether the stop was one a fresh process should follow. */
    protected bool $restartWanted = false;

    public function __construct(
        protected TaskPoolState $state,
        protected TaskRegistry $registry,
        protected ControlChannel $channel,
        protected CooperativeSleeper $sleeper,
        protected TaskPoolOptions $options,
        protected TaskPoolLogger $logger,
        protected int $masterPid = 0,
        protected ?TaskPoolTelemetry $telemetry = null,
    ) {
        $this->handledUpTo = $state->startedAt();
    }

    /**
     * True when the pool stopped because it hit its memory limit — the one stop that
     * wants a fresh process after it.
     *
     * Every other stop is deliberate: an operator ran sconcur:tasks:stop, a signal
     * arrived, or the master went away. Restarting after those would undo them, which is
     * why the pool answers this question at all rather than always exiting the same way.
     */
    public function restartWanted(): bool
    {
        return $this->restartWanted;
    }

    /** Called from the signal handler, so it does the least it can get away with. */
    public function signalled(): void
    {
        $this->signalled = true;
    }

    public function run(WaitGroup $group): void
    {
        $tick = $this->options->sleepChunkMs / 1000;

        while (true) {
            $this->readSignal();
            $this->readChannel();
            $this->checkMemory();
            $this->checkMaster();

            // Reported from here because this is the one coroutine that wakes on a fixed
            // interval no matter what the tasks are doing; the sender itself decides
            // whether a snapshot is due.
            $this->telemetry?->push();

            if ($this->finished($group)) {
                return;
            }

            $this->sleeper->sleep($tick);
        }
    }

    protected function readSignal(): void
    {
        if (!$this->signalled) {
            return;
        }

        $this->signalled = false;

        if ($this->state->isStopRequested()) {
            // Asked twice. The operator is saying they will not wait for the drain,
            // so the deadline becomes now and the next pass unwinds the group.
            $this->log('signal again — not waiting for the drain');
            $this->state->setHardDeadlineAt(microtime(true));

            return;
        }

        $this->log('signal received — stopping');
        $this->requestStop();
    }

    protected function readChannel(): void
    {
        foreach ($this->channel->takeAll($this->handledUpTo) as $command) {
            $this->handleCommand($command);
        }
    }

    protected function handleCommand(ControlCommandDto $command): void
    {
        $this->handledUpTo = max($this->handledUpTo, $command->at);

        $this->log('command: ' . $command->describe());

        if ($command->action === ControlActionEnum::Restart) {
            $this->restart($command);

            return;
        }

        if ($command->targetsAll()) {
            $this->requestStop();

            return;
        }

        if (!$this->registry->has($command->target)) {
            $this->log('no task named ' . $command->target . ' — ignored');

            return;
        }

        // One task only: it stops ticking for the life of this process and the rest
        // carry on. Deliberately not persisted — a restart of the process brings it
        // back, which is what the cron stop command did before.
        $this->state->deactivate($command->target);
    }

    protected function restart(ControlCommandDto $command): void
    {
        if (!$command->targetsAll() && !$this->registry->has($command->target)) {
            $this->log('no task named ' . $command->target . ' — ignored');

            return;
        }

        foreach ($this->state->names() as $name) {
            if ($command->targets($name)) {
                $this->state->requestRelaunch($name);
            }
        }
    }

    /**
     * A leak in one task takes the whole process down, so the limit is the pool's, not
     * the task's. It goes through the same stop as everything else and the supervisor
     * brings a fresh process up.
     */
    protected function checkMemory(): void
    {
        if ($this->state->isStopRequested()) {
            return;
        }

        $used = memory_get_usage(true);

        if ($used < $this->options->memoryLimitBytes()) {
            return;
        }

        $this->log(sprintf('memory limit reached (%d MiB) — stopping', intdiv($used, 1024 * 1024)));

        $this->restartWanted = true;

        $this->requestStop();
    }

    /**
     * Stands down when the master that spawned this pool is gone.
     *
     * Compared by parent pid rather than by signalling the master's own: the kernel
     * reparents an orphan, so getppid() stops matching and stays that way, which a pid
     * that has since been reused would not. The library's servers check the same way.
     */
    protected function checkMaster(): void
    {
        if ($this->masterPid <= 0 || $this->state->isStopRequested()) {
            return;
        }

        if (!function_exists('posix_getppid') || posix_getppid() === $this->masterPid) {
            return;
        }

        $this->log('master ' . $this->masterPid . ' is gone — stopping');
        $this->requestStop();
    }

    protected function requestStop(): void
    {
        $this->state->stopAll();
        $this->state->setHardDeadlineAt(microtime(true) + $this->options->shutdownTimeoutSeconds);
    }

    /**
     * True when the controller is done: everything drained, or the deadline ran out and
     * the group had to be unwound.
     */
    protected function finished(WaitGroup $group): bool
    {
        $running = $this->state->runningNames();

        if ($running === []) {
            $this->log('all tasks stopped');

            return true;
        }

        // The same ending by the other road: a task stopped by name parks rather than
        // finishing, so that a restart has something to wake, and its coroutine stays in
        // `running`. With every task parked there is nothing left to tick and so nothing
        // left to supervise — which is how a pool whose tasks were stopped one by one
        // still comes to an end.
        if (!$this->state->isStopRequested() && $this->state->activeNames() === []) {
            $this->log('all tasks stopped');

            $this->requestStop();

            return false;
        }

        $deadline = $this->state->hardDeadlineAt();

        if ($deadline === null || microtime(true) < $deadline) {
            return false;
        }

        if (!$this->state->isStopRequested()) {
            // A deadline without a full stop belongs to a single task that would not
            // let go. Unwinding the group would take the others with it, so this is
            // reported and left alone.
            $this->log('still inside a tick past the deadline: ' . implode(', ', $running));
            $this->state->setHardDeadlineAt(microtime(true) + $this->options->shutdownTimeoutSeconds);

            return false;
        }

        $this->log('deadline reached, unwinding: ' . implode(', ', $running));

        $group->stop();

        return true;
    }

    protected function log(string $message): void
    {
        $this->logger->log('controller', $message);
    }
}
