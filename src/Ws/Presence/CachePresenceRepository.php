<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Presence;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use SConcur\Features\Sleeper\Sleeper;
use Throwable;

/**
 * The member list in the shared cache, so every worker of the pool sees the same one.
 *
 * One key per channel holding a socket id => member map, rewritten under a lock. A map
 * rather than a key per member because the read is the hot path — a subscription needs
 * the whole channel at once, and a store with no way to enumerate keys cannot give it.
 *
 * The TTL is the cleanup: a worker killed outright leaves its members behind, and the
 * entry has to expire rather than wait for someone to notice. It is refreshed on every
 * change, so a busy channel never expires under its own members.
 */
readonly class CachePresenceRepository implements PresenceRepositoryInterface
{
    /** How long the store holds the lock if the holder dies before releasing it. */
    private const int LOCK_TTL_SECONDS = 3;

    /** How many times an occupied lock is retried before the change is given up. */
    private const int LOCK_ATTEMPTS = 12;

    /** The pause between those attempts. */
    private const int LOCK_RETRY_MS = 25;

    public function __construct(
        private CacheRepository $cache,
        private string $prefix,
        private int $ttlSeconds,
    ) {
    }

    public function join(string $channel, string $socketId, array $member): void
    {
        $this->mutate($channel, static function (array $members) use ($socketId, $member): array {
            $members[$socketId] = $member;

            return $members;
        });
    }

    public function leave(string $channel, string $socketId): void
    {
        $this->mutate($channel, static function (array $members) use ($socketId): array {
            unset($members[$socketId]);

            return $members;
        });
    }

    public function members(string $channel): array
    {
        $members = $this->cache->get($this->key($channel));

        if (!is_array($members)) {
            return [];
        }

        /** @var array<string, array<string, mixed>> $members */
        return $members;
    }

    /**
     * Read, change, write — under a lock where the store has one. A store without
     * LockProvider (the array and file drivers) does the same without it: two workers
     * cannot share such a store anyway, so there is nothing to race with.
     *
     * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change
     */
    private function mutate(string $channel, callable $change): void
    {
        $lock = $this->lock($channel);

        if ($lock === null) {
            $this->apply($channel, $change);

            return;
        }

        if (!$this->acquire($lock)) {
            // Occupied by another worker for the whole retry window. Writing anyway would
            // be the race this exists to prevent; the member is announced over the bus
            // either way, so what is lost is one entry of the snapshot, not the event.
            return;
        }

        try {
            $this->apply($channel, $change);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Releasing talks to the store too, and it can be down. Letting that
                // escape would take the caller's teardown with it — see ConnectionHandler.
            }
        }
    }

    /**
     * Takes the lock without stopping the worker.
     *
     * Lock::block() is not usable here: it waits in a native usleep loop, and one native
     * sleep freezes the single PHP thread — every other connection of this worker stops
     * being read, written or pinged for as long as it lasts. Sleeper yields the coroutine
     * instead, so the wait costs this subscription and nothing else.
     */
    private function acquire(Lock $lock): bool
    {
        for ($attempt = 0; $attempt < self::LOCK_ATTEMPTS; $attempt++) {
            try {
                if ($lock->get()) {
                    return true;
                }
            } catch (Throwable) {
                // The store is unreachable; there is nothing to wait for.
                return false;
            }

            Sleeper::usleep(self::LOCK_RETRY_MS * 1000);
        }

        return false;
    }

    /**
     * @param callable(array<string, array<string, mixed>>): array<string, array<string, mixed>> $change
     */
    private function apply(string $channel, callable $change): void
    {
        try {
            $this->write($channel, $change($this->members($channel)));
        } catch (Throwable) {
            // Reading and writing both talk to the store. A member missing from the
            // snapshot is bad; an exception out of here is worse — it unwinds the
            // connection teardown that called it.
        }
    }

    /**
     * @param array<string, array<string, mixed>> $members
     */
    private function write(string $channel, array $members): void
    {
        if ($members === []) {
            $this->cache->forget($this->key($channel));

            return;
        }

        $this->cache->put($this->key($channel), $members, $this->ttlSeconds);
    }

    private function lock(string $channel): ?Lock
    {
        $store = $this->cache->getStore();

        if (!$store instanceof LockProvider) {
            return null;
        }

        return $store->lock($this->key($channel) . ':lock', self::LOCK_TTL_SECONDS);
    }

    private function key(string $channel): string
    {
        return $this->prefix . ':' . $channel;
    }
}
