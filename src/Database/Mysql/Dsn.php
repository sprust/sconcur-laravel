<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

/**
 * Builds the MySQL DSN out of a config/database.php connection entry.
 *
 * The PDO connector applies charset, timezone and sql_mode as SET statements after
 * connecting; this driver takes them in the DSN instead. Verified against the parser the
 * extension carries (ext/src/features/sql/dsn.rs) in sconcur 0.12.2:
 *
 * - `charset`, `collation`, `time_zone` and `tls` are connect options it knows by name;
 * - anything else it does not recognise is what this DSN format says it is — a session
 *   system variable, issued as one `SET name=value, ...` on every connection the pool
 *   opens, after the driver's own session setup and therefore winning over it. That is
 *   how `sql_mode` gets there;
 * - a parameter value is percent-decoded, so everything is written with rawurlencode().
 *   A value MySQL needs quoted carries its quotes here. Unlike a URL query string a
 *   literal `+` survives as a `+`, but encoding it costs nothing and keeps one rule.
 *
 * Credentials are the exception: the parser splits them on the last `@` and the first
 * `:` and unescapes neither half, so they go in verbatim — percent-encoding a password
 * would send it percent-encoded.
 *
 * parseTime is deliberately absent, and would do nothing if it were there: the extension
 * accepts it and ignores it (it configured the retired Go client), and
 * DATE/DATETIME/TIMESTAMP always arrive RFC3339. Eloquent reads them anyway —
 * Model::getDateFormat() does not match, so asDateTime() falls through to Date::parse().
 */
class Dsn
{
    /**
     * The sql_mode Laravel's MySqlConnector applies for `strict => true` on MySQL
     * 8.0.11 and above, verbatim. The server version is not probed: the DSN is
     * built before any connection exists, and this project runs mysql:8.0.
     */
    private const string STRICT_MODE = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE'
        . ',ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private const string NON_STRICT_MODE = 'NO_ENGINE_SUBSTITUTION';

    /**
     * @param array<string, mixed> $config
     */
    public static function build(array $config): string
    {
        $credentials = (string) ($config['username'] ?? '')
            . ':' . (string) ($config['password'] ?? '');

        $address = self::address($config);

        $database = (string) ($config['database'] ?? '');

        $parameters = self::parameters($config);

        $query = $parameters === [] ? '' : '?' . implode('&', $parameters);

        return $credentials . '@' . $address . '/' . $database . $query;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function address(array $config): string
    {
        $socket = (string) ($config['unix_socket'] ?? '');

        if ($socket !== '') {
            return 'unix(' . $socket . ')';
        }

        $host = (string) ($config['host'] ?? '127.0.0.1');
        $port = (string) ($config['port'] ?? '3306');

        return 'tcp(' . $host . ':' . $port . ')';
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     */
    private static function parameters(array $config): array
    {
        $parameters = [];

        foreach (self::systemVariables($config) as $name => $value) {
            $parameters[] = $name . '=' . rawurlencode($value);
        }

        // Explicit choices in the config win over everything above, including the
        // ones this class derives, so an application can reach a driver flag the
        // config keys do not cover.
        foreach ((array) ($config['dsn_params'] ?? []) as $name => $value) {
            $parameters[] = ((string) $name) . '=' . rawurlencode((string) $value);
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, string>
     */
    private static function systemVariables(array $config): array
    {
        $variables = [];

        $charset = (string) ($config['charset'] ?? '');

        if ($charset !== '') {
            $variables['charset'] = $charset;
        }

        $collation = (string) ($config['collation'] ?? '');

        if ($collation !== '') {
            $variables['collation'] = $collation;
        }

        $timezone = (string) ($config['timezone'] ?? '');

        if ($timezone !== '') {
            $variables['time_zone'] = "'" . $timezone . "'";
        }

        $sqlMode = self::sqlMode($config);

        if ($sqlMode !== null) {
            $variables['sql_mode'] = "'" . $sqlMode . "'";
        }

        return $variables;
    }

    /**
     * Mirrors MySqlConnector::getSqlMode(): explicit `modes` win, `strict` picks
     * one of the two canned lists, and neither key means the server's own default
     * is left alone.
     *
     * @param array<string, mixed> $config
     */
    private static function sqlMode(array $config): ?string
    {
        if (isset($config['modes'])) {
            return implode(',', (array) $config['modes']);
        }

        if (!isset($config['strict'])) {
            return null;
        }

        return $config['strict'] ? self::STRICT_MODE : self::NON_STRICT_MODE;
    }
}
