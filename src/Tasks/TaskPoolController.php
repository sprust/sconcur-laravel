<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Support\ProcessMemory;
use SConcur\Laravel\Tasks\Control\ControlCommandDto;
use SConcur\Process\ProcessControl;
use SConcur\Scheduler\Scheduler;
use SConcur\WaitGroup;

/**
 * The coroutine that watches for a reason to stop and then sees the stop through.
 *
 * It is a member of the same group as the tasks and does no work of its own, which is
 * the point: something has to execute PHP on a regular tick to take the signals the
 * extension recorded, and to poll the control channel. A pool whose tasks were all parked
 * in the extension would notice neither.
 *
 * It never touches a task's coroutine — nothing can. It writes to the shared state, and
 * each task's loop reads it between ticks. The one forcible tool is unwinding the whole
 * group, which is why it is the last resort and why it is only ever used on a full stop.
 */
class TaskPoolController
{
    /** A signal taken or handed over, acted on by the next pass. */
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
        protected ProcessMemory $processMemory = new ProcessMemory(),
    ) {
        $this->handledUpTo = $state->startedAt();
    }

    /**
     * True when the pool stopped for a reason a fresh process should follow: the memory
     * limit, or a signal while a master supervises the pool.
     *
     * The master's own stop, reload and retire send SIGTERM too, but for a worker it is
     * draining or rolling the master reads no exit code, so there the answer changes
     * nothing. Where it is read, the signal is the master's watchdog killing a pool whose
     * PHP thread stopped answering, or an operator's kill; exiting zero there would leave
     * the pool down for good under `on-failure`. Under a master the pool is stopped for
     * good with sconcur:tasks:stop, not with a signal.
     *
     * The deliberate stops exit zero: sconcur:tasks:stop, a signal to a pool running
     * without a master (Ctrl+C in a terminal), and the master going away. Restarting after
     * those would undo them, which is why the pool answers this question at all.
     */
    public function restartWanted(): bool
    {
        return $this->restartWanted;
    }

    /**
     * Records a signal for the next pass to act on, as one the extension recorded is: the
     * same path, for a caller that has a signal of its own to hand over.
     */
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

            // The pool is a loop of its own rather than a library server, so it tells its
            // master that it is up itself. Only the first call says anything, and outside
            // a worker the master forked it does nothing.
            Scheduler::get()->markReady();

            if ($this->finished($group)) {
                return;
            }

            $this->sleeper->sleep($tick);
        }
    }

    protected function readSignal(): void
    {
        // The signals TaskPool watches, recorded by the extension since the last pass.
        // Several of the same kind between two passes arrive as one, as they would have
        // through a handler that only raises a flag.
        if (ProcessControl::takeSignals() !== []) {
            $this->signalled = true;
        }

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

        if ($this->masterPid > 0) {
            $this->restartWanted = true;
        }

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
            // The operator's stop outranks a restart asked for before it — a signal whose
            // drain this arrives during — so the pool stays down as asked.
            $this->restartWanted = false;

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
     *
     * The limit is compared against the larger of the PHP heap and the resident set size.
     * The heap alone misses everything the extension allocates, and a native leak would
     * grow the process until the container runs out of memory without ever tripping it.
     * Where /proc is not available the RSS reads as zero and the heap decides alone.
     */
    protected function checkMemory(): void
    {
        if ($this->state->isStopRequested()) {
            return;
        }

        $heapBytes = memory_get_usage(true);
        $rssBytes  = $this->processMemory->rssBytes();

        $figure    = $rssBytes > $heapBytes ? 'rss' : 'heap';
        $usedBytes = max($heapBytes, $rssBytes);

        if ($usedBytes < $this->options->memoryLimitBytes()) {
            return;
        }

        $this->log(
            sprintf('memory limit reached (%s %d MiB) — stopping', $figure, intdiv($usedBytes, 1024 * 1024)),
        );

        $this->restartWanted = true;

        $this->requestStop();
    }

    /**
     * Stands down when the master that spawned this pool is gone.
     *
     * Compared by parent pid rather than by signalling the master's own: the kernel
     * reparents an orphan, so the parent pid stops matching and stays that way, which a pid
     * that has since been reused would not. The library's servers check the same way.
     */
    protected function checkMaster(): void
    {
        if ($this->masterPid <= 0 || $this->state->isStopRequested()) {
            return;
        }

        if (ProcessControl::parentPid() === $this->masterPid) {
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
