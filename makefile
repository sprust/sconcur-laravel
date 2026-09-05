MAKEFLAGS += --no-print-directory

DOCKER_COMPOSE = docker compose

PHP_SERVICE     = php
PHP_CLI         = $(DOCKER_COMPOSE) exec $(PHP_SERVICE)
WORKERS_SERVICE = workers
WORKERS_CLI     = $(DOCKER_COMPOSE) exec $(WORKERS_SERVICE)

ifneq (,$(wildcard ./.env))
    include .env
    export
else
    include .env.example
    export
endif

# Everything from a fresh clone to a working demo. The order is not arbitrary: the
# image bakes sconcur.so by the version in composer.lock, so the lock has to be there
# before `build`; the queues are declared before the consumer pool starts, or the pool
# spins on a 404 instead of reading (see docs/queue.md).
#
# The .env is created only when there is none — `make env-copy` is the deliberate,
# interactive way to overwrite one, and running it here would make every re-setup stop
# to ask about a file the user already tuned.
#
# It ends on `workers-restart`, never on `restart`: the master needs to come up on the
# vendor composer just installed, but MySQL and RabbitMQ keep their data in tmpfs, which
# a container stop empties — so restarting the whole stack here would throw away the
# migrations and the queue this target just created.
setup:
	@[ -f .env ] || cp .env.example .env
	make down
	make build
	make up
	make composer c=install
	make demo-link-vendor
	make demo-art c=key:generate
	make demo-art c="migrate --force"
	make queues-declare
	make workers-restart
	@echo ""
	@echo "demo: http://localhost:$(APP_PORT)"

env-copy:
	cp -i .env.example .env

build:
	$(DOCKER_COMPOSE) build

up:
	$(DOCKER_COMPOSE) up -d --wait

stop:
	$(DOCKER_COMPOSE) stop --timeout=5

down:
	$(DOCKER_COMPOSE) down --timeout=5

# Careful: this stops MySQL and RabbitMQ too, and their data lives in tmpfs — a stop
# empties it. Use `make workers-restart` to pick up new code or a new vendor.
restart:
	make stop
	make up

# Fresh master and worker processes without touching the backends, so the demo's data
# and the declared queues survive.
workers-restart:
	$(DOCKER_COMPOSE) restart $(WORKERS_SERVICE)

# Puts the demo's state back by hand: the schema and the queue it consumes. The workers
# container does this from its entrypoint on every start, so it is rarely needed — reach
# for it when the backends came up after the master did, and the entrypoint's attempt
# was the one that failed.
demo-reset:
	make demo-art c="migrate --force"
	make queues-declare
	make workers-restart

logs:
	$(DOCKER_COMPOSE) logs -f ${c}

bash-php:
	$(PHP_CLI) bash

bash-workers:
	$(WORKERS_CLI) bash

composer:
	$(PHP_CLI) composer ${c}

# Resolves composer.lock in a throwaway container, off no image of this project. The
# image cannot build without the lock — it reads the sconcur version out of it — so the
# lock cannot be produced by the container the lock produces. `config.platform` in
# composer.json pins the PHP and msgpack versions the resolution targets, so what this
# writes is what the built image would have written.
composer-lock:
	docker run --rm -v "$(CURDIR)":/app -w /app -e COMPOSER_HOME=/tmp/composer \
		composer:2 composer update --no-install --no-scripts --no-interaction ${c}

# The demo has no composer.json of its own — it runs on the root vendor through this
# symlink, so the package and the application it demonstrates can never drift apart.
# Committed; this target is for the checkouts that lose symlinks.
demo-link-vendor:
	$(PHP_CLI) sh -c '[ -e demo/vendor ] || ln -s ../vendor demo/vendor'

demo-art:
	$(PHP_CLI) php demo/artisan ${c}

workers-art:
	$(WORKERS_CLI) php demo/artisan ${c}

tinker:
	make demo-art c=tinker

# On every install path, because neither side creates a queue: the publishing driver
# declares nothing and neither does QueueConsumer. Publishing to a routing key nothing
# is bound to is dropped by the broker without an error, and a pool consuming a queue
# that does not exist restarts on a 404 forever.
queues-declare:
	make workers-art c='sconcur:rabbitmq:declare'

# Master control. reload replaces the worker processes and leaves the master up; stop
# takes the master down too, and supervisor starts a fresh one.
sconcur-status:
	make workers-art c='sconcur:servers:master:status'

sconcur-reload:
	make workers-art c='sconcur:servers:master:reload'

sconcur-stop:
	make workers-art c='sconcur:servers:master:stop'

http-reload:
	make workers-art c='sconcur:servers:master:reload --group=http'

rabbitmq-reload:
	make workers-art c='sconcur:servers:master:reload --group=rabbitmq'

# Deliberately from the CLI container: the task pool is reached through a cache key, not
# through a pid, which is the whole point of that channel.
tasks-stop:
	make demo-art c='sconcur:tasks:stop'

tasks-restart:
	make demo-art c='sconcur:tasks:restart'

ext-status:
	make demo-art c='sconcur:extension:status'

# Walks the demo's ws path from outside — handshake, ping, subscribe, publish, delivery —
# and answers with an exit code, so the pool can be checked without a browser. From the
# workers container, because the ws client is in the extension. `c` is the burst size.
ws-check:
	$(WORKERS_CLI) php demo/bin/ws-check.php ${c}

test:
	$(PHP_CLI) ./vendor/bin/phpunit \
		-d memory_limit=512M \
		--colors=auto \
		--testdox \
		--display-incomplete \
		--display-skipped \
		--display-deprecations \
		--display-phpunit-deprecations \
		--display-errors \
		--display-notices \
		--display-warnings \
		tests ${c}

stan:
	$(PHP_CLI) ./vendor/bin/phpstan analyse \
		--memory-limit=1G

cs-fixer-check:
	$(PHP_CLI) ./vendor/bin/php-cs-fixer fix --config php-cs-fixer.dist.php --dry-run --diff --verbose

cs-fixer-fix:
	$(PHP_CLI) ./vendor/bin/php-cs-fixer fix --config php-cs-fixer.dist.php --verbose

# Lists the PHP files missing the declaration, and fails only when there are any —
# `grep -L` finding nothing exits 1, which would otherwise report success as a failure.
declare-strict:
	@! grep -Lr "declare(strict_types=1);" ./src ./tests ./workbench ./demo/app | grep '\.php$$'

check:
	make cs-fixer-check
	make stan
	make test
