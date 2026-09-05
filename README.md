English | [Русский](README.ru.md)

# SConcur Laravel

Laravel integration for [SConcur](https://github.com/sprust/sconcur): a concurrent HTTP
worker and a coroutine-scoped application.

`AsyncApplication` goes into `bootstrap/app.php` as a subclass of
`Illuminate\Foundation\Application`. Inside a worker, every fiber gets its own
`request`, `auth`, `session`, `cookie`, config overlay, current route, locale,
`View::share` and `defer`. Outside a coroutine nothing changes: one caller, one instance.

The `sconcur_mysql` connection gives the ORM non-blocking MySQL and per-coroutine
transactions (see "Database"). The PDO-backed `mysql` connection has a transaction limit
of its own — see "Transactions on the PDO connection".

## Why

SConcur runs every HTTP request in its own PHP Fiber, concurrently, in one process.
Octane's model — cloning `$app` and swapping the global container — is not fiber-safe
under that kind of concurrency. This package keeps request state in the **coroutine
context** instead, swapping no global state and cloning no application.

## Installation

### Requirements

| Component | Version | What for |
|---|---|---|
| PHP | 8.4, NTS | |
| `ext-msgpack` | 3.0.1 | every payload crossing the PHP↔extension boundary; a hard requirement of `sconcur/sconcur`, enforced by composer |
| the `sconcur` extension | 0.12.2 | exactly the `sconcur/sconcur` version; installed separately (step 2) |
| `ext-pcntl` | — | graceful shutdown of the master and of every long-lived worker |
| MySQL | 8.4 | only for the `sconcur_mysql` connection |
| RabbitMQ | 4.1 | only for the `sconcur_rabbitmq` queue |

The `.so` and the PHP side cross a protocol boundary that changes with the version, so
`sconcur/sconcur` is pinned exactly (`0.12.2`) rather than with a caret, and the
extension has to match it exactly: a version that drifted is rejected on load rather
than working somehow.

### 1. The package

```bash
composer require sconcur/laravel
```

`SConcur\Laravel\SConcurServiceProvider` is found by auto-discovery (`extra.laravel.providers`
in the package's `composer.json`) — there is nothing to add to `bootstrap/providers.php`.
It registers the artisan commands, the `sconcur_rabbitmq` queue driver, the
`sconcur_mysql` database driver, the task pool and the coroutine adapters.

### 2. The `sconcur.so` extension

The extension is in no registry, PECL included — it ships as a release asset. The
command does not take the version from its argument but from
`Extension::REQUIRED_EXTENSION_VERSION`, so the downloaded file is guaranteed to pass the
check on load.

```bash
php artisan sconcur:extension:load "$(php-config --extension-dir)/sconcur.so"
echo "extension=sconcur.so" > "$(php-config --ini-dir)/sconcur.ini"
php artisan sconcur:extension:status
```

The argument is the destination path, and its directory must exist. Without an argument
the file lands in `base_path('servers/sconcur')`, and the extension then has to be
enabled with a flag (`php -d extension=servers/sconcur/sconcur.so`): fine for a look,
not for the workers the master spawns itself.

In an image it is one instruction after `composer install`:

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > "$(php-config --ini-dir)/sconcur.ini"
```

To install the extension **before** `composer install` — so that resolving the
dependencies already runs with the `.so` loaded — read the version out of `composer.lock`
and download the asset directly; [docker/php/Dockerfile](docker/php/Dockerfile) is a
working example.

`sconcur:extension:status` prints the required and the installed version and exits `1`
when they disagree, which makes it a usable deploy check.

### 3. The config

```bash
php artisan vendor:publish --tag=sconcur-laravel
```

Publishing is mandatory. The package does not merge its config into the application, so
the published file is the whole of `config('sconcur')`: the application owns every value,
defaults included. Merging would leave the package's own values standing behind the
application's file, so a key the application deleted would quietly come back — and the
package would have to carry defaults for things only the application can know, such as
which queues to consume and with what weight. Without the file, the commands say so
rather than running on an empty array.

What the package ships is a skeleton: whatever is true of any application. The details —
your queues, their weights, the process counts — live in the published file.

The minimum in `.env` for the master to come up; the full list is in "Configuration (ENV)":

```dotenv
SCONCUR_HTTP_NAME=my-app
SCONCUR_HTTP_ADDRESS=0.0.0.0:28080
SCONCUR_HTTP_WORKER_COUNT=2

# telemetry panel: an empty token turns it off
SCONCUR_HTTP_PANEL_PORT=28081
SCONCUR_HTTP_ADMIN_TOKEN=change-me
SCONCUR_PANEL_HOST=http://127.0.0.1:28081/api/stats

# the consumer pool: below 1 leaves the group out of the master config entirely
SCONCUR_RABBITMQ_WORKER_COUNT=0
```

### 4. `bootstrap/app.php`

The one edit the application has to make: the application must be an `AsyncApplication`,
not `Illuminate\Foundation\Application`. Without it `request`, `session`, `auth` and
`cookie` stay process-wide singletons, and two requests running as coroutines in one
process read each other's state.

```php
<?php

use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use SConcur\Laravel\Foundation\AsyncApplication;

return (new ApplicationBuilder(new AsyncApplication(dirname(__DIR__))))
    ->withKernels()
    ->withEvents()
    ->withCommands()
    ->withProviders()
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        //
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        //
    })
    ->create();
