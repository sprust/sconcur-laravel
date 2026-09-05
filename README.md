English | [Русский](README.ru.md)

# SConcur Laravel

Laravel integration for [SConcur](https://github.com/sprust/sconcur): a concurrent HTTP
worker and a coroutine-scoped application.

`AsyncApplication` goes into `bootstrap/app.php` as a subclass of
`Illuminate\Foundation\Application`. Inside a worker, every fiber gets its own
`request`, `auth`, `session`, `cookie`, config overlay, current route, locale,
`View::share` and `defer`. Outside a coroutine nothing changes: one caller, one instance.

The `sconcur_mysql` connection gives the ORM non-blocking MySQL and per-coroutine
transactions. The PDO-backed `mysql` connection has a transaction limit of its own — see
[Database](docs/database.md).

## Why

SConcur runs every HTTP request in its own PHP Fiber, concurrently, in one process.
Octane's model — cloning `$app` and swapping the global container — is not fiber-safe
under that kind of concurrency. This package keeps request state in the **coroutine
context** instead, swapping no global state and cloning no application.

The coroutine-scoped model (`AsyncApplication` plus per-coroutine state) is taken from
[yangusik/laravel-spawn](https://github.com/yangusik/laravel-spawn), where it sits on PHP
TrueAsync, and adapted to standard PHP plus SConcur (`Context::current()` in place of the
TrueAsync context). The worker's PSR-7 bridge follows Laravel Octane's model.

## Documentation

| Document | About |
|---|---|
| [Installation](docs/installation.md) | requirements, the extension, the config, `bootstrap/app.php`, the first run |
| [Layout](docs/layout.md) | what lies where in the repository |
| [Configuration (ENV)](docs/configuration.md) | every environment variable and its default |
| [Database](docs/database.md) | the `sconcur_mysql` connection, per-coroutine transactions, and the PDO connection's limit |
| [Queue](docs/queue.md) | the `sconcur_rabbitmq` driver and the consumer pool |
| [WebSocket](docs/websocket.md) | the ws pool: examples, the protocol, channel signatures, the bus, presence |
| [The task pool](docs/task-pool.md) | periodic tasks, a coroutine each |
| [Development](docs/development.md) | the docker environment, `make`, the tests |
| [The demo application](demo/README.md) | what the demo shows and how to run it |

Every document exists in English and in Russian; the switcher is on its first line.

## The runtimes

Four of them, all groups of one supervisor process, the SConcur master
(`sconcur:servers:master:start`), configured as `groups` in `config/sconcur.php`.

| Runtime | Foreground command | Document |
|---|---|---|
| HTTP server | `sconcur:servers:http:start` | [Installation](docs/installation.md) |
| Queue consumers | `sconcur:servers:rabbitmq:start` | [Queue](docs/queue.md) |
| WebSocket | `sconcur:servers:ws:start` | [WebSocket](docs/websocket.md) |
| Periodic tasks | `sconcur:tasks:start` | [The task pool](docs/task-pool.md) |

## Artisan commands

The master is instantiated inside the commands straight out of `config('sconcur.master')`
(through `MasterConfig::fromArray`), with no JSON path passed around.

```
sconcur:servers:master:start|stop                 # MasterRunner (the supervisor, spawns the workers)
sconcur:servers:master:status [--group=NAME]      # status: every pool or one
sconcur:servers:master:reload [--group=NAME]      # rolling restart: every pool or one
sconcur:servers:http:start                        # one HTTP server in the foreground (build + serve)
sconcur:servers:rabbitmq:start                    # the queue consumer pool in the foreground
sconcur:rabbitmq:declare                          # declare the queues the pool reads — mandatory
sconcur:servers:ws:start                          # one WebSocket server in the foreground
sconcur:tasks:start [--only=NAME]                 # the periodic task pool in the foreground
sconcur:tasks:stop [--task=NAME]                  # stop the pool or one of its tasks
sconcur:tasks:restart [--task=NAME]               # rebuild every task or one
sconcur:extension:load                            # download the .so (runs the downloader)
sconcur:extension:status                          # extension status (in-process)
```

`reload` is the only command that needs a file: the master re-reads the config from disk
in its own process, so an in-memory object never reaches it. `masterConfigPath()`
serializes the same array into `{runtimeDir}/{name}.config.json` and returns the path, so
the file the master reloads from and the config it is supervised with cannot drift apart.

The master spawns workers as `php artisan sconcur:servers:http:start --masterPid=N`
(`workerScript=artisan`, `workerArgs=[command]`). The same `http:start` also runs
standalone. The handler is coroutine-safe (per-fiber context) and requests inside one
process are handled concurrently; a multi-process production setup is `master:start`
(plus `reusePort`).

## The demo application

The repository carries a demo — a minimal Laravel application served by the SConcur master
itself. It exists so the package can be looked at in operation rather than only read
about.

```bash
make setup
```

Then `http://localhost:48081` (the port is changed through `APP_PORT` in `.env`). What the
page shows and what is worth trying by hand is in [demo/README.md](demo/README.md).

An application installing the package the ordinary way needs none of this: there the
provider is found by auto-discovery and the config is published with
`vendor:publish --tag=sconcur-laravel`.
