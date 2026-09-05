<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

/**
 * The `sconcur.ws.presence` settings: where the member list of a presence channel lives.
 *
 * `store` is `auto` by default, which means the process decides from the pool size: one
 * worker owns the whole list and needs nothing shared, several workers do. Naming a store
 * explicitly overrides that — and `memory` under a multi-worker pool is a mistake the
 * start command reports rather than a configuration it silently honours.
 */
readonly class WsPresenceOptions
{
    public const string STORE_AUTO = 'auto';

    public const string STORE_MEMORY = 'memory';

    public const string STORE_CACHE = 'cache';

    public function __construct(
        public string $store = self::STORE_AUTO,
        public int $ttlSeconds = 3600,
        public string $cachePrefix = 'sconcur:ws:presence',
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            store: (string) ($config['store'] ?? self::STORE_AUTO),
            ttlSeconds: max(60, (int) ($config['ttl_seconds'] ?? 3600)),
            cachePrefix: (string) ($config['cache_prefix'] ?? 'sconcur:ws:presence'),
        );
    }

    /** The store to actually use, once the pool size is known. */
    public function resolveStore(int $workerCount): string
    {
        if ($this->store !== self::STORE_AUTO) {
            return $this->store;
        }

        return $workerCount > 1 ? self::STORE_CACHE : self::STORE_MEMORY;
    }
}
