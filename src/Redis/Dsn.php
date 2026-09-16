<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisOptionException;

/**
 * Builds the feature's DSN out of a `config/database.php` Redis connection entry.
 *
 * The entry keeps the shape Laravel gives it — `scheme`, `host`, `port`, `path`,
 * `username`, `password`, `database` — and the feature takes one URL. Checked against
 * the parser the extension carries (ext/src/features/redis/dsn.rs) in sconcur 0.13.0:
 *
 * - `redis://` for TCP, `rediss://` for TLS, `unix://` for a socket, where the path is
 *   the socket and `db`, `user` and `pass` travel as query parameters because they have
 *   nowhere else to go;
 * - redis-rs percent-decodes the credentials of the URL and the query values, so every
 *   one of them is written with rawurlencode() — a password holding `@` or `/` would
 *   otherwise split the URL somewhere else.
 *
 * `url` is not read here: RedisManager has already merged it into the fields through
 * ConfigurationUrlParser, and Connector does the same for the cache store.
 */
class Dsn
{
    public const string SCHEME_TCP  = 'tcp';
    public const string SCHEME_TLS  = 'tls';
    public const string SCHEME_UNIX = 'unix';

    /**
     * @param array<string, mixed> $config
     */
    public static function build(array $config): string
    {
        $scheme = strtolower((string) ($config['scheme'] ?? ''));

        return match ($scheme) {
            '', self::SCHEME_TCP => self::network(config: $config, urlScheme: 'redis'),
            self::SCHEME_TLS     => self::network(config: $config, urlScheme: 'rediss'),
            self::SCHEME_UNIX    => self::socket($config),
            default              => throw new UnsupportedRedisOptionException(
                sprintf(
                    'The Redis connection scheme "%s" is not supported by the sconcur client;'
                    . ' expected "tcp", "tls" or "unix".',
                    $scheme,
                ),
            ),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function network(array $config, string $urlScheme): string
    {
        $host = (string) ($config['host'] ?? '');
        $host = $host === '' ? '127.0.0.1' : $host;

        // An IPv6 literal carries colons of its own, and the URL tells them from the port
        // separator only by the brackets.
        if (str_contains($host, ':') && !str_starts_with($host, '[')) {
            $host = '[' . $host . ']';
        }

        $port = (string) ($config['port'] ?? '');
        $port = $port === '' ? '6379' : $port;

        return $urlScheme . '://' . self::credentials($config) . $host . ':' . $port . '/' . self::database($config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function socket(array $config): string
    {
        $path = (string) ($config['path'] ?? '');

        if ($path === '') {
            throw new UnsupportedRedisOptionException(
                'A Redis connection with the "unix" scheme needs "path": the socket to connect to.',
            );
        }

        $parameters = [
            'db=' . self::database($config),
        ];

        $username = (string) ($config['username'] ?? '');

        if ($username !== '') {
            $parameters[] = 'user=' . rawurlencode($username);
        }

        $password = (string) ($config['password'] ?? '');

        if ($password !== '') {
            $parameters[] = 'pass=' . rawurlencode($password);
        }

        return 'unix://' . $path . '?' . implode('&', $parameters);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function credentials(array $config): string
    {
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');

        if ($username === '' && $password === '') {
            return '';
        }

        if ($password === '') {
            return rawurlencode($username) . '@';
        }

        return rawurlencode($username) . ':' . rawurlencode($password) . '@';
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function database(array $config): int
    {
        return (int) ($config['database'] ?? 0);
    }
}