```

There is nothing to reimplement here: the skeleton's `Application::configure()` is
exactly `new ApplicationBuilder(new static($basePath))` followed by those four `with*`
calls, and the builder takes a ready instance. Everything that came after `configure()`
in your file stays as it was.

### 5. Runtime directories

The master keeps its lock, its state file, its telemetry socket and its logs in
`storage/sconcur`. The paths are set in the published config (`master.runtimeDir` and
`master.logDir`) and point here by default:

```bash
mkdir -p storage/sconcur/runtime storage/sconcur/logs
```

Both must be writable by the user the master and its workers run as. If the application
is deployed by rolling out a new release directory, its `storage` is shared as usual —
and a lock and a state file that survive the release is exactly what is wanted.

### 6. The `sconcur_mysql` connection (optional)

Non-blocking MySQL is switched on where any Laravel connection is chosen — in
`config/database.php` and `DB_CONNECTION`. Details and limits are in "Database".

```php
// config/database.php
'connections' => [
    'sconcur_mysql' => [
        'driver'         => 'sconcur_mysql',
        'host'           => env('DB_HOST', '127.0.0.1'),
        'port'           => env('DB_PORT', '3306'),
        'database'       => env('DB_DATABASE'),
        'username'       => env('DB_USERNAME'),
        'password'       => env('DB_PASSWORD'),
        'charset'        => 'utf8mb4',
        'collation'      => 'utf8mb4_unicode_ci',
        'prefix'         => '',
        'strict'         => true,
        'max_open_conns' => 20,
    ],
],
```

Do not drop the stock `mysql` connection from the config: it is what serves whatever
needs a real PDO object — `schema:dump` and the `database` queue driver. And check
`batching` and `failed` in `config/queue.php`: those must name the connection as `null`
rather than through `env('DB_CONNECTION')` — `null` follows `database.default`, and then
`failed_jobs` does not drift away from what everything else uses.

### 7. The `sconcur_rabbitmq` queue (optional)

```php
// config/queue.php
'connections' => [
    'sconcur_rabbitmq' => [
        'driver' => 'sconcur_rabbitmq',
        'queue'  => env('SCONCUR_RABBITMQ_QUEUE', 'default'),
        'dsn'    => env('SCONCUR_RABBITMQ_DSN'),   // amqp://user:pass@host:5672/%2f
    ],
],
```

The consumer pool comes up on a non-zero `SCONCUR_RABBITMQ_WORKER_COUNT`, and the queues
it reads are listed in `sconcur.queue.rabbitmq.queues` of the published config.

Declaring the queues is mandatory and belongs on every install and deploy path — neither
the publishing driver nor the consumer creates any topology:

```bash
php artisan sconcur:rabbitmq:declare
```

Skipping it means losing jobs silently (a publish goes to the default exchange on a
routing key nothing is bound to) and spinning the pool through a restart loop on a `404`.
The details are in "Declaring the queues is mandatory".

### 8. Running it

The master is one process holding every pool: `http`, `rabbitmq` and `tasks`. It is also
what the supervisor starts:

```ini
[program:sconcur-master]
command=php /srv/app/artisan sconcur:servers:master:start
autostart=true
autorestart=true
stopsignal=TERM
; the master forwards SIGTERM to its groups and waits out their shutdownTimeoutMs — up
; to 30 s for the task pool. The default 10 s would kill the master mid-drain and make
; every graceful stop below it pointless.
stopwaitsecs=40
```

There is no php-fpm in this picture: HTTP is served by SConcur itself, and nginx in front
of it is a reverse proxy.

```nginx
location / {
    proxy_pass http://127.0.0.1:28080;

    # 1.0 is the default and cannot carry a chunked response
    proxy_http_version 1.1;

    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

New code is rolled out with `sconcur:servers:master:reload`: a rolling restart of the
workers with the master left up. A single group is updated with `--group=http`.

### 9. Checking it

```bash
php artisan sconcur:extension:status        # ready: yes
php artisan sconcur:servers:master:status   # running: pid=… workers=… groups=…
curl -i http://localhost/                   # answered by a SConcur worker already
```

`sconcur:servers:master:status` prints one line per group: how many workers it has up and
on what script. An empty group list means the config is not published or its `groups` is
empty; the `rabbitmq` group is absent whenever `SCONCUR_RABBITMQ_WORKER_COUNT` is below
one — that is the supported way to turn it off.

All of it can be watched live on the demo application in this repository — see "The demo
application".

## Layout

```
config/sconcur.php        — the config (panel_host, scoped_services, master + groups, queue, ws, tasks)
src/SConcurServiceProvider — the provider (commands + wiring the adapters into the worker)
src/Console/              — artisan commands
src/Servers/              — MasterRunner (a wrapper over SConcur\Worker\MasterCli)
src/Queue/Rabbitmq/       — the queue driver and the consumer pool (Connector, Queue, Job, ConsumerRunner)
src/Database/Mysql/       — the sconcur_mysql connection (Connector, Connection, Dsn, TransactionStack)
src/Tasks/                — the periodic task pool (TaskPool, TaskPoolController, TaskRegistry,
                            CooperativeSleeper, TaskPoolTelemetry + TaskPoolMetrics)
src/Tasks/Control/        — the control channel through the cache (stop/restart from another container)
src/Http/                 — HttpServerRunner + LaravelHttpHandler (build + serve)
src/Ws/                   — the WebSocket pool (WsServerRunner, ConnectionHandler,
                            ConnectionRegistry, Protocol, Auth, Bus, Presence, Broadcasting)
src/Foundation/           — AsyncApplication, ScopedService, ScopedServiceProxy
src/Config/               — AsyncConfig (a per-coroutine config()->set overlay)
src/Events/               — AsyncDispatcher (per-coroutine defer())
src/Routing/              — AsyncRouter (per-coroutine current route/request)
src/Translation/          — AsyncTranslator (per-coroutine locale)
src/View/                 — AsyncViewFactory (per-coroutine View::share)
docs/                     — the specifications and the design

demo/                     — the demo application the master serves (see demo/README.md)
workbench/                — the testbench application the tests run against
tests/                    — the package's tests
docker/                   — the images, nginx and supervisor of the development environment
```

The application is coroutine-scoped always, with no switch and no mode detection. The
adapters are installed in every process, and the container always resolves
`request`/`session`/`auth`/`cookie` out of the coroutine context.

Outside a coroutine this costs nothing: the context collapses to the process root, that
is to one store for one caller — which is exactly what the stock implementations are. All
request state — `request`, `auth`, `session`, `cookie`, the config overlay, the current
route, the locale, `View::share`, `defer` — lives in the coroutine context.

The coroutine context comes from the library: `SConcur\Context\Context::current()`
(`find/has/set/forget`). Its semantics are in
`vendor/sconcur/sconcur/docs/coroutine-context.ru.md`.

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

## Transactions on the PDO connection (important)

This is about the ordinary `mysql` connection. `sconcur_mysql` has no such limit — see
"Database".

While a coroutine holds a transaction on blocking PDO, control must not go to another
coroutine: PDO is shared per process, and the neighbour reaching the same physical
connection either lands inside your transaction or closes it. Isolating the transaction
counter does not fix that, which is why no separate DB methods were added.

The problem is not `await` as such but any coroutine switch. These cause one:

- any call going into the extension — Mongo, the SQL feature, the HTTP client, AMQP,
  `Sleeper`;
- `WaitGroup` — both when starting child coroutines and when waiting for them;
- preemptive switching by quantum (`preemption_quantum_ms` of the task pool): it switches
  even pure PHP code that calls nothing outside itself;
- `Fiber::suspend()` in somebody else's code — inside a package you called, say.

In practice, the only transaction that is safe on PDO under concurrency is one that does
nothing but SQL against that same PDO, and only with preemption off (verified: 30 out of
30 concurrent nested transactions). Everything else goes before `beginTransaction`, after
`commit`, or into a queue.

## Database (`sconcur_mysql`)

A Laravel connection with SConcur's SQL feature behind it instead of PDO. A statement goes
into the extension while the calling coroutine is suspended, so concurrent handlers in
one process do not wait for each other on a shared blocking handle. Outside a coroutine
the same calls work synchronously.

`Connection` extends `Illuminate\Database\MySqlConnection`, so the grammars, the schema,
the post-processor and `instanceof MySqlConnection` all stay in place; only the methods
that would need a PDO object are replaced. They all still go through `Connection::run()`
— timing, `QueryExecuted`, the query log and the wrapping into `QueryException` work as
usual.

### Configuration

```php
// config/database.php
'sconcur_mysql' => [
    'driver'   => 'sconcur_mysql',
    'host'     => env('DB_HOST'),
    'port'     => env('DB_PORT'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'strict'   => true,
    'max_open_conns' => 20,
],
```

`charset`, `collation`, `timezone` and `strict`/`modes` travel in the DSN rather than as
separate `SET` statements after connecting. The first three are connect options the
extension knows by name; `sql_mode` — and anything under `dsn_params` — is what this DSN
format says an unrecognised parameter is: a session system variable, issued as one `SET`
on every connection the pool opens, after the driver's own setup and therefore winning
over it. `unix_socket` is honoured too, as `unix(/path/to.sock)`.

`parseTime` is not sent, and would do nothing if it were: the extension accepts it and
ignores it, and `DATE`/`DATETIME`/`TIMESTAMP` always arrive RFC3339.

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_DB_TIMEOUT_MS` | `30000` | deadline for one statement; for a cursor, for its whole life |
| `SCONCUR_DB_MAX_OPEN_CONNS` | `20` | the pool size in the extension; `0` is not "no limit", it is the extension's own 32 |
| `SCONCUR_DB_MAX_IDLE_CONNS` | `0` | accepted and not applied: the pool keeps every idle connection up to the cap. It still keys the pool, so two values mean two pools |
| `SCONCUR_DB_CONN_MAX_LIFETIME_MS` | `0` | connection lifetime; `0` means no limit |

A bounded pool is not caution: every concurrent statement takes a connection of its own,
so an unbounded pool walks a fan-out straight into the server's `max_connections`
(MySQL error 1040).

### Which connection the application uses

There is no runtime choice here, deliberately. The SQL feature works synchronously too —
outside a coroutine the same calls simply suspend nothing — so the connection is chosen
where any Laravel application chooses it: `DB_CONNECTION` and `database.default`.
Verified: migrations, `Schema::create` with indexes, `hasTable` and `getColumnListing`
all work through this driver.

Keep the PDO-backed `mysql` connection in the config for whatever needs a real PDO object:
`schema:dump` calls `mysqldump` past the connection, and the `database` queue driver asks
PDO for the driver name and version.

Two more places in `config/queue.php` — `batching` and `failed` — must name the connection
as `null` rather than through `env('DB_CONNECTION')`: `null` follows `database.default`,
and then `failed_jobs` does not drift away from what everything else uses.

### Transactions

The nesting level lives not in a property of the connection (one object serves every
coroutine) but in `TransactionStore`: inside a coroutine that is the coroutine context,
outside one it is an array of the store's own rather than the root context. The difference
matters: the root context is never released and is read through by every coroutine, so a
transaction opened before the first fiber appeared would be inherited by every request,
message and task at once. The first level is a real `BEGIN` of the feature, the ones above
it are savepoints, the same as on PDO.

- Siblings do not see each other: concurrent requests and jobs are neighbours in the
  context tree, not ancestors, so one's transaction is invisible to another.
- A child coroutine inherits the transaction, and its queries go into it. Otherwise a
  `WaitGroup` of five `UPDATE`s inside `DB::transaction()` would quietly leave as five
  autocommits past it.
- Whoever opened a transaction closes it. A root-level `commit()`/`rollBack()` from
  another coroutine throws: it could commit the shared object, but the owner record in
  the context would stay put, pointing at a dead transaction.
- A nested level is opened and closed by the child coroutine itself. Savepoint names come
  from a counter shared per transaction rather than from the depth: by depth, two sibling
  coroutines would produce one name, and MySQL drops the previous savepoint on a repeated
  `SAVEPOINT`.

What remains: an unread cursor (`cursor()` interrupted before the end) holds the
transaction's connection, and the next command of any coroutine inside it will wait for
that. For a fan-out inside a transaction use `select()`/`get()`, which read the result set
whole.

`afterCommit` and `dispatchAfterCommit` are correct under concurrency: `db.transactions`
is a `CoroutineTransactionsManager`, which keeps one framework manager per coroutine and
only routes to the right one. It is registered always rather than by process type: outside
a coroutine it has one manager of its own and behaves indistinguishably from the stock
one. Without it, a single per-process singleton would key its records by connection name
rather than by whoever opened the transaction, and one coroutine's commit would run a
neighbour's `afterCommit` while that neighbour's transaction was still open. This is not
theory: `Model::saveOrFail()` is a transaction, so creating a model the ordinary way
already lands there.

### Differences from PDO

- Types. PDO with emulation returns everything as strings; the extension normalizes:
  integers → `int`, `FLOAT`/`DOUBLE` → `float`, `DECIMAL` → string, `NULL` → `null`.
  Eloquent hides that; a strict `===` against a string in application code does not.
  `TINYINT(1)` is an `int` rather than a `bool`, and an unsigned `BIGINT` above
  `PHP_INT_MAX` arrives as a decimal string, because it does not fit a signed 64-bit int.
- Dates. `DATE`/`DATETIME`/`TIMESTAMP` arrive as RFC3339 (`2026-09-05T10:17:40Z`), not as
  the `Y-m-d H:i:s` string PDO gives. Eloquent is unaffected — `Model::getDateFormat()`
  does not match, so `asDateTime()` falls through to `Date::parse()`, which reads it — but
  a raw `select()` value handed straight to something expecting the PDO spelling is not.
- An `UPDATE` answers with the rows it **matched**, not the rows it changed. The driver
  negotiates `CLIENT_FOUND_ROWS` and PDO does not, so `DB::update()` on a row already
  holding the new value returns 1 here and 0 there. Code reading that count as "did
  anything actually change" is reading something else. There is no switch: the flag is
  negotiated in the handshake, `clientFoundRows=false` in the DSN is refused rather than
  applied, and `ROW_COUNT()` answers the same number. The two connections can only be
  made to agree from the other side: `'options' => [PDO::MYSQL_ATTR_FOUND_ROWS => true]`
  on the `mysql` entry puts PDO on the same flag. Otherwise ask the question in the
  statement — exclude the rows that would not change, and matched is changed:

  ```php
  DB::table('notes')
      ->where('id', $id)
      ->whereRaw('NOT (title <=> ?)', [$title])
      ->update(['title' => $title]);
  ```

  `<=>` is the null-safe comparison, so a `NULL` column is compared rather than dropped
  the way `<>` would drop it.
- `getPdo()`/`getReadPdo()` throw: there is no PDO object. Code that needs PDO works
  through the `mysql` connection.
- `selectResultSets()` is not supported: the feature returns one result set per query.
- Rows always arrive as `stdClass` — that is Laravel's default `fetchMode`, and the
  connection does not let it be changed anyway.
- **Column order within a row is not guaranteed.** PDO returns them in `SELECT` order;
  here a row crosses the PHP↔extension boundary as a msgpack map, and a map does not preserve key
  order — so two identical queries in a row give different field orders. Reading by name
  works as usual, so the ORM, `->id`, `->toArray()` and `where` never notice. What does
  notice is whatever relies on the order: `array_values($row)`, destructuring
  `[$a, $b] = array_values(...)`, `fputcsv` of a row as-is, comparing two rows with `==`
  on arrays, and `json_encode` of an API response — the JSON keys will shuffle from one
  request to the next. If the order matters, set it yourself: list the fields when
  building the response rather than handing over the whole row.
- Read/write splitting (`read`/`write`/`sticky`) is not supported — the feature has one
  DSN.
- `pretend()` keeps its flag in the shared object: it must not be used in a coroutine
  runtime.
- `schema:dump` calls `mysqldump` past the connection — that is not about the driver.
  Migrations do work through it: `Schema` goes through the same `select`/`statement`.
- The `database` queue driver (`Illuminate\Queue\DatabaseQueue`) does not work on this
  connection: it asks PDO for the driver name and version. An application keeping its
  queue in a table has to name a connection for it explicitly.

Anything a connection is not named for follows `database.default`: `Auth` through the
eloquent provider, models without a `protected $connection`, the framework's own tables.
A model that needs different storage names its connection explicitly.

### Delay and confirms (`config/queue.php`)

`confirm_publishes` turns on a confirm for every publish and `confirm_timeout_seconds` is
how long to wait for it; a delayed publish is confirmed always, regardless of both.

## Development

The repository carries an environment and a demo application of its own, so the package
can be run rather than only read:

```bash
make setup
```

After that the demo answers on `http://localhost:48081` (the port is `APP_PORT` in
`.env`). What it shows and what is worth trying by hand is in
[demo/README.md](demo/README.md).

