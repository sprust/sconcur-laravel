<?php

declare(strict_types=1);

/*
| The connection the integration tests run against — the live MySQL the compose file
| raises, reachable under these names from inside the php container (the values come
| from phpunit.xml).
|
| Both connections are spelled out rather than leaning on the framework defaults being
| merged underneath: testbench assembles the config its own way, and the PDO entry has to
| be here for the tests that compare the two against each other.
*/
return [
    'default' => env('DB_CONNECTION', 'sconcur_mysql'),

    'connections' => [
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
            'max_open_conns' => 20,
        ],

        // The same server over PDO — what sconcur_mysql is compared against.
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE', 'demo'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'strict'    => true,
        ],
    ],

    // The live Redis the compose file raises; databases 8 and 9 (phpunit.xml), apart from
    // the demo's, because the cache tests flush.
    'redis' => [
        'client' => env('REDIS_CLIENT', 'sconcur'),

        'default' => [
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', '6379'),
            'password'   => env('REDIS_PASSWORD'),
            'database'   => env('REDIS_DB', '8'),
            'timeout_ms' => 5000,
        ],

        'cache' => [
            'host'       => env('REDIS_HOST', '127.0.0.1'),
            'port'       => env('REDIS_PORT', '6379'),
            'password'   => env('REDIS_PASSWORD'),
            'database'   => env('REDIS_CACHE_DB', '9'),
            'timeout_ms' => 5000,
        ],
    ],
];
