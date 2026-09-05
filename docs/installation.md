English | [Русский](installation.ru.md)

# Installation

Nine steps from an empty application to a master serving requests. Steps 6 and 7 are
optional: they add the non-blocking MySQL connection and the queue driver, and the HTTP
server runs without either. The WebSocket pool is optional in the same way and is set up
in [websocket.md](websocket.md).

## Table of contents

- [Requirements](#requirements)
- [1. The package](#1-the-package)
- [2. The `sconcur.so` extension](#2-the-sconcurso-extension)
- [3. The config](#3-the-config)
- [4. `bootstrap/app.php`](#4-bootstrapappphp)
- [5. Runtime directories](#5-runtime-directories)
- [6. The `sconcur_mysql` connection (optional)](#6-the-sconcur_mysql-connection-optional)
- [7. The `sconcur_rabbitmq` queue (optional)](#7-the-sconcur_rabbitmq-queue-optional)
- [8. Running it](#8-running-it)
- [9. Checking it](#9-checking-it)

## Requirements

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

## 1. The package

```bash
composer require sconcur/laravel
```

`SConcur\Laravel\SConcurServiceProvider` is found by auto-discovery
(`extra.laravel.providers` in the package's `composer.json`) — there is nothing to add to
`bootstrap/providers.php`. It registers the artisan commands, the `sconcur_rabbitmq` queue
driver, the `sconcur_mysql` database driver, the task pool and the coroutine adapters.

## 2. The `sconcur.so` extension

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
and download the asset directly; [docker/php/Dockerfile](../docker/php/Dockerfile) is a
working example.

`sconcur:extension:status` prints the required and the installed version and exits `1`
when they disagree, which makes it a usable deploy check.

## 3. The config

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

The minimum in `.env` for the master to come up; the full list is in
[configuration.md](configuration.md):

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

## 4. `bootstrap/app.php`

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

## 5. Runtime directories

The master keeps its lock, its state file, its telemetry socket and its logs in
`storage/sconcur`. The paths are set in the published config (`master.runtimeDir` and
`master.logDir`) and point here by default:

```bash
mkdir -p storage/sconcur/runtime storage/sconcur/logs
```

Both must be writable by the user the master and its workers run as. If the application
is deployed by rolling out a new release directory, its `storage` is shared as usual —
and a lock and a state file that survive the release is exactly what is wanted.

## 6. The `sconcur_mysql` connection (optional)

Non-blocking MySQL is switched on where any Laravel connection is chosen — in
`config/database.php` and `DB_CONNECTION`. Details and limits are in
[database.md](database.md).

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

## 7. The `sconcur_rabbitmq` queue (optional)

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
The details are in [queue.md](queue.md).

## 8. Running it

The master is one process holding every pool — `http`, `rabbitmq`, `ws` and `tasks` — and
it is what the supervisor starts:

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

A WebSocket pool needs a second `location` beside this one, with the upgrade headers and a
long read timeout — the block above would cut every socket loose once a minute. It is in
[websocket.md](websocket.md).

New code is rolled out with `sconcur:servers:master:reload`: a rolling restart of the
workers with the master left up. A single group is updated with `--group=http`.

## 9. Checking it

```bash
php artisan sconcur:extension:status        # ready: yes
php artisan sconcur:servers:master:status   # running: pid=… workers=… groups=…
curl -i http://localhost/                   # answered by a SConcur worker already
```

`sconcur:servers:master:status` prints one line per group: how many workers it has up and
on what script. An empty group list means the config is not published or its `groups` is
empty; the `rabbitmq` group is absent whenever `SCONCUR_RABBITMQ_WORKER_COUNT` is below
one — that is the supported way to turn it off.

All of it can be watched live on the demo application in this repository — see
[demo/README.md](../demo/README.md).