### What comes up

| Container | Role |
|---|---|
| `scl-nginx` | the only published entry point; proxies to the `http` pool |
| `scl-php` | CLI only: composer, artisan, phpunit, the analyzers. There is no php-fpm here — HTTP is served by SConcur itself |
| `scl-workers` | supervisor, and under it the SConcur master with the `http`, `rabbitmq` and `tasks` groups |
| `scl-mysql` | MySQL 8.4, data in `tmpfs` — wiped when the container is recreated |
| `scl-rabbitmq` | RabbitMQ 4.1 with its panel, in `tmpfs` as well |

The `sconcur.so` extension is baked into the image: `docker/php/Dockerfile` reads the
`sconcur/sconcur` version out of `composer.lock` and downloads the matching release asset.
That is why `composer.lock` is committed — without it a fresh clone has nothing to pin
against. The library version is pinned exactly rather than with a caret: the `.so` and the
PHP side cross a protocol boundary that changes with the version.

`composer.lock` is produced by a throwaway container (`make composer-lock`), not by the
project's own image: the image cannot build without the lock it reads. The platform the
resolution targets is set by `config.platform` in `composer.json`.

### Commands

```bash
make up / make stop / make restart    # the environment
make demo-art c=...                   # artisan of the demo application
make workers-art c=...                # the same artisan inside the workers container
make queues-declare                   # declare the queues the consumer pool reads
make sconcur-status                   # master status: groups and workers
make sconcur-reload                   # rolling restart of the pools, master stays up
make tasks-stop / make tasks-restart  # driving the task pool from another container
make check                            # cs-fixer, phpstan, tests
make test c=--filter=DsnTest          # a single test
```

