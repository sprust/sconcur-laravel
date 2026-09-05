<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Presence;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
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
    private const int LOCK_TIMEOUT_SECONDS = 3;

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
            $this->write($channel, $change($this->members($channel)));

            return;
        }

        try {
            $lock->block(self::LOCK_TIMEOUT_SECONDS);

            $this->write($channel, $change($this->members($channel)));
        } catch (Throwable) {
            // The lock could not be taken in time. Writing anyway would be the race this
            // exists to prevent; the member is announced over the bus either way, so what
            // is lost is one entry of the snapshot, not the event.
        } finally {
            $lock->release();
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

        return $store->lock($this->key($channel) . ':lock', self::LOCK_TIMEOUT_SECONDS);
    }

    private function key(string $channel): string
    {
        return $this->prefix . ':' . $channel;
    }
}
