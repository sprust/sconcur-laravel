<?php

declare(strict_types=1);

/*
| The demo broadcasts over the ws pool. The driver publishes to the fanout bus rather
| than to the ws server over HTTP — the ws server has no application routes, and under
| SO_REUSEPORT an individual worker has no address of its own.
|
| Everything the driver needs (the key, the secret, the bus) is read from
| config/sconcur.php, so the two files cannot drift apart.
*/
return [
    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [
        'sconcur' => [
            'driver' => 'sconcur',
        ],

        'null' => [
            'driver' => 'null',
        ],
    ],
];
