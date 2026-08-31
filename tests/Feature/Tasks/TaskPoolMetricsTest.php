<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\TaskPoolMetrics;
use SConcur\Laravel\Tasks\TickResultEnum;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The counters the pool reports itself with. They travel in the snapshot's `consumers`
 * section — a tick is to a task what a delivery is to a consumer — so the panel's
 * columns fill in without knowing a task pool exists.
 */
class TaskPoolMetricsTest extends BaseTestCase
{
    #[Test]
    public function aFinishedTickIsCountedAsHandled(): void
    {
        $metrics = new TaskPoolMetrics(tasks: 2);

        $metrics->tickStarted('first');
        $metrics->tickFinished('first', TickResultEnum::Worked);

        $section = $metrics->section();

        self::assertSame(2, $section['coroutines']);
        self::assertSame(1, $section['delivered']);
        self::assertSame(1, $section['acked']);
        self::assertSame(0, $section['refused']);
        self::assertSame(0, $section['inFlight']);
    }

    #[Test]
    public function aThrownTickIsCountedAsRefused(): void
    {
        $metrics = new TaskPoolMetrics(tasks: 1);

        $metrics->tickStarted('first');
        $metrics->tickFinished('first', TickResultEnum::Failed);

        $section = $metrics->section();

        self::assertSame(1, $section['delivered']);
        self::assertSame(0, $section['acked']);
        self::assertSame(1, $section['refused']);
    }

    /**
     * An idle tick is counted nowhere. Counting it would make `Finished` measure the
     * polling interval and the average duration the cost of an empty poll rather than
     * the cost of the work.
     */
    #[Test]
    public function anIdleTickIsCountedNowhere(): void
    {
        $metrics = new TaskPoolMetrics(tasks: 1);

        $metrics->tickStarted('first');
        $metrics->tickFinished('first', TickResultEnum::Idle);

        $section = $metrics->section();

        self::assertSame(0, $section['delivered']);
        self::assertSame(0, $section['acked']);
        self::assertSame(0, $section['refused']);
        self::assertSame(0, $section['timed']);
        self::assertSame(0.0, $section['avgMs']);
    }

    #[Test]
    public function aTickInProgressIsInFlight(): void
    {
        $metrics = new TaskPoolMetrics(tasks: 2);

        $metrics->tickStarted('first');

        self::assertSame(1, $metrics->section()['inFlight']);

        $metrics->tickFinished('first', TickResultEnum::Worked);

        self::assertSame(0, $metrics->section()['inFlight']);
    }

    /** The average is over what was timed, so it is a duration and not a rate. */
    #[Test]
    public function theAverageIsOverTheTicksThatDidSomething(): void
    {
        $metrics = new TaskPoolMetrics(tasks: 1);

        foreach ([TickResultEnum::Worked, TickResultEnum::Failed, TickResultEnum::Idle] as $result) {
            $metrics->tickStarted('first');
            $metrics->tickFinished('first', $result);
        }

        $section = $metrics->section();

        self::assertSame(2, $section['timed']);
        self::assertGreaterThanOrEqual(0.0, $section['avgMs']);
    }
}
