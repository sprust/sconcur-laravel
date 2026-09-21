<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature;

use Closure;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

/**
 * Tells a call that suspends its coroutine from one that holds the whole process: the
 * former leaves the process's other coroutines running, the latter freezes them.
 *
 * The class using it declares COROUTINE_TIMEOUT_MS.
 */
trait MeasuresStallsTrait
{
    private const int TICK_US = 10_000;

    /**
     * Runs the units as coroutines beside a ticker that wakes every 10 ms, and answers the
     * longest the ticker was kept from waking. A unit that pauses cooperatively leaves that
     * at about the tick; a native pause freezes the process, the ticker with it, for the
     * whole pause.
     *
     * Every coroutine has a deadline, so a unit that never ends fails the test rather than
     * hanging it.
     *
     * @param list<Closure(): void> $units
     */
    protected function longestStallMs(array $units): float
    {
        $running = count($units);
        $ticks   = [];

        $waitGroup = WaitGroup::create();

        foreach ($units as $unit) {
            $waitGroup->add(
                callback: static function () use ($unit, &$running): void {
                    try {
                        $unit();
                    } finally {
                        --$running;
                    }
                },
                timeoutMs: self::COROUTINE_TIMEOUT_MS,
            );
        }

        $waitGroup->add(
            callback: static function () use (&$running, &$ticks): void {
                while ($running > 0) {
                    $ticks[] = microtime(true);

                    Sleeper::usleep(self::TICK_US);
                }

                // The wake that ends the loop counts too: a unit that stalled the process on
                // its way out would otherwise leave no tick after the stall to measure it by.
                $ticks[] = microtime(true);
            },
            timeoutMs: self::COROUTINE_TIMEOUT_MS,
        );

        $waitGroup->waitAll();

        $longestMs = 0.0;

        for ($index = 1, $count = count($ticks); $index < $count; ++$index) {
            $longestMs = max($longestMs, ($ticks[$index] - $ticks[$index - 1]) * 1000);
        }

        return $longestMs;
    }
}
