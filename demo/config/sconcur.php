<?php

declare(strict_types=1);

use Demo\App\Support\ScalingSettings;
use Demo\App\Tasks\HeartbeatTask;
use Demo\App\Tasks\ScalingTask;

/*
| The published package config (`vendor:publish --tag=sconcur-laravel`), filled in for
| the demo. The package does not merge its own file underneath — the application owns
| every value here, defaults included.
|
| Differences from the package skeleton, and why:
|
| - the http address is built from SCONCUR_HTTP_PORT rather than taken whole from
|   SCONCUR_HTTP_ADDRESS, because docker compose and the nginx template need the port on
|   its own, and two variables that must agree is a way to make them disagree;
| - the rabbitmq group is on by default here (the skeleton leaves it off), since the
|   demo is what shows the pool working;
| - the task list is not empty: the heartbeat task is what makes the third runtime
|   visible on the page.
|
| The three pool sizes come from ScalingSettings rather than straight from ENV, because
| the demo page can change them: the numbers live in a file both this config and the
| page can see, and the master picks them up when the task pool rolls the group. Nothing
| else here is settable at runtime.
*/
$scaling = ScalingSettings::current();

return [
    'panel_host' => env('SCONCUR_PANEL_HOST', 'http://127.0.0.1:28081/api/stats'),

    'scoped_services' => [],

    'master' => [
        'phpBinary'           => env('SCONCUR_HTTP_PHP_BINARY', 'php'),
        'phpArgs'             => [],
        'panelPort'           => (int) env('SCONCUR_PANEL_PORT', 28081),
        'adminToken'          => env('SCONCUR_HTTP_ADMIN_TOKEN', ''),
        'runtimeDir'          => storage_path('sconcur/runtime'),
        'logDir'              => storage_path('sconcur/logs'),
        'name'                => env('SCONCUR_HTTP_NAME', 'scl-master'),
        'rotateDays'          => (int) env('SCONCUR_HTTP_ROTATE_DAYS', 3),
        'logTo'               => env('SCONCUR_HTTP_LOG_TO', 'both'),
        'restartPolicy'       => env('SCONCUR_HTTP_RESTART_POLICY', 'always'),
        'shutdownTimeoutMs'   => (int) env('SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS', 10000),
        'restartBackoffMs'    => (int) env('SCONCUR_HTTP_RESTART_BACKOFF_MS', 200),
        'maxRestartBackoffMs' => (int) env('SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS', 30000),

        // array_values, and not for tidiness: array_filter preserves keys, so dropping
        // the conditional rabbitmq group out of the middle leaves [0 => http, 2 => tasks]
        // — and MasterConfig::parseGroups refuses anything that is not a list.
        'groups' => array_values(array_filter([
            [
                'name'         => 'http',
                'workerScript' => base_path('artisan'),
                // Two, so a rolling reload has somewhere to send traffic: reload takes a
                // slot down and only then starts its replacement, and SO_REUSEPORT hands
                // new connections to the workers still listening.
                'workerCount'  => $scaling['httpWorkers'],
                'workerArgs'   => ['sconcur:servers:http:start'],
                'server'       => [
                    'address'             => '0.0.0.0:' . env('SCONCUR_HTTP_PORT', 28080),
                    'reusePort'           => (bool) env('SCONCUR_HTTP_REUSE_PORT', true),
                    'maxRequests'         => (int) env('SCONCUR_HTTP_MAX_REQUESTS', 0),
                    'maxConcurrency'      => (int) env('SCONCUR_HTTP_MAX_CONCURRENCY', 0),
                    'maxRequestBody'      => (int) env('SCONCUR_HTTP_MAX_REQUEST_BODY', 10485760),
                    'readHeaderTimeoutMs' => (int) env('SCONCUR_HTTP_READ_HEADER_TIMEOUT_MS', 10000),
                    'readTimeoutMs'       => (int) env('SCONCUR_HTTP_READ_TIMEOUT_MS', 30000),
                    'writeTimeoutMs'      => (int) env('SCONCUR_HTTP_WRITE_TIMEOUT_MS', 30000),
                    'idleTimeoutMs'       => (int) env('SCONCUR_HTTP_IDLE_TIMEOUT_MS', 60000),
                    'handlerTimeoutMs'    => (int) env('SCONCUR_HTTP_HANDLER_TIMEOUT_MS', 60000),
                    'shutdownTimeoutMs'   => (int) env('SCONCUR_HTTP_SERVER_SHUTDOWN_TIMEOUT_MS', 5000),
                ],
            ],

            // Off unless asked for: a worker count below one leaves the group out of the
            // master config entirely. Setting workerCount to 0 would not do it — to the
            // master that means one worker per CPU, not none.
            $scaling['rabbitmqWorkers'] < 1 ? null : [
                'name'         => 'rabbitmq',
                'workerScript' => base_path('artisan'),
                'workerCount'  => $scaling['rabbitmqWorkers'],
                'workerArgs'   => ['sconcur:servers:rabbitmq:start'],
                'server'       => [
                    // The weight is how many consumers the queue gets, each on its own
                    // channel — the analogue of running that many `queue:work`
                    // processes on it. A handler still runs in its own coroutine per
                    // message, which is what lets one process hold all four.
                    'queues'            => [
                        [
                            'name'           => env('SCONCUR_RABBITMQ_QUEUE', 'demo'),
                            'coroutineCount' => $scaling['rabbitmqCoroutines'],
                        ],
                    ],
                    'prefetchCount'     => (int) env('SCONCUR_RABBITMQ_PREFETCH_COUNT', 1),
                    'handlerTimeoutMs'  => (int) env('SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS', 0),
                    'requeueOnFailure'  => (bool) env('SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE', false),
                    'maxMessages'       => (int) env('SCONCUR_RABBITMQ_MAX_MESSAGES', 0),
                    'maxRuntimeSeconds' => (int) env('SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS', 0),
                    'maxMemoryBytes'    => (int) env('SCONCUR_RABBITMQ_MAX_MEMORY_BYTES', 0),
                ],
            ],

            // The ws pool. Off unless asked for, like the rabbitmq group; two workers by
            // default so the page is served by a pool rather than by one process, which
            // is what makes the fanout bus do something visible.
            (int) env('SCONCUR_WS_WORKER_COUNT', 0) < 1 ? null : [
                'name'         => 'ws',
                'workerScript' => base_path('artisan'),
                'workerCount'  => (int) env('SCONCUR_WS_WORKER_COUNT'),
                'workerArgs'   => ['sconcur:servers:ws:start'],
                'server'       => [
                    'address'   => '0.0.0.0:' . env('SCONCUR_WS_PORT', 28090),
                    'reusePort' => (bool) env('SCONCUR_WS_REUSE_PORT', true),
                    // The key is part of the path, and the comparison ignores the query
                    // string — so /app/{key}?protocol=7 matches and a wrong key is a 404
                    // on the handshake.
                    'path'      => '/app/' . env('SCONCUR_WS_APP_KEY', ''),

                    'handshakeTimeoutMs' => (int) env('SCONCUR_WS_HANDSHAKE_TIMEOUT_MS', 10000),
                    'idleTimeoutMs'      => (int) env('SCONCUR_WS_IDLE_TIMEOUT_MS', 0),
                    'writeTimeoutMs'     => (int) env('SCONCUR_WS_WRITE_TIMEOUT_MS', 30000),
                    'pingIntervalMs'     => (int) env('SCONCUR_WS_PING_INTERVAL_MS', 30000),
                    'maxMessageBytes'    => (int) env('SCONCUR_WS_MAX_MESSAGE_BYTES', 1048576),
                    'maxConcurrency'     => (int) env('SCONCUR_WS_MAX_CONCURRENCY', 0),
                    // Zero, always: this is a deadline on the whole life of a connection.
                    'handlerTimeoutMs'   => 0,
                    'maxConnections'     => (int) env('SCONCUR_WS_MAX_CONNECTIONS', 0),

                    'shutdownTimeoutMs'   => (int) env('SCONCUR_WS_SHUTDOWN_TIMEOUT_MS', 10000),
                    'preemptionQuantumMs' => (int) env('SCONCUR_WS_PREEMPTION_QUANTUM_MS', 5),
                ],
            ],

            // Exactly one worker, always: a second one would tick every task twice.
            [
                'name'         => 'tasks',
                'workerScript' => base_path('artisan'),
                'workerCount'  => 1,
                'workerArgs'   => ['sconcur:tasks:start'],
                // Not the master's `always`: `sconcur:tasks:stop` exits 0, and under
                // `always` the master would put a fresh pool up within the second — a
                // stop that does not stop.
                'restartPolicy'     => 'on-failure',
                // Must exceed the pool's own shutdown deadline (20 s below), or the
                // master kills it before the graceful stop can finish; supervisor's
                // stopwaitsecs for the master (40 s) must in turn exceed this.
                'shutdownTimeoutMs' => (int) env('SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS', 30000),
            ],
        ])),
    ],

    'queue' => [
        'rabbitmq' => [
            'connection' => env('SCONCUR_RABBITMQ_CONNECTION', 'sconcur_rabbitmq'),

            // What `sconcur:rabbitmq:declare` declares. It must list every queue the
            // pool above consumes — the consumer runtime declares nothing itself.
            'queues' => [
                env('SCONCUR_RABBITMQ_QUEUE', 'demo'),
            ],

            'tries'     => (int) env('SCONCUR_RABBITMQ_TRIES', 3),
            'backoff'   => (int) env('SCONCUR_RABBITMQ_BACKOFF', 0),
            'memory_mb' => (int) env('SCONCUR_RABBITMQ_MEMORY_MB', 128),
        ],
    ],

    'ws' => [
        'app_key'    => env('SCONCUR_WS_APP_KEY', ''),
        'app_secret' => env('SCONCUR_WS_APP_SECRET', ''),

        'path_prefix' => env('SCONCUR_WS_PATH_PREFIX', '/app'),

        'activity_timeout_seconds'    => (int) env('SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS', 120),
        'max_channels_per_connection' => (int) env('SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION', 100),

        'client_events'            => (bool) env('SCONCUR_WS_CLIENT_EVENTS', false),
        'client_events_per_minute' => (int) env('SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE', 60),

        // The demo runs two ws workers, so the bus is doing real work: the page is
        // connected to one of them and the broadcast is published by an http worker.
        'bus' => [
            'driver'   => env('SCONCUR_WS_BUS_DRIVER', 'amqp'),
            'dsn'      => env('SCONCUR_WS_BUS_DSN', env('SCONCUR_RABBITMQ_DSN')),
            'exchange' => env('SCONCUR_WS_BUS_EXCHANGE', 'sconcur.ws'),

            'read_timeout_seconds' => (float) env('SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS', 5.0),
            'reopen_backoff_ms'    => (int) env('SCONCUR_WS_BUS_REOPEN_BACKOFF_MS', 1000),
        ],

        'presence' => [
            'store'        => env('SCONCUR_WS_PRESENCE_STORE', 'auto'),
            'ttl_seconds'  => (int) env('SCONCUR_WS_PRESENCE_TTL_SECONDS', 3600),
            'cache_prefix' => env('SCONCUR_WS_PRESENCE_CACHE_PREFIX', 'sconcur:ws:presence'),
        ],
    ],

    'tasks' => [
        'control_key' => env('SCONCUR_TASKS_CONTROL_KEY', 'sconcur:tasks:control'),
        'lock_path'   => env('SCONCUR_TASKS_LOCK_PATH', storage_path('sconcur/runtime/tasks.lock')),
        'memory_mb'   => (int) env('SCONCUR_TASKS_MEMORY_MB', 256),

        'sleep_chunk_ms'        => (int) env('SCONCUR_TASKS_SLEEP_CHUNK_MS', 250),
        'preemption_quantum_ms' => (int) env('SCONCUR_TASKS_PREEMPTION_QUANTUM_MS', 1000),
        'report_ticks'          => (bool) env('SCONCUR_TASKS_REPORT_TICKS', true),

        // Below the master's shutdownTimeoutMs for this group (30 s above), or the
        // process is always killed before the graceful path can run.
        'shutdown_timeout_seconds' => (int) env('SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS', 20),

        'list' => [
            [
                'name'    => 'heartbeat',
                'task'    => HeartbeatTask::class,
                'idle'    => 2,
                'busy'    => 2,
                'backoff' => 5,
            ],
            // Polls for a scaling request from the page and rolls the groups it names.
            // One second idle so a change from the page is acted on while the reader is
            // still looking at it.
            [
                'name'    => 'scaling',
                'task'    => ScalingTask::class,
                'idle'    => 1,
                'busy'    => 1,
                'backoff' => 5,
            ],
        ],
    ],
];