The tests need the environment up: they load `sconcur.so`, and the integration ones talk
to the live MySQL and RabbitMQ.

### The tests and the demo are different applications

`workbench/` lives under `orchestra/testbench` and belongs to the tests. `demo/` is a
separate minimal application with a `bootstrap/app.php` of its own, because it needs
`AsyncApplication` while testbench builds `Illuminate\Foundation\Application` itself. It
has no `composer.json`: `demo/vendor` is a symlink to the root `vendor` and its classes
are autoloaded through the root's `autoload-dev`. One install, one lock, and the package
and the application demonstrating it cannot drift apart.

## Configuration (ENV)

Every value of `config/sconcur.php` comes from ENV. The defaults below are the package's,
from the skeleton; in the published file the application sets its own.

### General

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_PANEL_HOST` | `http://127.0.0.1:28081/api/stats` | where the dashboard reads the master's stats from |

The coroutine-scoped application has no switch and no mode detection: the provider
installs the adapters in every process.

### The master (supervisor)

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

### The `http` group

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_HTTP_WORKER_COUNT` | `2` | workers in the group (0 = one per CPU) |

`workerCount` is a key of the group, not of the master, hence a table of its own.

### The HTTP server (the `server` block of the `http` group)

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

### Groups (SConcur 0.12)

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

### The `ws` group

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_WS_WORKER_COUNT` | `0` | workers in the group; below 1 leaves the group out of the config |

