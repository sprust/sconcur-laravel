<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SConcur\Laravel\Redis\Dsn;
use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisOptionException;

class DsnTest extends TestCase
{
    #[Test]
    public function aTcpEntryBecomesARedisUrl(): void
    {
        self::assertSame(
            'redis://scl-redis:6380/3',
            Dsn::build([
                'host'     => 'scl-redis',
                'port'     => '6380',
                'database' => '3',
            ]),
        );
    }

    #[Test]
    public function missingFieldsFallBackToTheLocalDefaultServer(): void
    {
        self::assertSame('redis://127.0.0.1:6379/0', Dsn::build([]));
    }

    #[Test]
    public function tlsBecomesARedissUrl(): void
    {
        self::assertSame(
            'rediss://example.com:6379/0',
            Dsn::build([
                'scheme' => 'tls',
                'host'   => 'example.com',
            ]),
        );
    }

    /**
     * redis-rs percent-decodes the credentials, so a password with `@`, `:` or `/` in it
     * has to be encoded or the URL splits somewhere else.
     */
    #[Test]
    public function theCredentialsAreEncoded(): void
    {
        self::assertSame(
            'redis://us%3Aer:p%40ss%2Fword@example.com:6379/0',
            Dsn::build([
                'host'     => 'example.com',
                'username' => 'us:er',
                'password' => 'p@ss/word',
            ]),
        );
    }

    #[Test]
    public function aPasswordWithoutAUsernameKeepsTheColon(): void
    {
        self::assertSame(
            'redis://:secret@example.com:6379/0',
            Dsn::build([
                'host'     => 'example.com',
                'password' => 'secret',
            ]),
        );
    }

    #[Test]
    public function anIpv6HostIsBracketed(): void
    {
        self::assertSame(
            'redis://[::1]:6379/0',
            Dsn::build([
                'host' => '::1',
            ]),
        );
    }

    /** On a socket the path is the socket, so the rest has nowhere to go but the query. */
    #[Test]
    public function aSocketCarriesTheRestInTheQuery(): void
    {
        self::assertSame(
            'unix:///run/redis.sock?db=2&user=app&pass=p%26ss',
            Dsn::build([
                'scheme'   => 'unix',
                'path'     => '/run/redis.sock',
                'database' => 2,
                'username' => 'app',
                'password' => 'p&ss',
            ]),
        );
    }

    #[Test]
    public function aSocketWithoutAPathIsRefused(): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);

        Dsn::build([
            'scheme' => 'unix',
        ]);
    }

    #[Test]
    public function anUnknownSchemeIsRefused(): void
    {
        $this->expectException(UnsupportedRedisOptionException::class);
        $this->expectExceptionMessage('"redis"');

        Dsn::build([
            'scheme' => 'redis',
        ]);
    }
}
