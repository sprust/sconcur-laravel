<?php

declare(strict_types=1);

namespace SConcur\Laravel\Cache\Redis;

use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\LockProvider;
use SConcur\Features\Redis\Connection as RedisClient;
use SConcur\Features\Redis\Pipeline;

/**
 * The `sconcur_redis` cache store, on the feature's typed API.
 *
 * The framework's RedisStore cannot be put on the feature: it opens a transaction with
 * multi() and closes it with exec() as two separate calls, which on a connection shared by
 * every coroutine would put a neighbour's commands inside it — the feature refuses both.
 * Everything else mirrors RedisStore, down to the storage format, so the two read each
 * other's keys:
 *
 * - a finite number is stored as it is, so INCRBY works on it, and comes back as the
 *   numeric string Redis holds — the same as RedisStore;
 * - anything else goes through serialize(), and is read back with
 *   `cache.serializable_classes` as the allowed classes.
 *
 * Tags come from TaggableStore, which keeps them in the store itself; RedisTaggedCache is
 * written against the phpredis and predis connections.
 */
class Store extends TaggableStore implements LockProvider
{
    /**
     * @param array<int, class-string>|bool|null $serializableClasses
     */
    public function __construct(
        protected RedisClient $client,
        protected RedisClient $lockClient,
        protected string $prefix = '',
        protected array|bool|null $serializableClasses = null,
    ) {
    }

    /**
     * @param string $key
     */
    public function get($key): mixed
    {
        $value = $this->client->get($this->prefix . $key);

        return $value === null ? null : $this->unserialize($value);
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @return array<string, mixed>
     */
    public function many(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $values = $this->client->mGet(
            array_map(fn(string $key): string => $this->prefix . $key, array_values($keys)),
        );

        $results = [];

        foreach ($keys as $key) {
            // mGet keys its answer by what was asked for, and PHP turns an integer-looking
            // key into an int both when that map was built and when it is read here, so
            // the lookup lands on the same entry either way.
            $value = $values[$this->prefix . $key] ?? null;

            $results[$key] = $value === null ? null : $this->unserialize($value);
        }

        return $results;
    }

    /**
     * @param string $key
     * @param int    $seconds
     */
    public function put($key, $value, $seconds): bool
    {
        return $this->client->set(
            key: $this->prefix . $key,
            value: (string) $this->serialize($value),
            ttlSeconds: max(1, (int) $seconds),
        );
    }

    /**
     * One MULTI/EXEC, so the values land together and in one round trip.
     *
     * @param array<string, mixed> $values
     * @param int                  $seconds
     */
    public function putMany(array $values, $seconds): bool
    {
        if ($values === []) {
            return true;
        }

        $ttlSeconds = max(1, (int) $seconds);

        $replies = $this->client->transaction(function (Pipeline $transaction) use ($values, $ttlSeconds): void {
            foreach ($values as $key => $value) {
                $transaction->command(
                    name: 'SET',
                    arguments: [
                        $this->prefix . $key,
                        $this->serialize($value),
                        'EX',
                        $ttlSeconds,
                    ],
                );
            }
        });

        foreach ($replies as $reply) {
            if ($reply !== 'OK') {
                return false;
            }
        }

        return true;
    }

    /**
     * `SET NX EX` is one atomic command, so the framework's Lua script is not needed.
     *
     * @param string $key
     * @param int    $seconds
     */
    public function add($key, mixed $value, $seconds): bool
    {
        return $this->client->set(
            key: $this->prefix . $key,
            value: (string) $this->serialize($value),
            ttlSeconds: max(1, (int) $seconds),
            ifNotExists: true,
        );
    }

    /**
     * @param string $key
     * @param int    $value
     */
    public function increment($key, $value = 1): int
    {
        return $this->client->incrBy(
            key: $this->prefix . $key,
            by: (int) $value,
        );
    }

    /**
     * @param string $key
     * @param int    $value
     */
    public function decrement($key, $value = 1): int
    {
        return $this->client->decrBy(
            key: $this->prefix . $key,
            by: (int) $value,
        );
    }

    /**
     * @param string $key
     */
    public function forever($key, $value): bool
    {
        return $this->client->set(
            key: $this->prefix . $key,
            value: (string) $this->serialize($value),
        );
    }

    /**
     * @param string $key
     */
    public function forget($key): bool
    {
        return $this->client->del($this->prefix . $key) > 0;
    }

    /**
     * FLUSHDB, as RedisStore does: the whole database the connection points at, not only
     * the keys under this store's prefix. Give the cache a database of its own.
     */
    public function flush(): bool
    {
        return $this->client->flushDb(confirm: true);
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * @param string      $name
     * @param int         $seconds
     * @param string|null $owner
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new Lock(
            client: $this->lockClient,
            name: $this->prefix . $name,
            seconds: (int) $seconds,
            owner: $owner,
        );
    }

    /**
     * @param string $name
     * @param string $owner
     */
    public function restoreLock($name, $owner): Lock
    {
        return $this->lock(
            name: $name,
            seconds: 0,
            owner: $owner,
        );
    }

    public function client(): RedisClient
    {
        return $this->client;
    }

    protected function serialize(mixed $value): string|int|float
    {
        if (is_numeric($value) && is_finite((float) $value)) {
            return $value;
        }

        return serialize($value);
    }

    protected function unserialize(string $value): mixed
    {
        if (is_numeric($value)) {
            return $value;
        }

        if ($this->serializableClasses !== null) {
            return unserialize($value, ['allowed_classes' => $this->serializableClasses]);
        }

        return unserialize($value);
    }
}
