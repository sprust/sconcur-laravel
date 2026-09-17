<?php

declare(strict_types=1);

namespace SConcur\Laravel\Cache\Redis;

use SConcur\Laravel\Redis\Connector;

/**
 * Builds the `sconcur_redis` store out of its `config/cache.php` entry.
 *
 * The entry names connections of `database.redis`, the way the framework's `redis` store
 * does, and they are built by the facade's Connector without going through RedisManager —
 * so the store is on the feature whatever `database.redis.client` says.
 *
 * The connections' key prefix goes in front of the store's own, as it does for RedisStore on
 * phpredis, so the two stores keep a key under one name.
 */
readonly class StoreFactory
{
    public function __construct(
        protected Connector $connector,
    ) {
    }

    /**
     * @param array<string, mixed>               $config              the store entry
     * @param array<string, mixed>               $redis               the whole `database.redis` section
     * @param array<int, class-string>|bool|null $serializableClasses
     */
    public function make(
        array $config,
        array $redis,
        string $prefix,
        array|bool|null $serializableClasses,
    ): Store {
        $connection     = (string) ($config['connection'] ?? 'default');
        $lockConnection = (string) ($config['lock_connection'] ?? $connection);

        $client = $this->connector->clientForConnection(
            redis: $redis,
            name: $connection,
        );

        $lockClient = $lockConnection === $connection
            ? $client
            : $this->connector->clientForConnection(
                redis: $redis,
                name: $lockConnection,
            );

        return new Store(
            client: $client,
            lockClient: $lockClient,
            prefix: $prefix,
            serializableClasses: $serializableClasses,
            connectionPrefix: $this->connector->prefixForConnection(
                redis: $redis,
                name: $connection,
            ),
            lockConnectionPrefix: $this->connector->prefixForConnection(
                redis: $redis,
                name: $lockConnection,
            ),
        );
    }
}
