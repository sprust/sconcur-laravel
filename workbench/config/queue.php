<?php

declare(strict_types=1);

/*
| The AMQP connection the integration tests publish and consume on — the live broker the
| compose file raises, reachable under this DSN from inside the php container
| (phpunit.xml sets it).
|
| `default` stays `sync`: only the tests that mean to talk to the broker name this
| connection, so everything else keeps running without one.
*/
return [
    'default' => env('QUEUE_CONNECTION', 'sync'),

    'connections' => [
        'sconcur_rabbitmq' => [
            'driver' => 'sconcur_rabbitmq',
            'queue'  => env('SCONCUR_RABBITMQ_QUEUE', 'tests'),
            'dsn'    => env('SCONCUR_RABBITMQ_DSN'),
        ],

        'sync' => [
            'driver' => 'sync',
        ],
    ],
];
