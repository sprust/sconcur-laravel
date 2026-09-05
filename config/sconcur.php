<?php

declare(strict_types=1);

// Published into the application (`vendor:publish --tag=sconcur-laravel`) and read from
// there, not from here. See the package README.
return [
    /*
    |--------------------------------------------------------------------------
    | Telemetry panel stats endpoint
    |--------------------------------------------------------------------------
    | Full URL of the master telemetry panel stats endpoint as reachable from the
    | app (master runs in the workers container, the app in php-fpm). The dashboard
    | client GETs this URL with the adminToken bearer.
    */
    'panel_host' => env('SCONCUR_PANEL_HOST', 'http://127.0.0.1:28081/api/stats'),

    /*
    |--------------------------------------------------------------------------
    | Scoped services
    |--------------------------------------------------------------------------
    | Extra container aliases that must be resolved per-coroutine (in addition to
    | the built-in ScopedService set: request, session, auth, auth.driver, cookie).
    | See docs/fiber-safe-laravel-bridge.ru.md.
    */
    'scoped_services' => [
        // \Some\Package\Manager::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Master config
    |--------------------------------------------------------------------------
    | Full mirror of vendor/sconcur/sconcur/config/sconcur.servers.config.json.
    | Keys are kept verbatim (camelCase) so this array can be serialized straight
    | into the JSON master config consumed by bin/sconcur-server (MasterCli).
    | Values are env-driven with project defaults (cf. servers/sconcur/...).
    |
    | The top level is what belongs to the master as a whole; the pools it
    | supervises are the `groups` list (SConcur 0.11 moved workerScript,
    | workerCount, workerArgs and server there — one master now runs several
    | unlike pools under one lock and one journal).
    */
    'master' => [
        'phpBinary'           => env('SCONCUR_HTTP_PHP_BINARY', 'php'),
        'phpArgs'             => [],
        'panelPort'           => (int) env('SCONCUR_HTTP_PANEL_PORT', 28081),
        'adminToken'          => env('SCONCUR_HTTP_ADMIN_TOKEN', ''),
        'runtimeDir'          => storage_path('sconcur/runtime'),
        'logDir'              => storage_path('sconcur/logs'),
        'name'                => env('SCONCUR_HTTP_NAME', 'sconcur-http-server'),
        'rotateDays'          => (int) env('SCONCUR_HTTP_ROTATE_DAYS', 3),
        'logTo'               => env('SCONCUR_HTTP_LOG_TO', 'both'),
        'restartPolicy'       => env('SCONCUR_HTTP_RESTART_POLICY', 'always'),
        'shutdownTimeoutMs'   => (int) env('SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS', 10000),
        'restartBackoffMs'    => (int) env('SCONCUR_HTTP_RESTART_BACKOFF_MS', 200),
        'maxRestartBackoffMs' => (int) env('SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS', 30000),

        // array_values, and not for tidiness: array_filter preserves keys, so dropping
        // the conditional rabbitmq group out of the middle leaves [0 => http, 2 => tasks]
        // — and MasterConfig::parseGroups refuses anything that is not a list. Without
        // this, SCONCUR_RABBITMQ_WORKER_COUNT=0 — the documented way to turn the consumer
        // pool off — stops the master from starting at all, HTTP and tasks included.
        'groups' => array_values(array_filter([
            [
                // The master spawns workers as: phpBinary phpArgs workerScript workerArgs --masterPid=N
                // i.e. `php artisan sconcur:servers:http:start --masterPid=N`.
                'name'         => 'http',
                'workerScript' => base_path('artisan'),
                // Two, not one, so that a rolling reload has somewhere to send traffic:
                // reload takes a slot down and only then starts its replacement, and
                // SO_REUSEPORT hands new connections to the workers still listening. A
                // single worker leaves nobody listening for the ~100 ms that takes, and
                // whoever knocks in that window gets a refused connection.
                'workerCount'  => (int) env('SCONCUR_HTTP_WORKER_COUNT', 2),
                'workerArgs'   => ['sconcur:servers:http:start'],
                // Forwarded to the worker's argv verbatim, which is where
                // HttpServer::fromArgs reads it back.
                'server'       => [
                    'address'             => env('SCONCUR_HTTP_ADDRESS', '0.0.0.0:28080'),
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

            /*
            | The queue-consumer pool. Its `server` block travels the same way the http
            | group's does: forwarded to the worker's argv verbatim, read back by
            | QueueConsumer::fromArgs, with the command declaring those flags so artisan
            | accepts them.
            |
            | Off unless asked for: a worker count below one leaves the group out of the
            | master config entirely. Setting workerCount to 0 would not do it — to the
            | master that means one worker per CPU, not none.
            */
            (int) env('SCONCUR_RABBITMQ_WORKER_COUNT', 0) < 1 ? null : [
                'name'         => 'rabbitmq',
                'workerScript' => base_path('artisan'),
                'workerCount'  => (int) env('SCONCUR_RABBITMQ_WORKER_COUNT'),
                'workerArgs'   => ['sconcur:servers:rabbitmq:start'],
                'server'       => [
                    // Queues and their weights: how many consumers each gets, every
                    // one on its own channel. A handler still runs in its own coroutine
                    // per message. The master JSON-encodes it on the way to argv.
                    'queues'           => [
                        [
                            'name'           => env('SCONCUR_RABBITMQ_QUEUE', 'default'),
                            'coroutineCount' => (int) env('SCONCUR_RABBITMQ_QUEUE_CONSUMERS', 1),
                        ],
                    ],
                    // One is the right answer for a coroutine pool: the next message
                    // goes to a free coroutine rather than into a busy one's buffer.
                    'prefetchCount'    => (int) env('SCONCUR_RABBITMQ_PREFETCH_COUNT', 1),
                    // No deadline unless one is asked for. A deadline refuses the job it
                    // catches rather than slowing it down, so the application decides
                    // whether its jobs have one.
                    'handlerTimeoutMs' => (int) env('SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS', 0),
                    // False dead-letters a failed message (or drops it where the queue
                    // names no exchange); true loops forever on one that always fails.
                    // Retries are the job's own business, through release().
                    'requeueOnFailure'  => (bool) env('SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE', false),
                    'maxMessages'       => (int) env('SCONCUR_RABBITMQ_MAX_MESSAGES', 0),
                    'maxRuntimeSeconds' => (int) env('SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS', 0),
                    'maxMemoryBytes'    => (int) env('SCONCUR_RABBITMQ_MAX_MEMORY_BYTES', 0),
                ],
            ],
            /*
            | The WebSocket pool. Its `server` block travels the way the others do:
            | forwarded to the worker's argv verbatim, read back by WsServer::fromArgs,
            | with WsStartCommand declaring those flags so artisan accepts them.
            |
            | Off unless asked for, like the consumer pool: a worker count below one leaves
            | the group out of the master config entirely.
            */
            (int) env('SCONCUR_WS_WORKER_COUNT', 0) < 1 ? null : [
                'name'         => 'ws',
                'workerScript' => base_path('artisan'),
                'workerCount'  => (int) env('SCONCUR_WS_WORKER_COUNT'),
                'workerArgs'   => ['sconcur:servers:ws:start'],
                'server'       => [
                    'address'   => env('SCONCUR_WS_ADDRESS', '0.0.0.0:28090'),
                    'reusePort' => (bool) env('SCONCUR_WS_REUSE_PORT', true),

                    // The exact path clients upgrade on, key included: the comparison
                    // ignores the query string, so Echo's /app/{key}?protocol=7 matches and
                    // a wrong key is a 404 on the handshake, before PHP sees it. An empty
                    // string would accept any path and leave the check to the handler.
                    'path'      => env('SCONCUR_WS_PATH', '/app/' . env('SCONCUR_WS_APP_KEY', '')),

                    'handshakeTimeoutMs' => (int) env('SCONCUR_WS_HANDSHAKE_TIMEOUT_MS', 10000),

                    // Off: silence from a client is normal here — a page may only ever
                    // listen. What keeps a dead peer from being held for ever is the ping
                    // below, not an idle deadline.
                    'idleTimeoutMs'      => (int) env('SCONCUR_WS_IDLE_TIMEOUT_MS', 0),

                    'writeTimeoutMs'     => (int) env('SCONCUR_WS_WRITE_TIMEOUT_MS', 30000),
                    'pingIntervalMs'     => (int) env('SCONCUR_WS_PING_INTERVAL_MS', 30000),
                    'maxMessageBytes'    => (int) env('SCONCUR_WS_MAX_MESSAGE_BYTES', 1048576),
                    'maxConcurrency'     => (int) env('SCONCUR_WS_MAX_CONCURRENCY', 0),

                    // Not a per-frame deadline: it bounds the whole life of a connection,
                    // and anything above zero disconnects every client on a timer. Left
                    // hard-coded rather than env-driven, because there is no value of it
                    // that a ws pool wants.
                    'handlerTimeoutMs'   => 0,

                    // How many connections to serve before standing down, not how many to
                    // hold at once. A leak guard, off by default.
                    'maxConnections'     => (int) env('SCONCUR_WS_MAX_CONNECTIONS', 0),

                    'shutdownTimeoutMs'   => (int) env('SCONCUR_WS_SHUTDOWN_TIMEOUT_MS', 10000),
                    'preemptionQuantumMs' => (int) env('SCONCUR_WS_PREEMPTION_QUANTUM_MS', 5),
                ],
            ],

            /*
            | The periodic task pool. Exactly one worker, always: a second one would tick
            | the cron twice a minute, and workerCount 0 does not mean none — to the
            | master it means one worker per CPU.
            |
            | No `server` block: the pool reads nothing from argv but the master's pid,
            | which the master appends by itself.
            |
            | Unlike the pools above, it reports to the panel from PHP rather than from the
            | extension's own runtime, because it runs no such runtime — see
            | TaskPoolTelemetry and the `tasks` section further down.
            */
            [
                'name'         => 'tasks',
                'workerScript' => base_path('artisan'),
                'workerCount'  => 1,
                'workerArgs'   => ['sconcur:tasks:start'],
                // Not the master's `always`: this pool is meant to be stoppable.
                // `sconcur:tasks:stop` drains the tasks and exits 0, and under `always`
                // the master would put a fresh pool up within the second — a stop that
                // does not stop. The one exit that does want a new process, the memory
                // limit, is non-zero on purpose (TaskPool::EXIT_RESTART).
                'restartPolicy' => 'on-failure',
                // Must exceed the pool's own shutdown deadline (20 s), or the master
                // kills it before the graceful stop can finish; and supervisor's
                // stopwaitsecs for the master (40 s) must in turn exceed this. There is
                // no supervisor program for the pool itself — the master spawns it.
                'shutdownTimeoutMs' => (int) env('SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS', 30000),
            ],
        ])),
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue transports
    |--------------------------------------------------------------------------
    | One section per transport, the same split as src/Queue/<Transport>.
    |
    | `connection` names the config/queue.php entry the consumer runs jobs on; it is
    | expected to use the `sconcur_rabbitmq` driver. `queues` is what
    | sconcur:rabbitmq:declare declares — it must list every queue the pool above
    | consumes, since the consumer runtime declares nothing itself.
    */
    'queue' => [
        'rabbitmq' => [
            'connection' => env('SCONCUR_RABBITMQ_CONNECTION', 'sconcur_rabbitmq'),

            'queues' => [
                env('SCONCUR_RABBITMQ_QUEUE', 'default'),
            ],

            // Attempts before Worker::process() writes the job to failed_jobs, and the
            // delay it releases with. Both are the defaults; a job's own $tries and
            // $backoff win over them.
            'tries'     => (int) env('SCONCUR_RABBITMQ_TRIES', 1),
            'backoff'   => (int) env('SCONCUR_RABBITMQ_BACKOFF', 0),
            'memory_mb' => (int) env('SCONCUR_RABBITMQ_MEMORY_MB', 128),
        ],
    ],
    /*
    |--------------------------------------------------------------------------
    | WebSocket pool
    |--------------------------------------------------------------------------
    | The protocol side of the `ws` group above; the network side is that group's
    | `server` block, which travels through argv instead.
    |
    | The wire protocol is a compatible subset of Pusher's, so `laravel-echo` talks to
    | this pool with no client of its own and channel authorization goes through the
    | application's ordinary /broadcasting/auth route. The key is public — the browser
    | carries it — and the secret is what signs a subscription; only the http workers
    | (to sign) and the ws workers (to verify) ever see it.
    */
    'ws' => [
        'app_key'    => env('SCONCUR_WS_APP_KEY', ''),
        'app_secret' => env('SCONCUR_WS_APP_SECRET', ''),

        // Echo connects to /app/{key}; this is the part before the key.
        'path_prefix' => env('SCONCUR_WS_PATH_PREFIX', '/app'),

        // Told to the client in the handshake: how long it may stay silent before it
        // should ping. The server's own keepalive is pingIntervalMs in the group above.
        'activity_timeout_seconds' => (int) env('SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS', 120),

        'max_channels_per_connection' => (int) env('SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION', 100),

        // client-* events: one subscriber talking to the others with no application in
        // between. Off by default, as they are with Pusher.
        'client_events'            => (bool) env('SCONCUR_WS_CLIENT_EVENTS', false),
        'client_events_per_minute' => (int) env('SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE', 60),

        /*
        | How a broadcast crosses the process boundary. It has to cross one: the event is
        | raised in an http worker, a queue consumer or a task, and the connections live
        | in the ws workers.
        |
        | `amqp` is a fanout exchange with one queue per ws worker. `local` goes no
        | further than the process it was published in — a test double, not a lighter
        | default: with it, nothing an http worker broadcasts ever reaches a browser.
        */
        'bus' => [
            'driver'   => env('SCONCUR_WS_BUS_DRIVER', 'amqp'),
            'dsn'      => env('SCONCUR_WS_BUS_DSN', env('SCONCUR_RABBITMQ_DSN')),
            'exchange' => env('SCONCUR_WS_BUS_EXCHANGE', 'sconcur.ws'),

            // Not a network knob: this is the subscriber's heartbeat. The consumer only
            // hands control back on a delivery or on this timeout, and that is when a
            // worker gets to notice its last connection is gone and stand down — which is
            // what lets the server's graceful shutdown finish. It therefore also bounds
            // that shutdown, and must stay well below the master's shutdownTimeoutMs.
            'read_timeout_seconds' => (float) env('SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS', 5.0),

            'reopen_backoff_ms' => (int) env('SCONCUR_WS_BUS_REOPEN_BACKOFF_MS', 1000),
        ],

        /*
        | Where the member list of a presence channel lives. `auto` decides from the pool
        | size, which is the only thing that determines the right answer: one worker holds
        | every connection there is, several do not — and a list built from one worker's
        | connections is wrong rather than partial.
        |
        | `memory` under a multi-worker pool is reported by the start command, not honoured
        | in silence.
        */
        'presence' => [
            'store'        => env('SCONCUR_WS_PRESENCE_STORE', 'auto'),
            'ttl_seconds'  => (int) env('SCONCUR_WS_PRESENCE_TTL_SECONDS', 3600),
            'cache_prefix' => env('SCONCUR_WS_PRESENCE_CACHE_PREFIX', 'sconcur:ws:presence'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Periodic task pool
    |--------------------------------------------------------------------------
    | The third runtime of this package, beside the HTTP server and the queue-consumer
    | pool: one process running every task below as its own coroutine of a WaitGroup.
    |
    | A task implements tick() and nothing else — the loop, the pauses, the reporting and
    | the stop belong to the pool. `sconcur:tasks:stop` and `sconcur:tasks:restart` reach
    | a running pool through `control_key`, which is what lets another container manage
    | it. See the package's docs/task-pool.ru.md.
    */
    'tasks' => [
        'control_key' => env('SCONCUR_TASKS_CONTROL_KEY', 'sconcur:tasks:control'),

        // flock, not a cache lock: the kernel releases it when the process dies, SIGKILL
        // included, so a second pool cannot start beside the first and there is no stale
        // lock to clean up.
        'lock_path'   => env('SCONCUR_TASKS_LOCK_PATH', storage_path('sconcur/runtime/tasks.lock')),

        // A leak anywhere in the process takes every task down with it, so the limit is
        // the pool's. Passing it is a graceful stop, and the supervisor starts a fresh one.
        'memory_mb'   => (int) env('SCONCUR_TASKS_MEMORY_MB', 256),

        // How finely a pause is cut, which is how fast the pool notices a signal: a
        // pcntl handler only runs while PHP does, and a process whose coroutines are all
        // parked in the extension executes none. The library's own servers poll on the same 250 ms.
        'sleep_chunk_ms' => (int) env('SCONCUR_TASKS_SLEEP_CHUNK_MS', 250),

        // Automatic coroutine switching, so a tick busy with pure computation cannot
        // starve the controller that carries the shutdown — it is the controller's tick
        // that delivers a signal and reads the control channel. Coarser than the
        // library's 5 ms default on purpose: this is not a server with dozens of
        // handlers sharing the thread and nobody here waits on a response, so the worst
        // reaction to SIGTERM is this quantum plus a sleep chunk against a 20 s deadline.
        //
        // 0 turns it off, which is what a task holding a MySQL transaction on the shared
        // connection needs: without per-coroutine connections, preemption lets another
        // task's query land inside that transaction.
        'preemption_quantum_ms' => (int) env('SCONCUR_TASKS_PREEMPTION_QUANTUM_MS', 1000),

        // The tick counters that fill the panel's "In-flight / Handled / Refused"
        // columns for this pool, sent as the snapshot's `consumers` section — a tick is
        // to a task what a delivery is to a consumer. The catch is one level up: the
        // master sums that section across every worker, so its master-wide deliveries
        // per second and average duration will count ticks alongside the AMQP pool's
        // real deliveries. Per-group numbers stay clean either way; turn this off to
        // keep the pool out of the master's totals.
        'report_ticks' => (bool) env('SCONCUR_TASKS_REPORT_TICKS', true),

        // How long a stop waits for the running ticks before the group is unwound. Must
        // stay below the master's shutdownTimeoutMs for the tasks group (30 s), or the
        // process is always killed before this can happen and the graceful path never
        // runs at all. The pool has no supervisor program of its own — the master spawns
        // it — so supervisor's stopwaitsecs (40 s, on the master) only has to exceed that.
        'shutdown_timeout_seconds' => (int) env('SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS', 20),

        /*
        | The tasks themselves, each naming a class that implements TaskInterface:
        |
        |     ['name' => 'cron', 'task' => CronTask::class, 'idle' => 5, 'busy' => 5, 'backoff' => 5]
        |
        | Empty here because the package ships no tasks of its own — they belong to the
        | application that publishes this file.
        */
        'list' => [],
    ],
];
