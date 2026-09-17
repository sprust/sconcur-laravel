<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Limiters;

use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Redis\Limiters\DurationLimiterBuilder as BaseDurationLimiterBuilder;

/**
 * What `Redis::throttle()` returns on the sconcur connection: the framework's builder,
 * handing out the limiter whose pause does not freeze the worker.
 */
class DurationLimiterBuilder extends BaseDurationLimiterBuilder
{
    public function then(callable $callback, ?callable $failure = null): mixed
    {
        $durationLimiter = new DurationLimiter(
            $this->connection,
            $this->name,
            $this->maxLocks,
            $this->decay,
        );

        try {
            return $durationLimiter->block($this->timeout, $callback, $this->sleep);
        } catch (LimiterTimeoutException $exception) {
            if ($failure !== null) {
                return $failure($exception);
            }

            throw $exception;
        }
    }
}
