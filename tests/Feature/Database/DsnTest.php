<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Database\Mysql\Dsn;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The DSN is where a config/database.php entry becomes something the Go driver
 * understands. The PDO connector applies charset, timezone and sql_mode as SET
 * statements after connecting; here they have to ride in the DSN, so what this builds
 * is the whole of the connection's behaviour.
 */
class DsnTest extends BaseTestCase
{
    #[Test]
    public function itBuildsATcpDsn(): void
    {
        $dsn = Dsn::build([
            'host'     => 'scl-mysql',
            'port'     => 3306,
            'database' => 'demo',
            'username' => 'scl_user',
            'password' => 'secret',
        ]);

        self::assertSame('scl_user:secret@tcp(scl-mysql:3306)/demo', $dsn);
    }

    #[Test]
    public function aUnixSocketWinsOverHostAndPort(): void
    {
        $dsn = Dsn::build([
            'unix_socket' => '/var/run/mysqld/mysqld.sock',
            'host'        => 'ignored',
            'database'    => 'demo',
            'username'    => 'user',
        ]);

        self::assertSame('user:@unix(/var/run/mysqld/mysqld.sock)/demo', $dsn);
    }

    /**
     * A value the driver does not recognise goes out as one SET statement on connect,
     * url-decoded first — and url.QueryUnescape reads `+` as a space, which would
     * corrupt a timezone like `+00:00`. Hence rawurlencode everywhere.
     */
    #[Test]
    public function aTimezoneIsEncodedSoThePlusSurvives(): void
    {
        $dsn = Dsn::build([
            'host'     => '127.0.0.1',
            'database' => 'demo',
            'username' => 'user',
            'timezone' => '+00:00',
        ]);

        self::assertStringContainsString('time_zone=%27%2B00%3A00%27', $dsn);
        self::assertStringNotContainsString('+00', $dsn);
    }

    #[Test]
    public function strictPicksTheSqlModeLaravelWouldHaveApplied(): void
    {
        $strict = Dsn::build([
            'host'     => '127.0.0.1',
            'database' => 'demo',
            'username' => 'user',
            'strict'   => true,
        ]);

        $loose = Dsn::build([
            'host'     => '127.0.0.1',
            'database' => 'demo',
            'username' => 'user',
            'strict'   => false,
        ]);

        self::assertStringContainsString('STRICT_TRANS_TABLES', rawurldecode($strict));
        self::assertStringContainsString('NO_ENGINE_SUBSTITUTION', rawurldecode($loose));
        self::assertStringNotContainsString('STRICT_TRANS_TABLES', rawurldecode($loose));
    }

    /**
     * Neither key means the server's own default is left alone — the connection does not
     * get an opinion it was never given.
     */
    #[Test]
    public function withoutStrictOrModesNoSqlModeIsSent(): void
    {
        $dsn = Dsn::build([
            'host'     => '127.0.0.1',
            'database' => 'demo',
            'username' => 'user',
        ]);

        self::assertStringNotContainsString('sql_mode', $dsn);
    }

    #[Test]
    public function explicitDsnParamsAreAppended(): void
    {
        $dsn = Dsn::build([
            'host'       => '127.0.0.1',
            'database'   => 'demo',
            'username'   => 'user',
            'dsn_params' => ['readTimeout' => '5s'],
        ]);

        self::assertStringContainsString('readTimeout=5s', $dsn);
    }

    /**
     * parseTime is deliberately absent: without it DATE/DATETIME/TIMESTAMP arrive as
     * `Y-m-d H:i:s`, which is what Model::getDateFormat() expects.
     */
    #[Test]
    public function parseTimeIsNeverSent(): void
    {
        $dsn = Dsn::build([
            'host'     => '127.0.0.1',
            'database' => 'demo',
            'username' => 'user',
            'strict'   => true,
            'charset'  => 'utf8mb4',
        ]);

        self::assertStringNotContainsString('parseTime', $dsn);
    }
}