### The WebSocket server (the `server` block of the `ws` group)

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

### The WebSocket protocol (`sconcur.ws`)

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

## Queue (`sconcur_rabbitmq`)

A Laravel queue driver over SConcur's AMQP feature, plus a consumer pool that reads queues
as coroutines in one process instead of one blocking `queue:work` per worker. The win is
on the consumer side: both `ext-amqp` and `php-amqplib` hold the PHP thread on reading the
queue, whereas here only the coroutine itself is suspended — so one process carries
several queues, and a slow job costs one message rather than a worker.

### Declaring the queues is mandatory

A queue appears by itself on neither side: the driver declares nothing when publishing,
and neither does `QueueConsumer`. Topology belongs to its owner, and a consumer that
re-declared somebody else's queue with its own flags would drop the channel with a `406`
instead of reading. So `sconcur:rabbitmq:declare` has to run before the first publish and
before the pool starts, and it belongs on every install and deploy path rather than being
run by hand once. In this repository it is the `make queues-declare` target, called by
`make setup`; in an application its place is on every install and deploy path.

What happens if it is skipped:

- a publish goes to the default exchange with a routing key equal to the queue name, and
  the broker silently discards a message whose routing key nothing is bound to. There is
  no error — the jobs are simply lost. There is one exception: a delayed publish always
  goes through `publishConfirmed`, so it throws `UnroutableMessageException`;
