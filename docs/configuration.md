English | [Русский](configuration.ru.md)

# Configuration (ENV)

Every value of `config/sconcur.php` comes from ENV, and every variable the package reads is
listed here. The defaults are the package's, from the skeleton; in the published file the
application sets its own.

## Table of contents

- [General](#general)
- [The master (supervisor)](#the-master-supervisor)
- [Groups](#groups)
- [The `http` group](#the-http-group)
- [The HTTP server](#the-http-server)
- [The `rabbitmq` group and its consumers](#the-rabbitmq-group-and-its-consumers)
- [The `ws` group](#the-ws-group)
- [The WebSocket server](#the-websocket-server)
- [The WebSocket protocol](#the-websocket-protocol)
- [The task pool](#the-task-pool)

## General

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_PANEL_HOST` | `http://127.0.0.1:28081/api/stats` | where the dashboard reads the master's stats from |

The coroutine-scoped application has no switch and no mode detection: the provider
installs the adapters in every process.

## The master (supervisor)

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_HTTP_PHP_BINARY` | `php` | the PHP binary for the workers |
| `SCONCUR_HTTP_PANEL_PORT` | `28081` | telemetry panel port (0 = off) |
| `SCONCUR_HTTP_ADMIN_TOKEN` | `` (empty) | the panel's bearer token (empty = off) |
| `SCONCUR_HTTP_NAME` | `sconcur-http-server` | server name (the lock/state/log files) |
| `SCONCUR_HTTP_ROTATE_DAYS` | `3` | log rotation, days |
| `SCONCUR_HTTP_LOG_TO` | `both` | where to log (`file`/`stdout`/`both`) |
| `SCONCUR_HTTP_RESTART_POLICY` | `always` | worker restart policy |
| `SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS` | `10000` | graceful worker stop deadline, ms |
| `SCONCUR_HTTP_RESTART_BACKOFF_MS` | `200` | initial restart backoff, ms |
| `SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS` | `30000` | maximum restart backoff, ms |

Not from ENV: `workerScript=base_path('artisan')`, `phpArgs=[]`, and
`runtimeDir`/`logDir`=`storage_path('sconcur/runtime'|'sconcur/logs')`.

## Groups

One master supervises several unlike pools under one lock and one journal, so
`workerScript`, `workerCount`, `workerArgs` and `server` live not at the top level of the
config but in an element of the `groups` list.

A group's `server` block is forwarded to its workers' argv verbatim, which is why all
three commands — `http:start`, `rabbitmq:start` and `ws:start` — declare those flags:
artisan rejects what is not declared. What reads them back is `HttpServer::fromArgs`,
`QueueConsumer::fromArgs` and `WsServer::fromArgs`. Anything that is not a scalar (the
queue list) the master JSON-encodes on the way.

A run without a master has nobody to forward it, so in that case the command takes the
same `server` block out of its own group's config. The group is looked up by what it
starts rather than by name — otherwise renaming a group would quietly leave a standalone
run on the library's defaults.

A `workerCount` below one is how a pool is turned off: the group does not reach the master
config at all. A zero would not do it — to the master `workerCount: 0` means one worker per
CPU (`WorkerGroup`, `Cpu::count()`).

## The `http` group

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_HTTP_WORKER_COUNT` | `2` | workers in the group (0 = one per CPU) |

## The HTTP server

The `server` block of the `http` group.

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_HTTP_ADDRESS` | `0.0.0.0:28080` | listen address |
| `SCONCUR_HTTP_REUSE_PORT` | `true` | `SO_REUSEPORT` (several processes on one port) |
| `SCONCUR_HTTP_MAX_REQUESTS` | `0` | stop after N requests (0 = ∞) |
| `SCONCUR_HTTP_MAX_CONCURRENCY` | `0` | maximum concurrent requests (0 = ∞) |
| `SCONCUR_HTTP_MAX_REQUEST_BODY` | `10485760` | request body limit, bytes |
| `SCONCUR_HTTP_READ_HEADER_TIMEOUT_MS` | `10000` | header read timeout, ms |
| `SCONCUR_HTTP_READ_TIMEOUT_MS` | `30000` | read timeout, ms |
| `SCONCUR_HTTP_WRITE_TIMEOUT_MS` | `30000` | write timeout, ms |
| `SCONCUR_HTTP_IDLE_TIMEOUT_MS` | `60000` | keep-alive idle timeout, ms |
| `SCONCUR_HTTP_HANDLER_TIMEOUT_MS` | `60000` | request handling timeout, ms |
| `SCONCUR_HTTP_SERVER_SHUTDOWN_TIMEOUT_MS` | `5000` | server stop timeout, ms |

## The `rabbitmq` group and its consumers

How the pool works is in [queue.md](queue.md).

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_RABBITMQ_WORKER_COUNT` | `0` | processes in the pool; below `1` the group does not reach the master config |
| `SCONCUR_RABBITMQ_QUEUE` | `default` | the queue the pool reads |
| `SCONCUR_RABBITMQ_QUEUE_CONSUMERS` | `1` | that queue's weight — how many consumers it gets |
| `SCONCUR_RABBITMQ_PREFETCH_COUNT` | `1` | unacknowledged messages per consumer |
| `SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS` | `0` | deadline for one message in the handler; `0` — none |
| `SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE` | `false` | requeue a failed message instead of dead-lettering it |
| `SCONCUR_RABBITMQ_MAX_MESSAGES` | `0` | drain and exit after N messages |
| `SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS` | `0` | drain and exit after N seconds |
| `SCONCUR_RABBITMQ_MAX_MEMORY_BYTES` | `0` | drain and exit on heap size |
| `SCONCUR_RABBITMQ_MEMORY_MB` | `128` | worker memory limit, MiB |
| `SCONCUR_RABBITMQ_CONNECTION` | `sconcur_rabbitmq` | the `config/queue.php` connection the jobs run on |
| `SCONCUR_RABBITMQ_TRIES` | `1` | attempts before `failed_jobs` |
| `SCONCUR_RABBITMQ_BACKOFF` | `0` | delay before a retry, seconds |
| `SCONCUR_RABBITMQ_DSN` | — | the broker, as `amqp://user:pass@host:5672/%2f` |

The skeleton describes one queue because that is all it can know. A list of any length and
its weights are what the application writes into `sconcur.queue.rabbitmq.queues` of the
published file, and that list is also what `sconcur:rabbitmq:declare` declares.

## The `ws` group

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_WS_WORKER_COUNT` | `0` | workers in the group; below 1 leaves the group out of the config |

## The WebSocket server

The `server` block of the `ws` group.

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_WS_ADDRESS` | `0.0.0.0:28090` | listen address |
| `SCONCUR_WS_REUSE_PORT` | `true` | `SO_REUSEPORT` (several processes on one port) |
| `SCONCUR_WS_PATH` | `/app/${SCONCUR_WS_APP_KEY}` | the exact path the upgrade is accepted on; empty accepts any |
| `SCONCUR_WS_HANDSHAKE_TIMEOUT_MS` | `10000` | how long the upgrade headers may take, ms |
| `SCONCUR_WS_IDLE_TIMEOUT_MS` | `0` | idle timeout between inbound messages (0 = off) |
| `SCONCUR_WS_WRITE_TIMEOUT_MS` | `30000` | one message write, ms |
| `SCONCUR_WS_PING_INTERVAL_MS` | `30000` | server keepalive ping cadence (0 = off) |
| `SCONCUR_WS_MAX_MESSAGE_BYTES` | `1048576` | inbound message size limit |
| `SCONCUR_WS_MAX_CONCURRENCY` | `0` | connections served at once (0 = ∞) |
| `SCONCUR_WS_MAX_CONNECTIONS` | `0` | stop after N served connections (0 = ∞) |
| `SCONCUR_WS_SHUTDOWN_TIMEOUT_MS` | `10000` | graceful stop deadline, ms |
| `SCONCUR_WS_PREEMPTION_QUANTUM_MS` | `5` | preemption quantum while serving |

`handlerTimeoutMs` is not among them and is hard-coded to `0`. Here it is a deadline on the
whole life of a connection rather than on one frame, so any value above zero disconnects
every client on a timer — there is no setting of it a ws pool wants.

The path is compared without the query string, so `/app/{key}?protocol=7&client=js`
matches and a wrong key is a `404` on the handshake, before PHP sees it.

## The WebSocket protocol

The `sconcur.ws` section. How the pool works is in [websocket.md](websocket.md).

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_WS_APP_KEY` | `` (empty) | the public key; the browser carries it in the path |
| `SCONCUR_WS_APP_SECRET` | `` (empty) | signs channel subscriptions; http and ws workers only |
| `SCONCUR_WS_PATH_PREFIX` | `/app` | the part of the connection path before the key |
| `SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS` | `120` | how long a client may stay silent before it should ping |
| `SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION` | `100` | channels one connection may hold |
| `SCONCUR_WS_CLIENT_EVENTS` | `false` | allow `client-*` events on private/presence channels |
| `SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE` | `60` | rate limit for them, per connection |
| `SCONCUR_WS_BUS_DRIVER` | `amqp` | `amqp`, or `local` — which delivers nothing between processes and is for tests |
| `SCONCUR_WS_BUS_DSN` | `${SCONCUR_RABBITMQ_DSN}` | the broker the bus runs on |
| `SCONCUR_WS_BUS_EXCHANGE` | `sconcur.ws` | the fanout exchange every worker binds to |
| `SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS` | `5.0` | the subscriber's heartbeat; also bounds the graceful stop |
| `SCONCUR_WS_BUS_REOPEN_BACKOFF_MS` | `1000` | pause before reopening a failed subscriber |
| `SCONCUR_WS_PRESENCE_STORE` | `auto` | `memory`, `cache`, or `auto` — decided by the pool size |
| `SCONCUR_WS_PRESENCE_TTL_SECONDS` | `3600` | how long a channel's member list survives with no change |
| `SCONCUR_WS_PRESENCE_CACHE_PREFIX` | `sconcur:ws:presence` | key prefix of the cache store |

`SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS` is not a network tuning knob. The bus subscriber only
gets control back on a delivery or on this timeout, and that is when it notices its last
connection is gone and stands down — which is what lets the server's graceful shutdown
finish. It therefore has to stay well below the group's `shutdownTimeoutMs`.

`SCONCUR_WS_PRESENCE_STORE` set to `memory` is correct only while there is one process.
Under a pool it is not incomplete but wrong, every worker answering with its own
subscribers — `auto` picks `cache` there, and an explicit `memory` is reported by the start
command.

## The task pool

How the pool works is in [task-pool.md](task-pool.md).

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_TASKS_CONTROL_KEY` | `sconcur:tasks:control` | the cache key `stop` and `restart` reach the pool through |
| `SCONCUR_TASKS_LOCK_PATH` | `storage/sconcur/runtime/tasks.lock` | flock path; keeps a second copy of a task from starting |
| `SCONCUR_TASKS_MEMORY_MB` | `256` | process memory limit; past it, an exit with `EXIT_RESTART` |
| `SCONCUR_TASKS_SLEEP_CHUNK_MS` | `250` | how finely a pause is cut, that is how fast the pool notices a signal |
| `SCONCUR_TASKS_PREEMPTION_QUANTUM_MS` | `1000` | automatic coroutine switching; `0` — off |
| `SCONCUR_TASKS_REPORT_TICKS` | `true` | show the ticks in the panel's `consumers` section |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS` | `20` | how long to wait for the running ticks before the group is unwound |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS` | `30000` | how long the master waits for the pool's worker; must exceed the previous one |

The pool's group is one worker and declares `restartPolicy: on-failure` rather than
inheriting the master's `always`, so that a `sconcur:tasks:stop`, which exits zero, is not
undone by a replacement within the second.
