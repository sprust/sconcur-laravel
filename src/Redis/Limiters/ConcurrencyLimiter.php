<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis\Limiters;

use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Redis\Limiters\ConcurrencyLimiter as BaseConcurrencyLimiter;
use Illuminate\Support\Str;
use SConcur\Laravel\Support\CooperativeSleep;
use Throwable;

/**
 * The framework's concurrency limiter with one change: the pause between attempts goes
 * through CooperativeSleep, so a coroutine waiting for a slot does not freeze the worker.
 */
class ConcurrencyLimiter extends BaseConcurrencyLimiter
{
    /**
     * @param int           $timeout
     * @param callable|null $callback
     * @param int           $sleep
     */
    public function block($timeout, $callback = null, $sleep = 250): mixed
    {
        $startedAtSeconds = time();

        $id = Str::random(20);

        while (!$slot = $this->acquire($id)) {
            if ((time() - $timeout) >= $startedAtSeconds) {
                throw new LimiterTimeoutException();
            }

            CooperativeSleep::usleep($sleep * 1000);
        }

        if (!is_callable($callback)) {
            return true;
        }

        try {
            $result = $callback();
        } catch (Throwable $exception) {
            $this->release($slot, $id);

            throw $exception;
        }

        $this->release($slot, $id);

        return $result;
    }
}
