<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

use SConcur\Features\Mysql\Connection as SqlConnection;

/**
 * Builds the connection from a config/database.php entry, registered under the
 * `sconcur_mysql` driver name.
 *
 * Nothing is opened here. The feature's connection object only holds a DSN and
 * the pool sizes; the pool itself is raised in the Go extension by the first
 * statement, so resolving a connection Laravel may never use costs no socket.
 */
readonly class Connector
{
    /**
     * A pool without a ceiling is the wrong default here: every concurrent
     * statement takes its own connection, so a fan-out walks straight into the
     * server's max_connections (MySQL error 1040). The feature defaults
     * maxIdleConns to maxOpenConns, which keeps the pool warm between fan-outs.
     */
    private const int DEFAULT_MAX_OPEN_CONNS = 20;

    /**
     * @param array<string, mixed> $config
     */
    public function connect(array $config, string $name): Connection
    {
        $config['name'] = $name;

        return new Connection(
            sql: new SqlConnection(
                dsn: Dsn::build($config),
                timeoutMs: (int) ($config['timeout_ms'] ?? 0) ?: null,
                maxOpenConns: (int) ($config['max_open_conns'] ?? self::DEFAULT_MAX_OPEN_CONNS),
                maxIdleConns: (int) ($config['max_idle_conns'] ?? 0) ?: null,
                connMaxLifetimeMs: (int) ($config['conn_max_lifetime_ms'] ?? 0) ?: null,
            ),
            database: (string) ($config['database'] ?? ''),
            tablePrefix: (string) ($config['prefix'] ?? ''),
            config: $config,
        );
    }
}
