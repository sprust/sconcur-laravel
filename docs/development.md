English | [Русский](development.ru.md)

# Development

The repository carries an environment and a demo application of its own, so the package
can be run rather than only read:

```bash
make setup
```

After that the demo answers on `http://localhost:48081` (the port is `APP_PORT` in
`.env`). What it shows and what is worth trying by hand is in
[demo/README.md](../demo/README.md).

## What comes up

| Container | Role |
|---|---|
| `scl-nginx` | the only published entry point; proxies to the `http` pool |
| `scl-php` | CLI only: composer, artisan, phpunit, the analyzers. There is no php-fpm here — HTTP is served by SConcur itself |
| `scl-workers` | supervisor, and under it the SConcur master with the `http`, `rabbitmq`, `ws` and `tasks` groups |
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

## Commands

```bash
make up / make stop / make restart    # the environment
make demo-art c=...                   # artisan of the demo application
make workers-art c=...                # the same artisan inside the workers container
make queues-declare                   # declare the queues the consumer pool reads
make sconcur-status                   # master status: groups and workers
make sconcur-reload                   # rolling restart of the pools, master stays up
make tasks-stop / make tasks-restart  # driving the task pool from another container
make ws-check c=50                    # the ws pool end to end, with an exit code
make check                            # cs-fixer, phpstan, tests
make test c=--filter=DsnTest          # a single test
```

The tests need the environment up: they load `sconcur.so`, and the integration ones talk
to the live MySQL and RabbitMQ.

## The tests and the demo are different applications

`workbench/` lives under `orchestra/testbench` and belongs to the tests. `demo/` is a
separate minimal application with a `bootstrap/app.php` of its own, because it needs
`AsyncApplication` while testbench builds `Illuminate\Foundation\Application` itself.
Nothing is installed there: `demo/vendor` is a symlink to the root `vendor` and its classes
are autoloaded through the root's `autoload-dev` (`Demo\App\` → `demo/app/`). One install,
one lock, and the package and the application demonstrating it cannot drift apart.
