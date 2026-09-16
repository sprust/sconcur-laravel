<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SConcur\Laravel\Redis\Connector;
use SConcur\Laravel\Redis\Exceptions\RedisClusterNotSupportedException;
use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisOptionException;

/**
 * What the client refuses at build time. Nothing here opens a socket: the feature's
 * connection is a DSN and pool sizes until its first command.
 */
class ConnectorTest extends TestCase
{
    #[Test]
    public function aPlainEntryBuildsAClient(): void
    {
        $client = (new Connector())->client(
            config: [
                'host'                 => 'scl-redis',
                'port'                 => 6379,
                'password'             => 'secret',
                'database'             => 1,
                'timeout_ms'           => 2000,
                'pool_size'            => 8,
                'conn_max_lifetime_ms' => 60000,
            ],
        );

        self::assertSame('redis://:secret@scl-redis:6379/1', $client->dsn);
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function unreadConnectionKeys(): iterable
    {
        yield 'retries' => ['max_retries', 3];
        yield 'backoff' => ['backoff_algorithm', 'decorrelated_jitter'];
        yield 'timeout' => ['timeout', 1.5];
        yield 'read timeout' => ['read_timeout', 2];
        yield 'persistent' => ['persistent', true];
        yield 'client name' => ['name', 'app'];
        yield 'an unknown key' => ['whatever', 'x'];
    }

    #[Test]
    #[DataProvider('unreadConnectionKeys')]
    public function aKeyTheClientDoesNotReadIsRefused(string $key, mixed $value): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);
        $this->expectExceptionMessage(sprintf('"%s"', $key));

        (new Connector())->client(
            config: [
                'host' => 'scl-redis',
                $key   => $value,
            ],
        );
    }

    /**
     * Laravel's skeleton carries these switched off; a setting that asks for nothing is
     * not a setting the client fails to honour.
     */
    #[Test]
    public function aSwitchedOffKeyIsAccepted(): void
    {
        $client = (new Connector())->client(
            config: [
                'host'        => 'scl-redis',
                'username'    => null,
                'persistent'  => false,
                'max_retries' => 0,
            ],
            options: [
                'cluster'    => 'redis',
                'prefix'     => '',
                'parameters' => [],
                'persistent' => false,
            ],
        );

        self::assertSame('redis://scl-redis:6379/0', $client->dsn);
    }

    #[Test]
    public function aPrefixIsRefused(): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);
        $this->expectExceptionMessage('"prefix" in redis.options');

        (new Connector())->client(
            config: [
                'host' => 'scl-redis',
            ],
            options: [
                'prefix' => 'laravel_database_',
            ],
        );
    }

    #[Test]
    #[DataProvider('unreadOptions')]
    public function anOptionTheClientDoesNotReadIsRefused(string $key, mixed $value): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);
        $this->expectExceptionMessage(sprintf('"%s" in redis.options', $key));

        (new Connector())->client(
            config: [
                'host' => 'scl-redis',
            ],
            options: [
                $key => $value,
            ],
        );
    }

    /**
     * @return iterable<string, array{string, mixed}>
     */
    public static function unreadOptions(): iterable
    {
        yield 'serializer' => ['serializer', 1];
        yield 'compression' => ['compression', 2];
        yield 'parameters' => ['parameters', ['password' => 'x']];
    }

    #[Test]
    public function aClusterIsRefused(): void
    {
        $this->expectException(RedisClusterNotSupportedException::class);

        (new Connector())->connectToCluster(
            config: [],
            clusterOptions: [],
            options: [],
        );
    }

    #[Test]
    public function aClusterNamedByTheCacheStoreIsRefused(): void
    {
        $this->expectException(RedisClusterNotSupportedException::class);

        (new Connector())->clientForConnection(
            redis: [
                'clusters' => [
                    'cache' => [
                        ['host' => 'one'],
                        ['host' => 'two'],
                    ],
                ],
            ],
            name: 'cache',
        );
    }

    #[Test]
    public function theUrlIsMergedIntoTheFieldsTheWayRedisManagerDoesIt(): void
    {
        $client = (new Connector())->clientForConnection(
            redis: [
                'cache' => [
                    'url'      => 'tls://user:p%40ss@example.com:6380/4',
                    'database' => 0,
                ],
            ],
            name: 'cache',
        );

        self::assertSame('rediss://user:p%40ss@example.com:6380/4', $client->dsn);
    }

    #[Test]
    public function aConnectionThatIsNotConfiguredSaysSo(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Redis connection [missing] not configured.');

        (new Connector())->clientForConnection(
            redis: [],
            name: 'missing',
        );
    }
}
