<?php

declare(strict_types=1);

/*
| `connections` is merged deeply with the framework's defaults, so `sync` and the rest
| stay available — the tests and any artisan call that should not touch a broker use
| them.
*/
return [
    'default' => env('QUEUE_CONNECTION', 'sconcur_rabbitmq'),

    'connections' => [
        'sconcur_rabbitmq' => [
            'driver' => 'sconcur_rabbitmq',
            'queue'  => env('SCONCUR_RABBITMQ_QUEUE', 'demo'),
            'dsn'    => env('SCONCUR_RABBITMQ_DSN'),

            // Off: a confirm costs a round trip per publish, and the demo's jobs are
            // not worth one. Delayed publishes confirm regardless of this — they have
            // to, since an unroutable one would otherwise be dropped silently.
            'confirm_publishes' => (bool) env('SCONCUR_RABBITMQ_CONFIRM_PUBLISHES', false),
        ],
    ],
];
