<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Scheduler\Scheduler;
use SConcur\WaitGroup;
use Throwable;

/**
 * Runs the configured tasks in one process, a coroutine per task, until something asks
 * it to stop.
 *
 * The loop lives here rather than in the tasks. A task implements one pass and the pool
 * owns everything around it — the pause, the reporting, the backoff, the rebuild, the
 * stop — so there is one implementation of all of it instead of one per task, and a task
 * cannot forget to check whether it should still be running.
 */
class TaskPool
{
    /**
     * The exit code of a stop that wants a fresh process — today only the memory limit.
     *
     * It has to be non-zero, and the group's restartPolicy has to be `on-failure`, for
     * the two kinds of stop to be told apart at all: under `always` a supervised pool
     * comes back from every exit, so `sconcur:tasks:stop` would drain the tasks, exit
     * cleanly and be replaced within the second — a stop that does not stop.
     */
    public const int EXIT_RESTART = 75;

    public function __construct(
        protected TaskRegistry $registry,
        protected ControlChannel $channel,
        protected CooperativeSleeper $sleeper,
        protected TaskPoolOptions $options,
        protected TaskPoolLogger $logger,
        protected ExceptionHandler $exceptions,
    ) {
    }

    /**
     * @param list<string> $only      names to run; empty means every configured task
     * @param int          $masterPid the supervising master, 0 when running standalone
     */
    public function run(array $only = [], int $masterPid = 0): int
    {
        $names = $this->names($only);

        if ($names === []) {
            $this->logger->log('pool', 'no tasks to run');

            return 0;
        }

        // A lock per task rather than one for the pool. What must not happen twice is a
        // task, not a process: two copies of the cron would run schedule:run twice a
        // minute. One lock for everything also meant the two single-task entry points
        // excluded each other, which is precisely when you want them side by side —
        // `cron:start` in one terminal, the index monitor in another.
        $locks = [];

        foreach ($names as $name) {
            $lock = new TaskPoolLock($this->options->lockPath . '.' . $name);

            if (!$lock->acquire()) {
                $this->logger->log($name, 'not starting: ' . $lock->failure());

                foreach ($locks as $acquired) {
                    $acquired->release();
                }

                return 1;
            }

            $locks[] = $lock;
        }

        try {
            return $this->serve($names, $masterPid);
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }
    }

    /**
     * @param list<string> $names
     */
    protected function serve(array $names, int $masterPid = 0): int
    {
        $state      = new TaskPoolState($names);
        $metrics    = $this->options->reportTicks ? new TaskPoolMetrics(count($names)) : null;
        $telemetry  = TaskPoolTelemetry::fromEnvironment($metrics);
        $controller = new TaskPoolController(
            state: $state,
            registry: $this->registry,
            channel: $this->channel,
            sleeper: $this->sleeper,
            options: $this->options,
            logger: $this->logger,
            masterPid: $masterPid,
            telemetry: $telemetry,
        );

        $restoreSignals = $this->installSignalHandlers($controller);
        $preemption     = $this->enablePreemption();

        $this->logger->log('pool', 'started: ' . implode(', ', $names));

        $group = WaitGroup::create();

        try {
            // The controller goes in first so it is already watching before a task's
            // first tick — which may be a long one — gets a chance to run.
            $group->add(static function () use ($controller, $group): void {
                $controller->run($group);
            });

            foreach ($names as $name) {
                $group->add($this->loop($state, $name, $metrics));
            }

            $group->waitAll();
        } catch (FlowStoppedException) {
            // The controller unwound the group past the shutdown deadline. That is a
            // stop we asked for, not a failure.
        } finally {
            if ($preemption) {
                Scheduler::get()->disablePreemption();
            }

            // Closing the socket is how the collector learns the worker went away, so it
            // happens on the way out rather than being left to the process ending.
            $telemetry?->close();

            $restoreSignals();
        }

        $this->logger->log('pool', 'stopped');

        return $controller->restartWanted() ? self::EXIT_RESTART : 0;
    }

    /**
     * Drops the task instance when a relaunch has been asked for, so the next tick is
     * built fresh. A no-op when none is pending; the state hands the request over once.
     */
    protected function takeRelaunch(TaskPoolState $state, string $name): void
    {
        if (!$state->takeRelaunch($name)) {
            return;
        }

        $this->registry->forget($name);

        $this->logger->log($name, 'rebuilt');
    }

