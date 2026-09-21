<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\CooperativeSleeper;
use SConcur\Laravel\Tasks\TaskPoolController;
use SConcur\Laravel\Tasks\TaskPoolLogger;
use SConcur\Laravel\Tasks\TaskPoolOptions;
use SConcur\Laravel\Tasks\TaskPoolState;
use SConcur\Laravel\Tasks\TaskRegistry;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * What a signal's stop asks for. Under a master it can only be the watchdog or an
 * operator — the master reads no exit code of a worker it stops or rolls — and a clean
 * exit would leave an `on-failure` pool down for good. Without a master it is Ctrl+C, and
 * a clean exit is what a terminal expects.
 *
 * The controller is driven directly over a pool with no tasks, which finishes on its first
 * pass: the signal is read before anything else on that pass.
 */
class TaskPoolSignalTest extends BaseTestCase
{
    protected string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = (string) tempnam(sys_get_temp_dir(), 'sconcur-task-pool-log');
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    #[Test]
    public function aSignalUnderAMasterAsksForARestart(): void
    {
        self::assertTrue($this->restartWantedAfterASignal(masterPid: 4711));
    }

    #[Test]
    public function aSignalWithoutAMasterDoesNot(): void
    {
        self::assertFalse($this->restartWantedAfterASignal(masterPid: 0));
    }

    /**
     * A pool already stopping — sconcur:tasks:stop — keeps the stop it was asked for: the
     * signal only cuts the drain short.
     */
    #[Test]
    public function aSignalDuringAStopDoesNot(): void
    {
        $taskPoolState = new TaskPoolState([]);

        $taskPoolState->stopAll();

        self::assertFalse(
            $this->restartWantedAfterASignal(
                masterPid: 4711,
                taskPoolState: $taskPoolState,
                logged: 'signal again',
            ),
        );
    }

    protected function restartWantedAfterASignal(
        int $masterPid,
        ?TaskPoolState $taskPoolState = null,
        string $logged = 'signal received',
    ): bool {
        $app = $this->getApp();

        $taskPoolController = new TaskPoolController(
            state: $taskPoolState ?? new TaskPoolState([]),
            registry: $app->make(TaskRegistry::class),
            channel: $app->make(ControlChannel::class),
            sleeper: $app->make(CooperativeSleeper::class),
            options: $app->make(TaskPoolOptions::class),
            logger: new TaskPoolLogger(stream: $this->logPath),
            masterPid: $masterPid,
        );

        $taskPoolController->signalled();
        $taskPoolController->run(WaitGroup::create());

        self::assertStringContainsString($logged, (string) file_get_contents($this->logPath));

        return $taskPoolController->restartWanted();
    }
}
