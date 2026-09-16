<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Limiters;

use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Redis\Limiters\DurationLimiter as BaseDurationLimiter;
use SConcur\Laravel\Support\CooperativeSleep;

/**
 * The framework's duration limiter with one change: the pause between attempts goes
 * through CooperativeSleep, so a coroutine waiting for its turn does not freeze the worker.
 */
class DurationLimiter extends BaseDurationLimiter
{
    /**
     * @param int           $timeout
     * @param callable|null $callback
     * @param int           $sleep
     */
    public function block($timeout, $callback = null, $sleep = 750): mixed
    {
        $startedAtSeconds = time();

        while (!$this->acquire()) {
            if ((time() - $timeout) >= $startedAtSeconds) {
                throw new LimiterTimeoutException();
            }

            CooperativeSleep::usleep($sleep * 1000);
        }

        if (is_callable($callback)) {
            return $callback();
        }

        return true;
    }
}
