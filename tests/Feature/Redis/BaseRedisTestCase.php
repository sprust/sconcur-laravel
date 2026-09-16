<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use Closure;
use Illuminate\Support\Facades\Redis;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Redis\Connection;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\WaitGroup;

/**
 * Integration tests against the live Redis the compose file raises.
 *
 * Both connections of the workbench point at databases of their own (phpunit.xml), and
 * both are emptied before every test: the cache store's flush empties a whole database,
 * and a key one test left behind must not be what the next one reads.
 */
abstract class BaseRedisTestCase extends BaseTestCase
{
    /** How long a coroutine of a test may run before it is unwound. */
    protected const int COROUTINE_TIMEOUT_MS = 10_000;

    private const int TICK_US = 10_000;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['default', 'cache'] as $name) {
            $this->redis($name)->client()->flushDb(confirm: true);
        }
    }

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

    protected function redis(string $name = 'default'): Connection
    {
        $connection = Redis::connection($name);

        assert($connection instanceof Connection);

        return $connection;
    }
}
