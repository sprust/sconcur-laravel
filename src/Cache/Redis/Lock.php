<?php

declare(strict_types=1);

namespace SConcur\Laravel\Cache\Redis;

use Illuminate\Cache\Lock as BaseLock;
use Illuminate\Cache\LuaScripts;
use Illuminate\Contracts\Cache\LockTimeoutException;
use SConcur\Features\Redis\Connection as RedisClient;
use SConcur\Laravel\Support\CooperativeSleep;

/**
 * The lock of the `sconcur_redis` store: `SET NX` to take it, and the framework's own Lua
 * scripts to release and refresh it, so only the owner can do either.
 *
 * The owner lives in the object, the way it does in every framework lock. A lock object is
 * made per call rather than shared, so two coroutines never hold the same one; across
 * processes the owner string is what `restoreLock()` hands on.
 */
class Lock extends BaseLock
{
    public function __construct(
        protected RedisClient $client,
        string $name,
        int $seconds,
        ?string $owner = null,
    ) {
        parent::__construct($name, $seconds, $owner);
    }

    public function acquire(): bool
    {
        if ($this->seconds > 0) {
            return $this->client->set(
                key: $this->name,
                value: $this->owner,
                ttlSeconds: $this->seconds,
                ifNotExists: true,
            );
        }

        return $this->client->setNx(
            key: $this->name,
            value: $this->owner,
        );
    }

    public function release(): bool
    {
        return (bool) $this->client->eval(
            script: LuaScripts::releaseLock(),
            keys: [$this->name],
            arguments: [$this->owner],
        );
    }

    public function forceRelease(): void
    {
        $this->client->del($this->name);
    }

    /**
     * @param int|null $seconds
     */
    public function refresh($seconds = null): bool
    {
        $seconds ??= $this->seconds;

        return (bool) $this->client->eval(
            script: LuaScripts::refreshLock(),
            keys: [$this->name],
            arguments: [
                $this->owner,
                (int) $seconds,
            ],
        );
    }

    /**
     * The framework's block() with one change: the pause between attempts.
     *
     * The framework waits with usleep(), which inside a coroutine freezes the whole process
     * — every other request of the worker included — for the length of the pause, and does
     * it again on every attempt. Here the pause is CooperativeSleep's.
     *
     * @param int           $seconds
     * @param callable|null $callback
     */
    public function block($seconds, $callback = null): mixed
    {
        $startedAtMs = ((int) now()->format('Uu')) / 1000;

        $waitMs = $seconds * 1000;

        while (!$this->acquire()) {
            $nowMs = ((int) now()->format('Uu')) / 1000;

            if (($nowMs + $this->sleepMilliseconds - $waitMs) >= $startedAtMs) {
                throw new LockTimeoutException();
            }

            CooperativeSleep::usleep($this->sleepMilliseconds * 1000);
        }

        if (is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }

        return true;
    }

    protected function getCurrentOwner(): ?string
    {
        return $this->client->get($this->name);
    }
}
