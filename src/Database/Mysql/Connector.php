<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

use SConcur\Features\Mysql\Connection as SqlConnection;

/**
 * Builds the connection from a config/database.php entry, registered under the
 * `sconcur_mysql` driver name.
 *
 * Nothing is opened here. The feature's connection object only holds a DSN and
 * the pool sizes; the pool itself is raised in the extension by the first
 * statement, so resolving a connection Laravel may never use costs no socket.
 */
readonly class Connector
{
    /**
     * A ceiling picked here rather than left to the feature: every concurrent
     * statement takes its own connection, so a fan-out walks straight into the
     * server's max_connections (MySQL error 1040). `0` is not "no limit" — the
     * extension reads it as its own built-in 32 — so the number is stated.
     *
     * `max_idle_conns` is still forwarded and still does nothing: since sconcur
     * 0.12.1 the pool keeps every idle connection up to the cap by itself and
     * the value is accepted but never applied. It stays part of the pool key,
     * so two values do mean two pools.
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
