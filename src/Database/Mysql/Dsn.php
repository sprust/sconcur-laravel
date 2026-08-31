<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

/**
 * Builds a go-sql-driver/mysql DSN out of a config/database.php connection entry.
 *
 * The PDO connector applies charset, timezone and sql_mode as SET statements after
 * connecting; the Go driver takes them in the DSN instead. Verified against
 * go-sql-driver/mysql 1.8.1, the version the extension is built with
 * (ext/go.mod):
 *
 * - `charset` and `collation` are parameters it knows: handleParams() turns them
 *   into `SET NAMES <charset> COLLATE <collation>`;
 * - anything else it does not recognise lands in cfg.Params and goes out as one
 *   `SET a = x, b = y` on connect, with the value url-decoded first. So a value
 *   MySQL needs quoted carries its quotes here, and everything is written with
 *   rawurlencode() — url.QueryUnescape reads `+` as a space, which would corrupt
 *   a timezone like `+00:00`.
 *
 * parseTime is deliberately absent, unlike the hand-rolled DSN this replaces:
 * without it DATE/DATETIME/TIMESTAMP arrive as `Y-m-d H:i:s`, which is what
 * Model::getDateFormat() expects. With it they arrive RFC3339 and Eloquent only
 * parses them through its fallback path.
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
        $credentials = rawurlencode((string) ($config['username'] ?? ''))
            . ':' . rawurlencode((string) ($config['password'] ?? ''));

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