- the pool doing `basic.consume` on a queue that does not exist gets
  `SConcur\Exceptions\Amqp\QueueException` reading
  `Server channel error: 404, message: NOT_FOUND - no queue 'default' in vhost '/'`.
  The worker exits `1`, the master brings up a replacement, and round it goes with growing
  backoff: the pool reads nothing, and in the telemetry panel its group stands with no
  workers.

The command declares what is listed in `sconcur.queue.rabbitmq.queues`, with the flags
`durable`, not `exclusive`, not `autoDelete` and no arguments — the same ones
`vladimir-yuldashev/laravel-queue-rabbitmq` uses (see "Compatibility"). Running it again
is harmless: declaring an existing queue with the same flags changes nothing, which is why
it is kept on the deploy path without checking whether it has run before.

The wait queues are none of its business: they are created by the delayed publish that
needs them — see "The connection".

### Compatibility

The wire format is not ours: the body, the message properties and the attempts header are
exactly what `vladimir-yuldashev/laravel-queue-rabbitmq` writes. A job sent by either
driver is read and executed by the other — verified both ways.

Three things hold that together, and none of them can be changed unilaterally:

- the attempt counter lives in the `laravel.attempts` header rather than in `x-death`;
  `Worker::process()` builds `maxTries` and the `failed_jobs` record on it;
- the queue is declared with the same flags — `durable`, not `exclusive`, not
  `autoDelete`, no arguments; a mismatch gives a `406`, which closes the channel;
- a publish goes to the default exchange with a routing key equal to the queue name.

### The connection

```php
// config/queue.php
'sconcur_rabbitmq' => [
    'driver'    => 'sconcur_rabbitmq',
    'queue'     => env('RABBITMQ_QUEUE', 'default'),
    'dsn'       => env('SCONCUR_RABBITMQ_DSN'),   // amqp://user:pass@host:5672/%2f
],
```

AMQP has no delayed publish: `later()` and `release()` go through a queue nobody reads,
which sends the message back on TTL. A queue per delay rather than one queue with
per-message TTL, because a classic queue only expires from its head: the TTL is on the
queue, so every message inside it has one deadline and the head holds nobody up.

The wait queue is created by the very publish that needs it and is named after the exact
delay — `<queue>.wait.<ms>`. `vladimir-yuldashev/laravel-queue-rabbitmq` is built the same
way. That is what gives the precision: a ladder of fixed steps declared up front serves
only the delays built into it and rounds every other one to them, whereas a queue for an
exact delay holds exactly that. There is nothing to clean up either: `x-expires` tells the
broker to drop a wait queue that has gone unused for twice its delay, and the
re-declaration on every retry is what keeps alive the one still needed.

A delayed publish always goes through `publishConfirmed`, whatever the connection settings
say: an ordinary publish on a routing key nothing is bound to is silently discarded by the
broker — while `publishConfirmed` is mandatory by default and throws
`UnroutableMessageException`.

### The consumer

The pool is a group of the master, so it lives under the same supervisor as HTTP and
reports into the same telemetry panel (the `consumers` section).

```
php artisan sconcur:rabbitmq:declare
php artisan sconcur:servers:rabbitmq:start --queues='[{"name":"default","coroutineCount":8}]' --prefetchCount=1
```

Handling goes through `Illuminate\Queue\Worker::process()` — the job events, `maxTries`,
`backoff` and `failed_jobs` come ready-made. `Worker::daemon()` is not used: it is a
strictly sequential loop, one job at a time, and its `sleep()` blocks the process.

Writing to `failed_jobs` is done not by `Worker` but by the `queue:work` command the pool
replaces — so `ConsumerRunner` attaches the same `JobFailed` listener itself.

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_RABBITMQ_WORKER_COUNT` | `0` | processes in the pool; below `1` the group does not reach the master config at all |
| `SCONCUR_RABBITMQ_QUEUE` | `default` | the queue the pool reads |
| `SCONCUR_RABBITMQ_QUEUE_CONSUMERS` | `1` | that queue's weight — how many consumers it gets |
| `SCONCUR_RABBITMQ_PREFETCH_COUNT` | `1` | unacknowledged messages per consumer |
| `SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS` | `0` | deadline for one message in the handler; `0` — none |
| `SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE` | `false` | requeue a failed message instead of dead-lettering it |
| `SCONCUR_RABBITMQ_MAX_MESSAGES` | `0` | drain and exit after N messages |
| `SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS` | `0` | drain and exit after N seconds |
| `SCONCUR_RABBITMQ_MAX_MEMORY_BYTES` | `0` | drain and exit on heap size |
| `SCONCUR_RABBITMQ_CONNECTION` | `sconcur_rabbitmq` | the `config/queue.php` connection the jobs run on |
| `SCONCUR_RABBITMQ_MEMORY_MB` | `128` | worker memory limit, MiB |
| `SCONCUR_RABBITMQ_TRIES` | `1` | attempts before `failed_jobs` |
| `SCONCUR_RABBITMQ_BACKOFF` | `0` | delay before a retry, seconds |

A zero in `SCONCUR_RABBITMQ_WORKER_COUNT` does not mean "no workers": to the master
`workerCount: 0` is one worker per CPU (`WorkerGroup`, `Cpu::count()`). So the pool is
turned off not by a zero in the group but by the group not being in the config.

The skeleton describes one queue because that is all it can know: the queue list and the
weights are what the application sets in the published file, where `queues` may be a list
of any length. It is also what `sconcur:rabbitmq:declare` declares, reading
`sconcur.queue.rabbitmq.queues`.

A queue's weight is the analogue of the number of `queue:work` processes on it: how many
consumers it gets, each on its own channel. The handler still runs in its own coroutine
per message.

`handlerTimeoutMs` is zero by default because a deadline does not slow down the job it
catches, it refuses it — and that is for the application, which knows its jobs, to decide.

`handlerTimeoutMs` unwinds a hung handler and refuses its message; the worker takes the
next one. `WorkerOptions::$timeout` is deliberately zero next to it: the Laravel worker's
`SIGALRM` would kill the process along with every handler running beside it.

## WebSocket (`sconcur:servers:ws:start`)

The package's fourth runtime: a pool of WebSocket workers under the same master, with the
network in the extension and every upgraded connection a coroutine of its own. In detail:
[docs/websocket.ru.md](docs/websocket.ru.md) (in Russian).

The wire protocol is a compatible subset of Pusher's, so `laravel-echo` talks to it with no
client of its own, and channel authorization goes through the application's ordinary
`/broadcasting/auth` route. On the application side it is an ordinary broadcast driver:

```php
broadcast(new OrderShipped($order))->toOthers();
```

### Turning it on

```php
// config/broadcasting.php
'connections' => [
    'sconcur' => ['driver' => 'sconcur'],
],
```

```dotenv
BROADCAST_CONNECTION=sconcur

