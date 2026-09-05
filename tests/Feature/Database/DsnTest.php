<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Database\Mysql\Dsn;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The DSN is where a config/database.php entry becomes something the extension
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
     * The parser splits the credentials on the last `@` and the first `:` and unescapes
     * neither half — unlike a parameter value, which it percent-decodes. Encoding them
     * here would send the password encoded, which is simply the wrong password.
     */
    #[Test]
    public function credentialsTravelVerbatim(): void
    {
        $dsn = Dsn::build([
            'host'     => '127.0.0.1',
            'database' => 'demo',
            'username' => 'user',
            'password' => 'p@ss w:rd',
        ]);

        self::assertSame('user:p@ss w:rd@tcp(127.0.0.1:3306)/demo', $dsn);
    }

    /**
     * A parameter value is percent-decoded on the other side, so a value carrying its
     * own quotes — which a system variable does — has to be encoded here.
     */
    #[Test]
    public function aTimezoneIsEncodedSoItsQuotesAndPlusSurvive(): void
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
            'dsn_params' => ['group_concat_max_len' => '4096'],
        ]);

        self::assertStringContainsString('group_concat_max_len=4096', $dsn);
    }

    /**
     * parseTime is deliberately absent, and the extension would ignore it anyway: it
     * configured the retired Go client, and DATE/DATETIME/TIMESTAMP always arrive
     * RFC3339 now.
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
