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
    /** The value of `database.redis.client` that puts RedisManager on this connector. */
    public const string CLIENT = 'sconcur';

    /** The keys of a connection entry the client reads. */
    private const array CONNECTION_KEYS = [
        'prefix',
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
     * own; `parameters` may be there as long as it is switched off.
     */
    private const array OPTION_KEYS = [
        'cluster',
        'prefix',
        'parameters',
    ];

    /** The options that are refused unless switched off, even though they are expected keys. */
    private const array OPTIONS_OFF_ONLY = [
        'parameters',
    ];

    /** What a switched-off setting looks like. */
    private const array OFF_VALUES = [
        null,
        false,
        '',
        0,
        '0',
        [],
    ];

    /** The schemes a `tcp`/`tls` URL is kept under, the way RedisManager keeps them. */
    private const array URL_SCHEMES = [
        Dsn::SCHEME_TCP,
        Dsn::SCHEME_TLS,
    ];

    /** The keys a `unix` entry has no use for: the socket is `path`. */
    private const array SOCKET_UNREAD_KEYS = [
        'host',
        'port',
    ];

    /** The feature's ceiling on multiplexed connections per server. */
    private const int MAX_POOL_SIZE = 64;

    private const int MAX_PORT = 65535;

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
            prefix: self::prefix(
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
     * RedisManager reads it: the entry first, the clusters after. This is the path of the
     * cache store, which does not go through RedisManager and so works whichever client the
     * facade is on.
     *
     * `redis.options` belong to the facade's client. They are checked only when that client
     * is this one; under phpredis or predis they are that client's business. The one option
     * the store reads whatever the client is, `prefix`, is read by prefixForConnection().
     *
     * @param array<string, mixed> $redis
     */
    public function clientForConnection(array $redis, string $name): RedisClient
    {
        $config = $redis[$name] ?? null;

        if (!is_array($config)) {
            $clusters = (array) ($redis['clusters'] ?? []);

            if (isset($clusters[$name])) {
                $this->connectToCluster(
                    config: [],
                    clusterOptions: [],
                    options: [],
                );
            }

            throw new InvalidArgumentException(sprintf('Redis connection [%s] not configured.', $name));
        }

        $options = (($redis['client'] ?? null) === self::CLIENT)
            ? (array) ($redis['options'] ?? [])
            : [];

        return $this->client(
            config: self::parseConfiguration($config),
            options: $options,
        );
    }

    /**
     * The key prefix of the connection named `$name`, the way PhpRedisConnector reads it: the
     * entry's own `prefix` wins over `redis.options.prefix`.
     *
     * Read whichever client the facade is on — this is the path of the cache store, and the
     * framework's RedisStore on phpredis puts that same prefix in front of its keys, so the two
     * stores find each other's keys only if this one does too.
     *
     * @param array<string, mixed> $redis
     */
    public function prefixForConnection(array $redis, string $name): string
    {
        return self::prefix(
            config: (array) ($redis[$name] ?? []),
            options: (array) ($redis['options'] ?? []),
        );
    }

    /**
     * @param array<string, mixed> $config  a connection entry with `url` already merged in
     * @param array<string, mixed> $options `redis.options`
     */
    public function client(array $config, array $options = []): RedisClient
    {
        self::assertOptions($options);
        self::assertConnection($config);

        return new RedisClient(
            dsn: Dsn::build($config),
            timeoutMs: self::integerOrNull(
                config: $config,
                key: 'timeout_ms',
            ),
            poolSize: self::integerOrNull(
                config: $config,
                key: 'pool_size',
            ),
            connMaxLifetimeMs: self::integerOrNull(
                config: $config,
                key: 'conn_max_lifetime_ms',
            ),
        );
    }

    /**
     * @param array<array-key, mixed> $config
     * @param array<array-key, mixed> $options
     */
    private static function prefix(array $config, array $options): string
    {
        $prefix = self::isOff($config['prefix'] ?? null)
            ? ($options['prefix'] ?? null)
            : $config['prefix'];

        return self::isOff($prefix) ? '' : (string) $prefix;
    }

    /**
     * @param array<array-key, mixed> $options
     */
    private static function assertOptions(array $options): void
    {
        self::assertOnlyKnownKeys(
            values: $options,
            known: self::OPTION_KEYS,
            where: 'redis.options',
        );

        foreach (self::OPTIONS_OFF_ONLY as $key) {
            if (!self::isOff($options[$key] ?? null)) {
                throw self::unsupported(
                    key: $key,
                    where: 'redis.options',
                );
            }
        }

        self::assertPrefix(
            value: $options['prefix'] ?? null,
            where: 'redis.options',
        );
    }

    /**
     * The keys first, then the values the feature would take without complaint and use
     * differently from what they say: a database number that is not a number becomes 0, a
     * pool size past the ceiling is cut to it, a `tls://` host becomes a hostname with a
     * colon in it, a username without a password logs in as nobody.
     *
     * @param array<array-key, mixed> $config
     */
    private static function assertConnection(array $config): void
    {
        self::assertOnlyKnownKeys(
            values: $config,
            known: self::CONNECTION_KEYS,
            where: 'the Redis connection entry',
        );

        self::assertPrefix(
            value: $config['prefix'] ?? null,
            where: 'the Redis connection entry',
        );

        self::assertInteger(
            config: $config,
            key: 'database',
            min: 0,
            max: PHP_INT_MAX,
        );
        self::assertInteger(
            config: $config,
            key: 'port',
            min: 1,
            max: self::MAX_PORT,
        );
        self::assertInteger(
            config: $config,
            key: 'timeout_ms',
            min: 0,
            max: PHP_INT_MAX,
        );
        self::assertInteger(
            config: $config,
            key: 'conn_max_lifetime_ms',
            min: 0,
            max: PHP_INT_MAX,
        );
        self::assertInteger(
            config: $config,
            key: 'pool_size',
            min: 1,
            max: self::MAX_POOL_SIZE,
        );

        $scheme = strtolower((string) ($config['scheme'] ?? ''));

        $host = (string) ($config['host'] ?? '');

        if ($scheme === Dsn::SCHEME_UNIX) {
            foreach (self::SOCKET_UNREAD_KEYS as $key) {
                if (!self::isOff($config[$key] ?? null)) {
                    throw new UnsupportedRedisOptionException(sprintf(
                        '"%s" is not read with the "unix" scheme: the socket is "path". Remove it.',
                        $key,
                    ));
                }
            }
        } else {
            if (!self::isOff($config['path'] ?? null)) {
                throw new UnsupportedRedisOptionException(
                    '"path" is read only with the "unix" scheme. Set "scheme" to "unix", or remove "path".',
                );
            }

            if (str_contains($host, '://')) {
                throw new UnsupportedRedisOptionException(sprintf(
                    'The Redis host "%s" carries a scheme. Put the bare host in "host" and the scheme'
                    . ' in "scheme" ("tls" for TLS).',
                    $host,
                ));
            }

            if (str_starts_with($host, '/')) {
                throw new UnsupportedRedisOptionException(sprintf(
                    'The Redis host "%s" is a socket path. Set "scheme" to "unix" and put the path in "path".',
                    $host,
                ));
            }
        }

        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if (($username !== '') && ($password === '')) {
            throw new UnsupportedRedisOptionException(
                'A Redis "username" without a "password" is not sent at all: the driver logs in only'
                . ' when there is a password, so the connection would run as the default user.'
                . ' Set the password, or remove the username.',
            );
        }
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

        if (in_array($driver, self::URL_SCHEMES, true)) {
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

            throw self::unsupported(
                key: (string) $key,
                where: $where,
            );
        }
    }

    /**
     * A value that is set has to be a whole number in the range: `(int) 'abc'` is 0, and 0
     * means something of its own for most of these keys.
     *
     * @param array<array-key, mixed> $config
     */
    private static function assertInteger(array $config, string $key, int $min, int $max): void
    {
        $value = $config[$key] ?? null;

        if (($value === null) || ($value === '')) {
            return;
        }

        $integer = self::toInteger($value);

        if (($integer !== null) && ($integer >= $min) && ($integer <= $max)) {
            return;
        }

        throw new UnsupportedRedisOptionException(sprintf(
            '"%s" of the Redis connection entry must be a whole number from %d to %d, got %s.',
            $key,
            $min,
            $max,
            var_export($value, true),
        ));
    }

    /**
     * A prefix is put in front of a key as it is, so it has to be a string.
     */
    private static function assertPrefix(mixed $value, string $where): void
    {
        if (self::isOff($value) || is_string($value)) {
            return;
        }

        throw new UnsupportedRedisOptionException(sprintf(
            '"prefix" in %s must be a string, got %s.',
            $where,
            get_debug_type($value),
        ));
    }

    /**
     * @param array<array-key, mixed> $config
     */
    private static function integerOrNull(array $config, string $key): ?int
    {
        $value = $config[$key] ?? null;

        if (($value === null) || ($value === '')) {
            return null;
        }

        return self::toInteger($value);
    }

    private static function toInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function unsupported(string $key, string $where): UnsupportedRedisOptionException
    {
        $message = sprintf('"%s" in %s is not read by the sconcur Redis client', $key, $where);

        $replacement = self::REPLACEMENTS[$key] ?? null;

        return new UnsupportedRedisOptionException(
            ($replacement === null)
                ? $message . '. Remove it.'
                : $message . ': ' . $replacement . '. Remove it.',
        );
    }

    private static function isOff(mixed $value): bool
    {
        return in_array($value, self::OFF_VALUES, true);
    }
}
