<?php

declare(strict_types=1);

/*
| Only what the demo adds or overrides. Laravel merges the framework's own
| config/database.php underneath, and `connections` is one of the keys it merges
| deeply — so the stock `mysql` entry is here without being written out, reading the
| same DB_* variables. That is the point: the two connections talk to the same server
| with the same credentials, and switching between them is one variable.
*/
return [
    'default' => env('DB_CONNECTION', 'sconcur_mysql'),

    'connections' => [
        /*
        | The non-blocking MySQL connection. Same keys as the PDO one, plus the pool
        | sizes the extension raises: every concurrent statement takes its own physical
        | connection, so the ceiling is what keeps a fan-out from walking into the
        | server's max_connections.
        */
        'sconcur_mysql' => [
            'driver'         => 'sconcur_mysql',
            'host'           => env('DB_HOST', '127.0.0.1'),
            'port'           => env('DB_PORT', '3306'),
            'database'       => env('DB_DATABASE', 'demo'),
            'username'       => env('DB_USERNAME', 'root'),
            'password'       => env('DB_PASSWORD', ''),
            'charset'        => 'utf8mb4',
            'collation'      => 'utf8mb4_unicode_ci',
            'prefix'         => '',
            'strict'         => true,
            'max_open_conns' => (int) env('DB_MAX_OPEN_CONNS', 20),
        ],
    ],

    /*
    | Replaces the framework's `redis` section whole rather than merging into it — only
    | `connections` is merged — and has to: the framework's entries carry `max_retries` and
    | `backoff_*`, which the sconcur client does not read and refuses.
    |
    | `timeout_ms`, `pool_size` and `conn_max_lifetime_ms` are the feature's own; left
    | out, the extension's defaults stand (30000 ms, 4 connections, no lifetime limit).
    */
    'redis' => [
        'client' => env('REDIS_CLIENT', 'sconcur'),

        'default' => [
            'url'        => env('REDIS_URL'),
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', '6379'),
            'username'   => env('REDIS_USERNAME'),
            'password'   => env('REDIS_PASSWORD'),
            'database'   => env('REDIS_DB', '0'),
            'timeout_ms' => (int) env('REDIS_TIMEOUT_MS', 5000),
        ],

        // A database of its own: a cache flush empties the whole database it is in.
        'cache' => [
            'url'        => env('REDIS_URL'),
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', '6379'),
            'username'   => env('REDIS_USERNAME'),
            'password'   => env('REDIS_PASSWORD'),
            'database'   => env('REDIS_CACHE_DB', '1'),
            'timeout_ms' => (int) env('REDIS_TIMEOUT_MS', 5000),
        ],
    ],
];
