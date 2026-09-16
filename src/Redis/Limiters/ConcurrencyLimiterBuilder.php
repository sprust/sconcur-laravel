<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Limiters;

use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Redis\Limiters\ConcurrencyLimiterBuilder as BaseConcurrencyLimiterBuilder;

/**
 * What `Redis::funnel()` returns on the sconcur connection: the framework's builder, handing
 * out the limiter whose pause does not freeze the worker.
 */
class ConcurrencyLimiterBuilder extends BaseConcurrencyLimiterBuilder
{
    public function then(callable $callback, ?callable $failure = null): mixed
    {
        $concurrencyLimiter = new ConcurrencyLimiter(
            $this->connection,
            $this->name,
            $this->maxLocks,
            $this->releaseAfter,
        );

        try {
            return $concurrencyLimiter->block($this->timeout, $callback, $this->sleep);
        } catch (LimiterTimeoutException $exception) {
            if ($failure !== null) {
                return $failure($exception);
            }

            throw $exception;
        }
    }
}
