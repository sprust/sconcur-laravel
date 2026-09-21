<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Servers;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;
use SConcur\Laravel\Servers\WatchdogEventForwarder;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Worker\WatchdogEvent;
use SConcur\Worker\WatchdogEventEnum;
use Workbench\App\Listeners\RecordWorkerWatchdog;

/**
 * The path a watchdog report takes in the master process: the library calls the
 * forwarder, the forwarder raises WorkerWatchdogTriggered, and the listeners the
 * workbench names in config('sconcur.listeners') hear it.
 */
class WatchdogEventForwarderTest extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        RecordWorkerWatchdog::$heard = [];
    }

    #[Test]
    public function aConfiguredListenerHearsTheWatchdog(): void
    {
        $watchdogEvent = new WatchdogEvent(
            event: WatchdogEventEnum::HeartbeatLost,
            group: 'http',
            slot: 1,
            pid: 4711,
            ageSeconds: 61.2,
            watchdogTimeoutMs: 60000,
        );

        $this->getApp()->make(WatchdogEventForwarder::class)($watchdogEvent);

        self::assertCount(1, RecordWorkerWatchdog::$heard);
        self::assertSame($watchdogEvent, RecordWorkerWatchdog::$heard[0]->watchdogEvent);
    }

    #[Test]
    public function everyStageOfOneKillIsHeard(): void
    {
        $watchdogEventForwarder = $this->getApp()->make(WatchdogEventForwarder::class);

        foreach (WatchdogEventEnum::cases() as $case) {
            $watchdogEventForwarder(new WatchdogEvent(
                event: $case,
                group: 'tasks',
                slot: 0,
                pid: 30,
                ageSeconds: null,
                watchdogTimeoutMs: 60000,
            ));
        }

        self::assertSame(
            WatchdogEventEnum::cases(),
            array_map(
                static fn(WorkerWatchdogTriggered $workerWatchdogTriggered): WatchdogEventEnum => $workerWatchdogTriggered->watchdogEvent->event,
                RecordWorkerWatchdog::$heard,
            ),
        );
    }

    #[Test]
    public function withNoListenersNothingHappens(): void
    {
        $this->getApp()->make('events')->forget(WorkerWatchdogTriggered::class);

        $this->getApp()->make(WatchdogEventForwarder::class)(new WatchdogEvent(
            event: WatchdogEventEnum::KillSurvived,
            group: 'ws',
            slot: 0,
            pid: 27,
            ageSeconds: null,
            watchdogTimeoutMs: 60000,
        ));

        self::assertSame([], RecordWorkerWatchdog::$heard);
    }
}
