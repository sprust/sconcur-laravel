<?php

declare(strict_types=1);

/*
| The store under test. `default` stays `array` (phpunit.xml): only the tests that mean to
| talk to Redis name this store, so everything else keeps running without one.
*/
return [
    'default' => env('CACHE_STORE', 'array'),

    'stores' => [
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],

        'sconcur_redis' => [
            'driver'          => 'sconcur_redis',
            'connection'      => 'cache',
            'lock_connection' => 'default',
        ],
    ],
];
