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
];