SCONCUR_WS_WORKER_COUNT=2
SCONCUR_WS_APP_KEY=some-public-key
SCONCUR_WS_APP_SECRET=some-private-secret
```

Below one worker leaves the group out of the master config entirely; `0` would mean one
worker per CPU, not none — the same rule as the consumer pool.

The bus needs a broker: `SCONCUR_WS_BUS_DSN`, which falls back to `SCONCUR_RABBITMQ_DSN`.
No `declare` command is needed — the exchange and the per-worker queues belong to the
package and are declared by it.

nginx has to pass the upgrade through, on its own location: a ws connection is long-lived,
and the timeouts of an ordinary proxy block would cut every client loose once a minute. The
repository's `docker/nginx/templates/default.conf.template` carries the block.

### The browser side

```js
window.Echo = new Echo({
    broadcaster: 'pusher',
    key: import.meta.env.VITE_SCONCUR_WS_KEY,
    wsHost: window.location.hostname,
    wsPort: 80,
    forceTLS: false,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    cluster: '',
});
```

`toOthers()` needs the event to `use Illuminate\Broadcasting\InteractsWithSockets` — that
trait is what declares the `$socket` property the caller's socket id is written into.
Without it `toOthers()` is silently a no-op.

Verified against `laravel-echo` 2.4.0 and `pusher-js` 8.6.0: connecting, subscribing to a
public channel, `join()` with its member list, and `listen()` on a broadcast event.

Worked examples of all of it — public, private and presence channels, `toOthers()`,
client events, broadcasting from a job or a task, the pool's own events, and what to
check when nothing arrives — are in
[docs/websocket.ru.md](docs/websocket.ru.md#примеры) (in Russian).

Echo is not required and neither is a Blade front end: a separate SPA on its own origin
works the same, a client can speak the protocol over a plain `WebSocket`, and any service
that can publish to the fanout exchange can broadcast into the pool. What stays with
Laravel is issuing the channel signature.

### What it does not do

- No TLS and no `permessage-deflate`: nginx terminates the first, and the extension does
  not yet do the second.
- No Pusher HTTP API, webhooks or stats — the bus took their place.
- No encrypted channels; a subscription to one is refused by name.
- No event history: a client that reconnects does not receive what it missed, and a worker
  that was away for a second misses events rather than getting a burst of them. Broadcasts
  are notifications; anything that must arrive belongs in a queue.
- `sconcur:servers:master:reload` drops ws connections. For the http pool a reload is
  invisible; here it is not, and Echo reconnects on its own.

## The task pool (`sconcur:tasks:start`)

The package's third runtime: one process, with every configured task a coroutine of a
`WaitGroup`. A task implements `tick()` and nothing else; the loop, the pauses, the
reporting and the stop belong to the pool. In detail:
[docs/task-pool.ru.md](docs/task-pool.ru.md) (in Russian).

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_TASKS_CONTROL_KEY` | `sconcur:tasks:control` | the cache key `stop` and `restart` reach the pool through |
| `SCONCUR_TASKS_LOCK_PATH` | `storage/sconcur/runtime/tasks.lock` | flock, keeps a second pool from starting |
| `SCONCUR_TASKS_MEMORY_MB` | `256` | process memory limit; past it, an exit with `EXIT_RESTART` |
| `SCONCUR_TASKS_SLEEP_CHUNK_MS` | `250` | how finely a pause is cut, that is how fast the pool notices a signal |
| `SCONCUR_TASKS_PREEMPTION_QUANTUM_MS` | `1000` | automatic coroutine switching; `0` — off (see the docs) |
| `SCONCUR_TASKS_REPORT_TICKS` | `true` | show the ticks in the panel's `consumers` section |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS` | `20` | how long to wait for the running ticks before the group is unwound |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS` | `30000` | how long the master waits for the pool's worker; must exceed the previous one |

The pool's group declares `restartPolicy: on-failure` rather than inheriting the master's
`always`: `sconcur:tasks:stop` exits zero, and under `always` the master would put a
replacement up within the second. The one exit that does want a replacement is the memory
limit, and that one is non-zero.

### How a task is written

