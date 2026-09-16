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

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function valuesTheFeatureWouldMisread(): iterable
    {
        yield 'a database that is not a number' => [['database' => 'abc'], '"database"'];
        yield 'a negative database' => [['database' => -1], '"database"'];
        yield 'a port out of range' => [['port' => 70000], '"port"'];
        yield 'a deadline that is not a number' => [['timeout_ms' => 'soon'], '"timeout_ms"'];
        yield 'a pool past the ceiling' => [['pool_size' => 65], '"pool_size"'];
        yield 'an empty pool' => [['pool_size' => 0], '"pool_size"'];
        yield 'a host with a scheme' => [['host' => 'tls://example.com'], '"scheme"'];
        yield 'a socket in the host' => [['host' => '/run/redis.sock'], '"unix"'];
        yield 'a path over tcp' => [['path' => '/run/redis.sock'], '"path"'];
        yield 'a host with a socket' => [['scheme' => 'unix', 'path' => '/run/redis.sock', 'host' => 'scl-redis'], '"host"'];
        yield 'a username without a password' => [['username' => 'app'], '"username"'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('valuesTheFeatureWouldMisread')]
    public function aValueTheFeatureWouldMisreadIsRefused(array $config, string $message): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);
        $this->expectExceptionMessage($message);

        (new Connector())->client(config: $config);
    }

    #[Test]
    public function numbersAsStringsFromTheEnvironmentAreAccepted(): void
    {
        $client = (new Connector())->client(
            config: [
                'host'       => 'scl-redis',
                'port'       => '6380',
                'database'   => '2',
                'timeout_ms' => '1500',
                'pool_size'  => '64',
            ],
        );

        self::assertSame('redis://scl-redis:6380/2', $client->dsn);
    }

    /** The entry wins over a cluster of the same name, the way RedisManager resolves it. */
    #[Test]
    public function anEntryWinsOverAClusterOfTheSameName(): void
    {
        $client = (new Connector())->clientForConnection(
            redis: [
                'cache'    => [
                    'host' => 'scl-redis',
                ],
                'clusters' => [
                    'cache' => [
                        ['host' => 'one'],
                    ],
                ],
            ],
            name: 'cache',
        );

        self::assertSame('redis://scl-redis:6379/0', $client->dsn);
    }

    /** Under another facade client the options are that client's, and the store does not read them. */
    #[Test]
    public function theOptionsOfAnotherClientAreNotChecked(): void
    {
        $client = (new Connector())->clientForConnection(
            redis: [
                'client'  => 'phpredis',
                'options' => [
                    'prefix'     => 'laravel_database_',
                    'persistent' => true,
                ],
                'cache'   => [
                    'host' => 'scl-redis',
                ],
            ],
            name: 'cache',
        );

        self::assertSame('redis://scl-redis:6379/0', $client->dsn);
    }

    #[Test]
    public function theOptionsOfThisClientAreChecked(): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);

        (new Connector())->clientForConnection(
            redis: [
                'client'  => 'sconcur',
                'options' => [
                    'prefix' => 'laravel_database_',
                ],
                'cache'   => [
                    'host' => 'scl-redis',
                ],
            ],
            name: 'cache',
        );
    }
}
