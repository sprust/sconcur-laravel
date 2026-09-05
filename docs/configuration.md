English | [Русский](configuration.ru.md)

# Configuration (ENV)

Every value of `config/sconcur.php` comes from ENV. The defaults below are the package's,
from the skeleton; in the published file the application sets its own.

## Table of contents

- [General](#general)
- [The master (supervisor)](#the-master-supervisor)
- [The `http` group](#the-http-group)
- [The HTTP server (the `server` block of the `http` group)](#the-http-server-the-server-block-of-the-http-group)
- [Groups (SConcur 0.12)](#groups-sconcur-012)
- [The `ws` group](#the-ws-group)
- [The WebSocket server (the `server` block of the `ws` group)](#the-websocket-server-the-server-block-of-the-ws-group)
- [The WebSocket protocol (`sconcur.ws`)](#the-websocket-protocol-sconcurws)

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

## The `http` group

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_HTTP_WORKER_COUNT` | `2` | workers in the group (0 = one per CPU) |

`workerCount` is a key of the group, not of the master, hence a table of its own.

## The HTTP server (the `server` block of the `http` group)

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

Not from ENV: `workerScript=base_path('artisan')`,
`workerArgs=['sconcur:servers:http:start']`, `phpArgs=[]`, and
`runtimeDir`/`logDir`=`storage_path('sconcur/runtime'|'sconcur/logs')`.

## Groups (SConcur 0.12)

One master supervises several unlike pools under one lock and one journal, so
`workerScript`, `workerCount`, `workerArgs` and `server` live not at the top level of the
config but in an element of the `groups` list.

A group's `server` block is forwarded to its workers' argv verbatim, which is why all
three commands — `http:start`, `rabbitmq:start` and `ws:start` — declare those flags:
artisan rejects what is not declared. What reads them back is `HttpServer::fromArgs`,
`QueueConsumer::fromArgs` and `WsServer::fromArgs`. Anything that is not a scalar (the queue list) the master
JSON-encodes on the way.

A run without a master has nobody to forward it, so in that case the command takes the
same `server` block out of its own group's config. The group is looked up by what it
starts rather than by name — otherwise renaming a group would quietly leave a standalone
run on the library's defaults.

## The `ws` group

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_WS_WORKER_COUNT` | `0` | workers in the group; below 1 leaves the group out of the config |

## The WebSocket server (the `server` block of the `ws` group)

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

## The WebSocket protocol (`sconcur.ws`)

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

A member list kept in one process is correct only while there is one process. Under a pool
`memory` is not incomplete but wrong, every worker answering with its own subscribers —
`auto` picks `cache` there, and an explicit `memory` is reported by the start command.