The contract is `SConcur\Laravel\Tasks\TaskInterface`, two methods:

```php
public function name(): string;          // the name the task is addressed by in the commands and the log
public function tick(): TickResultEnum;  // one portion of work
```

There is deliberately no `stop()` in the interface: PHP cannot interrupt somebody else's
fiber, so such a method could only raise a flag the task's loop would have to remember to
check. And the task has no loop of its own — the pool simply stops calling `tick()`.

The outcome of a tick picks the next pause, and all three are configured per task:

| `TickResultEnum` | What it means | The pause from the config |
|---|---|---|
| `Worked` | there was work and it was done | `busy` |
| `Idle` | no work was found | `idle` |
| `Failed` | the tick threw | `backoff` |

```php
// config/sconcur.php
'tasks' => [
    'list' => [
        ['name' => 'cron', 'task' => CronTask::class, 'idle' => 5, 'busy' => 5, 'backoff' => 5],
    ],
],
```

Two rules the pool will not check for you:

1. **A tick has to return by itself.** There is nothing to interrupt it with, so a tick
   hung forever holds its coroutine until the hard stop deadline, which unwinds the whole
   group.
2. **A tick does not touch process-global state** — `config()->set`, `Auth`, `Request`,
   static properties. Ticks of different tasks interleave, and with preemption on, at any
   opcode boundary. A transaction is not in that category if it is on `sconcur_mysql`: the
   nesting level lies in the coroutine context and the extension pins it to a physical
   connection of its own, so a neighbouring task cannot enter it. On the PDO connection it
   is in that category — there the PDO object is one per process.

A pause goes through `CooperativeSleeper` rather than the native `sleep()`, which would
freeze the whole process, every coroutine of it at once. The wait is cut into chunks
(`SCONCUR_TASKS_SLEEP_CHUNK_MS`) for two reasons — a pause has to be interruptible once
there is nothing left to wait for, and PHP has to reach an opcode boundary regularly or
the deferred signal handler never runs at all: a process whose coroutines are all parked
in the extension executes no PHP and will not see SIGTERM.

### Driving it from outside

`sconcur:tasks:stop` and `sconcur:tasks:restart` do not look for the process and need no
pid: they put the command into the cache under `SCONCUR_TASKS_CONTROL_KEY`, and the pool
picks it up with its controller tick. That is what lets the pool be driven from another
container — the same `php-fpm`, say — without knowing where it is up.

Without `--task` the command addresses the whole pool, with `--task=NAME` a single task:
`stop` parks it and leaves the neighbours running, `restart` rebuilds it and puts it back.
A parked task holds its coroutine — otherwise there would be nobody to read the `restart`
— and waits to be brought back or for the pool to stop. When every task is parked there is
nothing left to tick and the pool finishes by itself: the same ending as a `stop` without
`--task`.

`sconcur:tasks:start` has the opposite option, `--only=NAME` (repeatable): bring up only
the tasks listed instead of every configured one.

### The pool's telemetry

The pool is the master's only worker that reports on itself. For the others the snapshots
are sent by the extension's own runtime, and here there is no such runtime — so
`TaskPoolTelemetry` reads RSS and CPU out of `/proc` once a second and writes a frame into
the collector's unix socket (a 4-byte length prefix, a JSON body). The count of live tasks
in the extension runtime (`runtimeTasks`) does not exist for such a worker and goes out as
zero.

The tick counters (`TaskPoolMetrics`) go into the snapshot's `consumers` section: a tick is
to a task what a delivery is to a consumer, so the panel's columns fill up with no change
on its side. An idle tick is counted nowhere — otherwise `Finished` would measure the
polling interval and the average duration would be the cost of an empty poll rather than
the cost of the work.

The master sums that section across every worker, so in its totals the pool's ticks add up
with the AMQP pool's deliveries. The per-group numbers stay separate either way. The
reporting is turned off with `SCONCUR_TASKS_REPORT_TICKS=false`.

## The demo application

The repository carries a demo — a minimal Laravel application served by the SConcur master
itself. It exists so the package can be looked at in operation rather than only read
about.

```bash
make setup
```

Then `http://localhost:48081` (the port is changed through `APP_PORT` in `.env`). The
details are in [demo/README.md](demo/README.md).

What the page shows:

| Block | What it shows |
|---|---|
| Master telemetry | the master's panel as-is: workers by group, how many requests and messages are in flight right now, memory, live tasks in the extension runtime. Refreshed once a second |
| Pool sizes | how many processes each pool holds and how many consumers a queue gets in each of them; applying rolls only the groups whose numbers changed |
| Concurrency | the same cooperative pause N times: as coroutines of one `WaitGroup` against a sequential run. On 20 pauses of 150 ms that is 152 ms against 3026 ms in one process |
| MySQL | Eloquent over `sconcur_mysql`, including several concurrent inserts, each in its own transaction |
| Queue | jobs through `sconcur_rabbitmq`; the results show that all of them went through a single consumer process |
| Periodic tasks | a counter the periodic task pool increments |

The demo is not a test bench: the tests run on `workbench/` under `orchestra/testbench`,
while the demo has a `bootstrap/app.php` of its own because it needs `AsyncApplication`.
It has no `composer install` of its own — `demo/vendor` is a symlink to the root `vendor`,
so the package and the application demonstrating it cannot drift apart in version.

An application installing the package the ordinary way needs none of this: there the
provider is found by auto-discovery and the config is published with
`vendor:publish --tag=sconcur-laravel`.
