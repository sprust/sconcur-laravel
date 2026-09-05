<?php

declare(strict_types=1);

use Workbench\App\Tasks\CountingTask;
use Workbench\App\Tasks\IdleTask;

/*
| The package config as the test suite needs it. Small on purpose: what is exercised
| here is the wiring — that a group list survives being filtered, that a task is
| resolved out of the container, that the declare command reads the queue list — not a
| production topology.
|
| Tests that need a different shape override it with config()->set in their own setUp;
| this is the baseline they start from.
*/
return [
    'panel_host' => '',

    'scoped_services' => [],

    'master' => [
        'phpBinary'           => 'php',
        'phpArgs'             => [],
        'panelPort'           => 0,
        'adminToken'          => '',
        'runtimeDir'          => storage_path('sconcur/runtime'),
        'logDir'              => storage_path('sconcur/logs'),
        'name'                => 'workbench-master',
        'rotateDays'          => 1,
        'logTo'               => 'stdout',
        'restartPolicy'       => 'always',
        'shutdownTimeoutMs'   => 5000,
        'restartBackoffMs'    => 200,
        'maxRestartBackoffMs' => 5000,

        'groups' => array_values(array_filter([
            [
                'name'         => 'http',
                'workerScript' => base_path('artisan'),
                'workerCount'  => 2,
                'workerArgs'   => ['sconcur:servers:http:start'],
                'server'       => [
                    'address'   => '127.0.0.1:28090',
                    'reusePort' => true,
                ],
            ],

            (int) env('SCONCUR_RABBITMQ_WORKER_COUNT', 0) < 1 ? null : [
                'name'         => 'rabbitmq',
                'workerScript' => base_path('artisan'),
                'workerCount'  => (int) env('SCONCUR_RABBITMQ_WORKER_COUNT'),
                'workerArgs'   => ['sconcur:servers:rabbitmq:start'],
                'server'       => [
                    'queues' => [
                        [
                            'name'           => env('SCONCUR_RABBITMQ_QUEUE', 'tests'),
                            'coroutineCount' => 1,
                        ],
                    ],
                    'prefetchCount' => 1,
                ],
            ],

            (int) env('SCONCUR_WS_WORKER_COUNT', 0) < 1 ? null : [
                'name'         => 'ws',
                'workerScript' => base_path('artisan'),
                'workerCount'  => (int) env('SCONCUR_WS_WORKER_COUNT'),
                'workerArgs'   => ['sconcur:servers:ws:start'],
                'server'       => [
                    'address'          => '127.0.0.1:28095',
                    'reusePort'        => false,
                    'path'             => '/app/' . env('SCONCUR_WS_APP_KEY', 'testkey'),
                    'handlerTimeoutMs' => 0,
                ],
            ],

            [
                'name'              => 'tasks',
                'workerScript'      => base_path('artisan'),
                'workerCount'       => 1,
                'workerArgs'        => ['sconcur:tasks:start'],
                'restartPolicy'     => 'on-failure',
                'shutdownTimeoutMs' => 10000,
            ],
        ])),
    ],

    'queue' => [
        'rabbitmq' => [
            'connection' => 'sconcur_rabbitmq',

            'queues' => [
                env('SCONCUR_RABBITMQ_QUEUE', 'tests'),
            ],

            'tries'     => 1,
            'backoff'   => 0,
            'memory_mb' => 128,
        ],
    ],

    'ws' => [
        'app_key'    => env('SCONCUR_WS_APP_KEY', 'testkey'),
        'app_secret' => env('SCONCUR_WS_APP_SECRET', 'testsecret'),

        'path_prefix' => '/app',

        'activity_timeout_seconds'    => 120,
        'max_channels_per_connection' => 3,

        'client_events'            => true,
        'client_events_per_minute' => 60,

        // The local bus by default: a unit test has one process, and the amqp one is
        // asked for explicitly by the tests that need a broker.
        'bus' => [
            'driver'   => env('SCONCUR_WS_BUS_DRIVER', 'local'),
            'dsn'      => env('SCONCUR_WS_BUS_DSN', env('SCONCUR_RABBITMQ_DSN', '')),
            'exchange' => env('SCONCUR_WS_BUS_EXCHANGE', 'sconcur.ws.tests'),

            'read_timeout_seconds' => 1.0,
            'reopen_backoff_ms'    => 100,
        ],

        'presence' => [
            'store'        => 'memory',
            'ttl_seconds'  => 3600,
            'cache_prefix' => 'sconcur:ws:presence:tests',
        ],
    ],

    'tasks' => [
        'control_key' => 'sconcur:tasks:control',
        'lock_path'   => storage_path('sconcur/runtime/tasks.lock'),
        'memory_mb'   => 128,

        'sleep_chunk_ms'        => 10,
        'preemption_quantum_ms' => 0,
        'report_ticks'          => false,

        'shutdown_timeout_seconds' => 5,

        'list' => [
            [
                'name'    => 'counting',
                'task'    => CountingTask::class,
                'idle'    => 1,
                'busy'    => 1,
                'backoff' => 1,
            ],
            [
                'name'    => 'idle',
                'task'    => IdleTask::class,
                'idle'    => 2,
                'busy'    => 1,
                'backoff' => 3,
            ],
        ],
    ],
];
