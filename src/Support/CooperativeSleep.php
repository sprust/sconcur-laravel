<?php

declare(strict_types=1);

namespace SConcur\Laravel\Support;

use Fiber;
use Illuminate\Support\Sleep;
use SConcur\Features\Sleeper\Sleeper;

/**
 * The pause of a retry loop the framework would take with Sleep::usleep().
 *
 * Inside a coroutine a native pause freezes the whole process — every other request of the
 * worker — for its length, so the pause goes through Sleeper, which suspends only the
 * caller. Outside a coroutine there is nothing to yield to, and the framework's Sleep
 * stays, so Sleep::fake() still works in tests.
 */
class CooperativeSleep
{
    public static function usleep(int $microseconds): void
    {
        if (Fiber::getCurrent() !== null && extension_loaded('sconcur')) {
            Sleeper::usleep($microseconds);

            return;
        }

        Sleep::usleep($microseconds);
    }
}
