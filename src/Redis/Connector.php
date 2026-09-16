<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use Illuminate\Contracts\Redis\Connector as ConnectorContract;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;
use SConcur\Features\Redis\Connection as RedisClient;
use SConcur\Laravel\Redis\Exceptions\RedisClusterNotSupportedException;
use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisOptionException;

/**
 * The `sconcur` client of RedisManager, and the one place a Redis connection entry is
 * turned into the feature's connection — the cache store builds its connections here too,
 * so the facade and the store read the same keys and refuse the same ones.
 *
 * Everything is checked when the connection is built rather than on its first command:
 * a configuration mistake shows at start-up, not in the middle of a request. Nothing is
 * opened — the feature's pool is raised in the extension by the first command.
 */
class Connector implements ConnectorContract
{
    /** The keys of a connection entry the client reads. */
    private const array CONNECTION_KEYS = [
        'scheme',
        'host',
        'port',
        'path',
        'username',
        'password',
        'database',
        'timeout_ms',
        'pool_size',
        'conn_max_lifetime_ms',
    ];

    /**
     * The keys of `redis.options` that may be present. `cluster` only says how the entries
     * under `redis.clusters` are sharded, and asking for one of those is refused on its
     * own; `prefix` and `parameters` may be there as long as they are empty.
     */
    private const array OPTION_KEYS = [
        'cluster',
        'prefix',
        'parameters',
    ];

    /** What to use instead, for the keys an application is likely to carry over. */
    private const array REPLACEMENTS = [
        'timeout'           => 'the connection deadline is "timeout_ms", and it bounds every command',
        'read_timeout'      => 'the connection deadline is "timeout_ms", and it bounds every command',
        'max_retries'       => 'a dropped connection is re-established by the extension, and a command'
            . ' is never retried — the server may have run it and lost only the answer',
        'backoff_algorithm' => 'a dropped connection is re-established by the extension, with no backoff to tune',
        'backoff_base'      => 'a dropped connection is re-established by the extension, with no backoff to tune',
        'backoff_cap'       => 'a dropped connection is re-established by the extension, with no backoff to tune',
        'persistent'        => 'connections always outlive the request — they are pooled in the extension',
        'prefix'            => 'a raw command does not say which of its arguments are keys, so there is'
            . ' nothing to put a prefix on; the cache store has a "prefix" of its own',
        'name'              => 'CLIENT SETNAME would rename a connection other coroutines share',
        'protocol'          => 'RESP3 changes the reply shape of several commands, and only RESP2 is supported',
    ];

    /**
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    public function connect(array $config, array $options): Connection
    {
        return new Connection(
            client: $this->client(
                config: $config,
                options: $options,
            ),
        );
    }

    /**
     * @param array<array-key, mixed> $config
     * @param array<array-key, mixed> $clusterOptions
     * @param array<array-key, mixed> $options
     */
    public function connectToCluster(array $config, array $clusterOptions, array $options): never
    {
        throw new RedisClusterNotSupportedException(
            'The sconcur Redis client does not support a cluster: keys route by slot, the server'
            . ' answers MOVED mid-command and a pipeline cannot span slots — a different connection'
            . ' model rather than a setting. Point the connection at a single server.',
        );
    }

    /**
     * The connection named `$name` of a whole `database.redis` section, read the way
     * RedisManager reads it. This is the path of the cache store, which does not go
     * through RedisManager and so works whichever client the facade is on.
     *
     * @param array<string, mixed> $redis
     */
    public function clientForConnection(array $redis, string $name): RedisClient
    {
        $clusters = (array) ($redis['clusters'] ?? []);

        if (isset($clusters[$name])) {
            $this->connectToCluster(
                config: [],
                clusterOptions: [],
                options: [],
            );
        }

        $config = $redis[$name] ?? null;

        if (!is_array($config)) {
            throw new InvalidArgumentException(sprintf('Redis connection [%s] not configured.', $name));
        }

        return $this->client(
            config: self::parseConfiguration($config),
            options: (array) ($redis['options'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $config  a connection entry with `url` already merged in
     * @param array<string, mixed> $options `redis.options`
     */
    public function client(array $config, array $options = []): RedisClient
    {
        self::assertOnlyKnownKeys(
            values: $options,
            known: self::OPTION_KEYS,
            where: 'redis.options',
        );

        foreach (['prefix', 'parameters'] as $key) {
            if (!self::isOff($options[$key] ?? null)) {
                throw self::unsupported(key: $key, where: 'redis.options');
            }
        }

        self::assertOnlyKnownKeys(
            values: $config,
            known: self::CONNECTION_KEYS,
            where: 'the Redis connection entry',
        );

        return new RedisClient(
            dsn: Dsn::build($config),
            timeoutMs: self::optionalInt($config['timeout_ms'] ?? null),
            poolSize: self::optionalInt($config['pool_size'] ?? null),
            connMaxLifetimeMs: self::optionalInt($config['conn_max_lifetime_ms'] ?? null),
        );
    }

    /**
     * RedisManager::parseConnectionConfiguration(), which is protected: the URL merged into
     * the fields, a `tcp`/`tls` URL scheme kept as `scheme`, and `driver` dropped.
     *
     * @param array<array-key, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function parseConfiguration(array $config): array
    {
        /** @var array<string, mixed> $parsed */
        $parsed = (new ConfigurationUrlParser())->parseConfiguration($config);

        $driver = strtolower((string) ($parsed['driver'] ?? ''));

        if (in_array($driver, [Dsn::SCHEME_TCP, Dsn::SCHEME_TLS], true)) {
            $parsed['scheme'] = $driver;
        }

        unset($parsed['driver']);

        return $parsed;
    }

    /**
     * A key the client does not read is refused only when it is set to something: Laravel's
     * own skeleton carries `persistent => false` and `username => null`, and a switched-off
     * setting asks for nothing the client fails to do.
     *
     * @param array<array-key, mixed> $values
     * @param list<string>            $known
     */
    private static function assertOnlyKnownKeys(array $values, array $known, string $where): void
    {
        foreach ($values as $key => $value) {
            if (in_array($key, $known, true) || self::isOff($value)) {
                continue;
            }

            throw self::unsupported(key: (string) $key, where: $where);
        }
    }

    private static function unsupported(string $key, string $where): UnsupportedRedisOptionException
    {
        $message = sprintf('"%s" in %s is not read by the sconcur Redis client', $key, $where);

        $replacement = self::REPLACEMENTS[$key] ?? null;

        return new UnsupportedRedisOptionException(
            $replacement === null
                ? $message . '. Remove it.'
                : $message . ': ' . $replacement . '. Remove it.',
        );
    }

    private static function isOff(mixed $value): bool
    {
        return in_array($value, [null, false, '', 0, '0', []], true);
    }

    private static function optionalInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }
}
