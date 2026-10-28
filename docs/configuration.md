English | [Русский](configuration.ru.md)

# Configuration (ENV)

The values of `config/sconcur.php` come from ENV, apart from the few named below, and every
variable the package reads is listed here. The defaults are the package's, from the skeleton; in the published file the
application sets its own.

## Table of contents

- [All variables at once](#all-variables-at-once)
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
- [Redis](#redis)
- [Filesystem](#filesystem)
- [Listeners](#listeners)

## All variables at once

Every variable below with the package's default, ready to be pasted into `.env` and trimmed
to what the application changes. A variable left out of `.env` takes the same default, so
only the changed lines are needed. Four are not plain values: `SCONCUR_RABBITMQ_DSN` has no
default and the one shown is an example; `SCONCUR_WS_PATH` and `SCONCUR_WS_BUS_DSN` are built
from other variables, the way the config builds them (`.env` expands `${...}`, and only
from the lines above);
`SCONCUR_TASKS_LOCK_PATH` is left commented out, because its default is computed by
`storage_path()` and an empty value is not the default.

```dotenv
SCONCUR_PANEL_HOST=http://127.0.0.1:28081/api/stats

# The master (supervisor)
SCONCUR_HTTP_PANEL_PORT=28081
SCONCUR_HTTP_ADMIN_TOKEN=
SCONCUR_HTTP_NAME=sconcur-http-server
SCONCUR_HTTP_ROTATE_DAYS=3
SCONCUR_HTTP_LOG_TO=both
SCONCUR_HTTP_RESTART_POLICY=always
SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS=10000
SCONCUR_HTTP_RESTART_BACKOFF_MS=200
SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS=30000
SCONCUR_HTTP_WATCHDOG_TIMEOUT_MS=60000
SCONCUR_HTTP_READY_TIMEOUT_MS=30000
SCONCUR_HTTP_ACCESS_LOG=file

# The http group and its server
SCONCUR_HTTP_WORKER_COUNT=2
SCONCUR_HTTP_ADDRESS=0.0.0.0:28080
SCONCUR_HTTP_MAX_REQUESTS=0
SCONCUR_HTTP_MAX_CONCURRENCY=0
SCONCUR_HTTP_MAX_REQUEST_BODY=10485760
SCONCUR_HTTP_READ_HEADER_TIMEOUT_MS=10000
SCONCUR_HTTP_READ_TIMEOUT_MS=30000
SCONCUR_HTTP_WRITE_TIMEOUT_MS=30000
SCONCUR_HTTP_IDLE_TIMEOUT_MS=60000
SCONCUR_HTTP_HANDLER_TIMEOUT_MS=60000
SCONCUR_HTTP_SERVER_SHUTDOWN_TIMEOUT_MS=5000

# The rabbitmq group and its consumers; below 1 worker the group is left out
SCONCUR_RABBITMQ_WORKER_COUNT=0
SCONCUR_RABBITMQ_QUEUE=default
SCONCUR_RABBITMQ_QUEUE_CONSUMERS=1
SCONCUR_RABBITMQ_PREFETCH_COUNT=1
SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS=0
SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE=false
SCONCUR_RABBITMQ_MAX_MESSAGES=0
SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS=0
SCONCUR_RABBITMQ_MAX_MEMORY_BYTES=0
SCONCUR_RABBITMQ_CONNECTION=sconcur_rabbitmq
SCONCUR_RABBITMQ_TRIES=1
SCONCUR_RABBITMQ_BACKOFF=0
# no default: the broker the queue and the ws bus run on
SCONCUR_RABBITMQ_DSN=amqp://guest:guest@127.0.0.1:5672/%2f

# The ws group and its server; below 1 worker the group is left out
SCONCUR_WS_WORKER_COUNT=0
SCONCUR_WS_ADDRESS=0.0.0.0:28090
SCONCUR_WS_HANDSHAKE_TIMEOUT_MS=10000
SCONCUR_WS_IDLE_TIMEOUT_MS=0
SCONCUR_WS_WRITE_TIMEOUT_MS=30000
SCONCUR_WS_PING_INTERVAL_MS=30000
SCONCUR_WS_MAX_MESSAGE_BYTES=1048576
SCONCUR_WS_MAX_CONCURRENCY=0
SCONCUR_WS_MAX_CONNECTIONS=0
SCONCUR_WS_SHUTDOWN_TIMEOUT_MS=10000
SCONCUR_WS_PREEMPTION_QUANTUM_MS=0

# The WebSocket protocol
SCONCUR_WS_APP_KEY=
SCONCUR_WS_APP_SECRET=
SCONCUR_WS_PATH_PREFIX=/app
SCONCUR_WS_PATH=${SCONCUR_WS_PATH_PREFIX}/${SCONCUR_WS_APP_KEY}
SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS=120
SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION=100
SCONCUR_WS_CLIENT_EVENTS=false
SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE=60
SCONCUR_WS_BUS_DRIVER=amqp
SCONCUR_WS_BUS_DSN=${SCONCUR_RABBITMQ_DSN}
SCONCUR_WS_BUS_EXCHANGE=sconcur.ws
SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS=5.0
SCONCUR_WS_BUS_REOPEN_BACKOFF_MS=1000
SCONCUR_WS_PRESENCE_STORE=auto
SCONCUR_WS_PRESENCE_TTL_SECONDS=3600
SCONCUR_WS_PRESENCE_CACHE_PREFIX=sconcur:ws:presence

# The task pool
SCONCUR_TASKS_CONTROL_KEY=sconcur:tasks:control
# default storage_path('sconcur/runtime/tasks.lock'); an absolute path when set
#SCONCUR_TASKS_LOCK_PATH=
SCONCUR_TASKS_MEMORY_MB=256
SCONCUR_TASKS_SLEEP_CHUNK_MS=250
SCONCUR_TASKS_PREEMPTION_QUANTUM_MS=0
SCONCUR_TASKS_REPORT_TICKS=true
SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS=20
SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS=30000

# Filesystem
SCONCUR_FILESYSTEM_FILES=false
SCONCUR_FILESYSTEM_TIMEOUT_MS=0
```

## General

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_PANEL_HOST` | `http://127.0.0.1:28081/api/stats` | where the demo's dashboard reads the master's stats from; the package itself does not read it |

The coroutine-scoped application has no switch and no mode detection: the provider
installs the adapters in every process.

## The master (supervisor)

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_HTTP_PANEL_PORT` | `28081` | telemetry panel port (0 = off) |
| `SCONCUR_HTTP_ADMIN_TOKEN` | `` (empty) | the panel's bearer token (empty = off) |
| `SCONCUR_HTTP_NAME` | `sconcur-http-server` | server name (the lock/state/log files) |
| `SCONCUR_HTTP_ROTATE_DAYS` | `3` | log rotation, days |
| `SCONCUR_HTTP_LOG_TO` | `both` | where to log (`file`/`stdout`/`both`) |
| `SCONCUR_HTTP_RESTART_POLICY` | `always` | worker restart policy (`always`/`on-failure`/`never`) |
| `SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS` | `10000` | graceful worker stop deadline, ms |
| `SCONCUR_HTTP_RESTART_BACKOFF_MS` | `200` | initial restart backoff, ms |
| `SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS` | `30000` | maximum restart backoff, ms |
| `SCONCUR_HTTP_WATCHDOG_TIMEOUT_MS` | `60000` | how long a worker's PHP thread may stay away from the scheduler before the master kills and replaces it; `0` — off, otherwise at least `5000` |
| `SCONCUR_HTTP_READY_TIMEOUT_MS` | `30000` | a worker that dies before it says it is up, and within this long, failed to start; at least `1000` |
| `SCONCUR_HTTP_ACCESS_LOG` | `file` | where the workers write their access lines: `file` (each worker to `<name>-access-YYYY-MM-DD.log` in `logDir`, removed with the journal after `rotateDays`), `stdout` (into the master's journal; one process reads every worker's pipe, and under heavy load lines are dropped), `off`; a config without the key gets `file` too |

The last seven are the defaults of every group: a group may set any of them for itself, as
the `tasks` group does with `restartPolicy` and `shutdownTimeoutMs`.

Not from ENV: `runtimeDir`/`logDir`=`storage_path('sconcur/runtime'|'sconcur/logs')`, and
`env` — environment variables every worker gets on top of the master's, set in the worker
right after its fork (`putenv`, `$_ENV`, `$_SERVER`). A group's own `env` goes on top of
that list, and its value wins:

```php
'master' => [
    'env'    => ['LOG_CHANNEL' => 'stderr'],
    'groups' => [
        [
            'name' => 'tasks',
            'role' => 'tasks',
            'env'  => ['LOG_CHANNEL' => 'daily'], // tasks workers: LOG_CHANNEL=daily
        ],
    ],
],
```

A variable set this way is seen by `getenv()` in the worker and by what the worker starts.
The configuration the application loaded in the master is not read again in the worker, so
`env()` calls in `config/*.php` keep the values the master saw.

## Groups

One master supervises several unlike pools under one lock and one journal, so `role`,
`workerCount` and `server` live not at the top level of the config but in an element of
the `groups` list.

`sconcur:servers:master:start` builds the master in its own process once the application
has booted, and every worker is a fork of that process. A group's `role` is what its
workers run: `http`, `rabbitmq`, `ws` or `tasks`. Its `server` block is the settings of
the server or consumer each worker builds — turned into `--name=value` flags and read by
`HttpServer::fromArgs`, `QueueConsumer::fromArgs` and `WsServer::fromArgs`; anything that
is not a scalar (the queue list) goes as JSON.

The `sconcur:servers:http:start`, `sconcur:servers:rabbitmq:start` and
`sconcur:servers:ws:start` commands run a server without a master.
They declare the same flags, and without any given they take the `server` block of the
group of their role. The group is looked up by its role rather than by name — otherwise
renaming a group would quietly leave a standalone run on the library's defaults.

Every server binds its address with `SO_REUSEPORT`: the workers of a group share the port,
and the kernel spreads the connections over them. There is no setting for it.

What the application does while it boots happens once, in the master's process, and every
worker inherits it. Two things follow. No asynchronous call may be made while booting — a
`sconcur_mysql` query or a `sconcur` Redis command in a service provider starts the
extension's runtime, and a process running it cannot fork: the master refuses to start,
with the reason. And a connection opened while booting would be shared by every worker
rather than copied, so the start command closes the database and Redis connections the
application opened before it forks; each worker opens its own on its first query.
Per-worker setup belongs in a [`WorkerForked`](#workerforked) listener.

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

A worker of this group tells the master it is up before its consumer connects, after the
`WorkerForked` listeners. A broker failure at the start — a queue that is not declared, a
refused login — is therefore an ordinary restart with backoff, not a failed start, and does
not stop the master; `SCONCUR_HTTP_READY_TIMEOUT_MS` does not reach it.

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
| `SCONCUR_WS_PATH` | `/app/${SCONCUR_WS_APP_KEY}` | the exact path the upgrade is accepted on; empty accepts any |
| `SCONCUR_WS_HANDSHAKE_TIMEOUT_MS` | `10000` | how long the upgrade headers may take, ms |
| `SCONCUR_WS_IDLE_TIMEOUT_MS` | `0` | idle timeout between inbound messages (0 = off) |
| `SCONCUR_WS_WRITE_TIMEOUT_MS` | `30000` | one message write, ms |
| `SCONCUR_WS_PING_INTERVAL_MS` | `30000` | server keepalive ping cadence (0 = off) |
| `SCONCUR_WS_MAX_MESSAGE_BYTES` | `1048576` | inbound message size limit |
| `SCONCUR_WS_MAX_CONCURRENCY` | `0` | connections served at once (0 = ∞) |
| `SCONCUR_WS_MAX_CONNECTIONS` | `0` | stop after N served connections (0 = ∞) |
| `SCONCUR_WS_SHUTDOWN_TIMEOUT_MS` | `10000` | graceful stop deadline, ms |
| `SCONCUR_WS_PREEMPTION_QUANTUM_MS` | `0` | preemption quantum while serving; `0` — off |

`handlerTimeoutMs` is not among them and is hard-coded to `0`. Here it is a deadline on the
whole life of a connection rather than on one frame, so any value above zero disconnects
every client on a timer — there is no setting of it a ws pool wants.

The path is compared without the query string, so `/app/{key}?protocol=7&client=js`
matches and a wrong key is a `404` on the handshake, before PHP sees it.

`SCONCUR_WS_PREEMPTION_QUANTUM_MS` is `0`, and preemption is off — the library's own
default. With it on, the extension parks a handler on a timer, so another coroutine can run
between any two of its statements. Some PHP state belongs to the process rather than to a
coroutine — `json_last_error()`, `preg_last_error()`, `error_get_last()`, output buffers,
error handlers — and a neighbour that runs in between overwrites it: the handler reads
another connection's result. The engine checks the timer right after an internal call
returns, so a switch between `json_decode()` and `json_last_error()` is the likely case, not
a rare one. The price of `0`: a handler that computes without waiting holds the other
connections of its worker until it finishes. A quantum above zero is for a worker whose
handlers and their libraries read none of that state; the full list, with the safe form of
each, is in `vendor/sconcur/sconcur/docs/coroutine-switching.md` (Process-wide state).

The `http` and `rabbitmq` groups carry no such key and run on the same default for the same
reason. `preemptionQuantumMs` added to their `server` block turns it on: both commands
declare the flag.

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
subscribers — `auto` picks `cache` there, and an explicit `memory` is reported in the log
by every ws worker as it starts.

## The task pool

How the pool works is in [task-pool.md](task-pool.md).

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_TASKS_CONTROL_KEY` | `sconcur:tasks:control` | the cache key `stop` and `restart` reach the pool through |
| `SCONCUR_TASKS_LOCK_PATH` | `storage/sconcur/runtime/tasks.lock` | flock path prefix, one file per task (`<path>.<task>`); keeps a second copy of a task from starting |
| `SCONCUR_TASKS_MEMORY_MB` | `256` | process memory limit, against the larger of the PHP heap and RSS; past it, an exit with `EXIT_RESTART` |
| `SCONCUR_TASKS_SLEEP_CHUNK_MS` | `250` | how finely a pause is cut, that is how fast the pool notices a signal |
| `SCONCUR_TASKS_PREEMPTION_QUANTUM_MS` | `0` | automatic coroutine switching; `0` — off |
| `SCONCUR_TASKS_REPORT_TICKS` | `true` | show the ticks in the panel's `consumers` section |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS` | `20` | how long to wait for the running ticks before the group is unwound |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS` | `30000` | how long the master waits for the pool's worker; must exceed the previous one |

The pool's group is one worker and declares `restartPolicy: on-failure` rather than
inheriting the master's `always`, so that a `sconcur:tasks:stop`, which exits zero, is not
undone by a replacement within the second.

## Redis

The `sconcur` client and the `sconcur_redis` cache store read no ENV of their own. Their
settings are keys of the `redis` section of `config/database.php` and of
`cache.stores.sconcur_redis`, and the application fills them from whatever variables it
likes; the keys are in [redis.md](redis.md).

## Filesystem

How it works is in [filesystem.md](filesystem.md).

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_FILESYSTEM_FILES` | `false` | put the `File` facade on the Files feature for `copy`, `move`, `hash` and `replace` inside a coroutine |
| `SCONCUR_FILESYSTEM_TIMEOUT_MS` | `0` | the deadline of one such call, ms; `0` — none |

A `sconcur_local` disk reads no ENV of its own: its `timeout_ms` is a key of its entry in
`config/filesystems.php`.

## Listeners

The `sconcur.listeners` section maps an event class to a list of its listeners, the shape
`EventServiceProvider::$listen` takes. The service provider registers them in every process.

```php
use App\Listeners\PrepareWorker;
use App\Listeners\ReportWorkerWatchdog;
use SConcur\Laravel\Servers\Events\WorkerForked;
use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;

'listeners' => [
    WorkerForked::class => [
        PrepareWorker::class,
    ],
    WorkerWatchdogTriggered::class => [
        ReportWorkerWatchdog::class,
    ],
],
```

| Event | Raised in | When |
|---|---|---|
| `SConcur\Laravel\Servers\Events\WorkerForked` | a worker of the master | right after the fork, before the worker builds its server, consumer or task pool |
| `SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered` | the master process | the master's watchdog acts on a worker whose PHP thread stopped coming back to the scheduler |

### `WorkerForked`

The place for what has to be done once per worker rather than once per master: every
worker starts with a copy of what the master's process loaded, so a value computed while
the application booted — an id, a timestamp, a seed — is the same in all of them.
`WorkerForked::$workerContext` is the library's `SConcur\Worker\WorkerContext`: `group`,
`slot` (from `0`) and `masterPid`.

```php
namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use SConcur\Laravel\Servers\Events\WorkerForked;

class PrepareWorker
{
    public function handle(WorkerForked $workerForked): void
    {
        Log::withContext([
            'worker' => $workerForked->workerContext->group . '#' . $workerForked->workerContext->slot,
        ]);
    }
}
```

A closure does as well, registered in a service provider:

```php
Event::listen(static function (WorkerForked $workerForked): void {
    // ...
});
```

The listener runs in the worker, so it may make asynchronous calls. It runs before the
worker says it is up: an exception it lets out ends the worker, and when every worker of a
group ends that way the master stops with code `70`.

### `WorkerWatchdogTriggered`

`WorkerWatchdogTriggered::$watchdogEvent` is the library's `SConcur\Worker\WatchdogEvent`:
`event`, `group`, `slot`, `pid`, `ageSeconds` and `watchdogTimeoutMs`. One kill raises up to
three events, one per `event`: `HeartbeatLost` when the worker is sent `SIGTERM`,
`KillEscalated` when it did not exit and is sent `SIGKILL`, `KillSurvived` when the process
outlived `SIGKILL` and its slot stays down. `ageSeconds` is set on the first one only. The
event is raised by `sconcur:servers:master:start`; with no listener the master only writes
its journal and counts `watchdogKills` for the telemetry panel.

A listener that sends the kill to the error tracker:

```php
namespace App\Listeners;

use RuntimeException;
use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;

class ReportWorkerWatchdog
{
    public function handle(WorkerWatchdogTriggered $workerWatchdogTriggered): void
    {
        $watchdogEvent = $workerWatchdogTriggered->watchdogEvent;

        report(new RuntimeException(sprintf(
            'watchdog %s: worker %d of group "%s" #%d (limit %d ms)',
            $watchdogEvent->event->value,
            $watchdogEvent->pid,
            $watchdogEvent->group,
            $watchdogEvent->slot,
            $watchdogEvent->watchdogTimeoutMs,
        )));
    }
}
```

The listener runs inside the master's supervision tick, and the master supervises nothing
while it runs, so it has to be short. It must make no asynchronous call either — no
`sconcur_mysql`, `sconcur` Redis or HTTP client of the extension: the master's process
refuses one, since a process running the extension's runtime cannot fork its workers.
Whatever it throws is written to the master's journal and dropped.
