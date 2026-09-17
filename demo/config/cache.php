<?php

declare(strict_types=1);

/*
| Only what the demo adds. `stores` is merged over the framework's own, so `file`,
| `array` and the rest stay available; CACHE_STORE picks between them.
|
| The task pool's control channel and the ws presence list both live in the default store,
| and both are read from more than one process — the shared Redis is what they are
| meant to be on.
*/
return [
    'default' => env('CACHE_STORE', 'sconcur_redis'),

    'stores' => [
        'sconcur_redis' => [
            'driver'          => 'sconcur_redis',
            'connection'      => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],
    ],
];