    /**
     * One task's loop: tick, pause, repeat, until the pool is stopped.
     *
     * A task stopped by name parks instead of ending: it keeps its coroutine and waits,
     * so `sconcur:tasks:restart --task=NAME` has something to wake. Ending the coroutine
     * left nothing to read the relaunch request, and the restart quietly did nothing.
     * The pool as a whole still comes to an end when no task is left ticking — see
     * TaskPoolController::finished().
     *
     * Nothing may escape here except a deliberate unwind. WaitGroup::iterate() rethrows
     * the first exception any member raises and stops the group on the way out, so one
     * task throwing would take the others down with it — the reason a failed tick is
     * reported and turned into a backoff rather than left to propagate.
     */
    protected function loop(TaskPoolState $state, string $name, ?TaskPoolMetrics $metrics = null): Closure
    {
        $definition = $this->registry->definition($name);
        $interrupt  = $state->interruptFor($name);

        return function () use ($state, $name, $definition, $interrupt, $metrics): void {
            // Yield before the first tick so WaitGroup::add() returns to the caller and
            // the remaining tasks get added: add() runs a callback up to its first
            // suspend on the adder's stack.
            Scheduler::get()->switch(0);

            $parked = false;

            try {
                while (!$state->isStopRequested()) {
                    if (!$state->isActive($name)) {
                        if (!$parked) {
                            $this->logger->log($name, 'parked');

                            $parked = true;
                        }

                        $this->park($state, $name, $definition);

                        continue;
                    }

                    $parked = false;

                    // Before the tick as well as after it. A relaunch posted while the
                    // task was paused ends the pause, and taking it only afterwards
                    // would spend one more tick on the very instance the operator asked
                    // to be replaced — with whatever state made them ask.
                    $this->takeRelaunch($state, $name);

                    $metrics?->tickStarted($name);

                    $result = $this->tick($name);

                    $metrics?->tickFinished($name, $result);

                    // And after it, so a relaunch that arrived during the tick is acted
                    // on before the pause rather than after it.
                    $this->takeRelaunch($state, $name);

                    $this->sleeper->sleep($definition->intervalFor($result), $interrupt);
                }
            } finally {
                $state->markStopped($name);

                $this->logger->log($name, 'stopped');
            }
        };
    }

    /**
     * A parked task's wait: it comes back if a relaunch arrives, and otherwise sleeps
     * the task's own idle interval. The instance is dropped on the way back in, so a
     * restarted task starts from a fresh one — which is what restarting it means.
     */
    protected function park(TaskPoolState $state, string $name, TaskDefinitionDto $definition): void
    {
        if ($state->takeRelaunch($name)) {
            $this->registry->forget($name);

            $state->activate($name);

            $this->logger->log($name, 'restarted');

            return;
        }

        $this->sleeper->sleep($definition->idle, $state->parkInterruptFor($name));
    }

    protected function tick(string $name): TickResultEnum
    {
        try {
            return $this->registry->task($name)->tick();
        } catch (FlowStoppedException | CoroutineTimeoutException $exception) {
            // A stop and a blown deadline are the scheduler unwinding this coroutine on
            // purpose. Catching them to carry on would be fighting it.
            throw $exception;
        } catch (Throwable $exception) {
            $this->exceptions->report($exception);

            $this->logger->log($name, 'tick failed: ' . $exception::class . ': ' . $exception->getMessage());

            return TickResultEnum::Failed;
        }
    }

    /**
     * @param list<string> $only
     *
     * @return list<string>
     */
    protected function names(array $only): array
    {
        if ($only === []) {
            return $this->registry->names();
        }

        return array_values(array_filter(
            $this->registry->names(),
            static fn(string $name): bool => in_array($name, $only, true),
        ));
    }

    /**
     * Enabling it is the whole reason the controller keeps its tick under a task busy
     * with computation: a stretch of PHP that never suspends would otherwise starve it,
     * and with it both the signal handler and the control channel. Native blocking calls
     * stay unpreemptable — that is what the shutdown deadline is for.
     */
    protected function enablePreemption(): bool
    {
        if ($this->options->preemptionQuantumMs <= 0 || !extension_loaded('sconcur')) {
            return false;
        }

        Scheduler::get()->enablePreemption($this->options->preemptionQuantumMs);

        return true;
    }

    /**
     * Mirrors ServerRuntimeSupportTrait::installSignalHandlers(): remember what was in
     * place, install ours, hand back the restorer. The handler does nothing but raise a
     * flag — the controller decides what it means on its next tick.
     *
     * @return Closure(): void
     */
    protected function installSignalHandlers(TaskPoolController $controller): Closure
    {
        if (!function_exists('pcntl_async_signals')) {
            return static function (): void {
            };
        }

        $signals = [SIGTERM, SIGINT, SIGQUIT];

        $previousAsync = pcntl_async_signals();

        /** @var array<int, callable|int> $previousHandlers */
        $previousHandlers = [];

        foreach ($signals as $signal) {
            $previousHandlers[$signal] = pcntl_signal_get_handler($signal);
        }

        pcntl_async_signals(true);

        $handler = static function () use ($controller): void {
            $controller->signalled();
        };

        foreach ($signals as $signal) {
            pcntl_signal($signal, $handler);
        }

        return static function () use ($signals, $previousHandlers, $previousAsync): void {
            foreach ($signals as $signal) {
                pcntl_signal($signal, $previousHandlers[$signal]);
            }

            pcntl_async_signals($previousAsync);
        };
    }
}
