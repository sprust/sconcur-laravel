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
