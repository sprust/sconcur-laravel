# Шаг 3. makefile

Шапка — как в `slogger.back` (там она полнее, чем в `slogger.laravel`): подавление
эха, переменные CLI по контейнерам, подгрузка `.env` с откатом на `.env.example`.

```makefile
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
```

`docker compose` (v2, через пробел), а не `docker-compose`: в `sconcur-php` уже
перешли, `slogger.*` остались на старом — берём новый.

## Цели

### Окружение

| Цель | Что делает |
|---|---|
| `env-copy` | `cp -i .env.example .env` |
| `build` | `docker compose build` |
| `up` | `docker compose up -d --wait` |
| `stop` | `docker compose stop --timeout=3` |
| `down` | `docker compose down --timeout=3` |
| `restart` | `stop` + `up` |
| `bash-php` | shell в CLI-контейнер |
| `bash-workers` | shell в контейнер воркеров |
| `logs` | `docker compose logs -f ${c}` |

### Главная цель

```makefile
# Полная установка с нуля: клонировал репозиторий — выполнил это — открыл
# http://localhost:${APP_PORT}. Порядок обязателен: образ вшивает sconcur.so по
# версии из composer.lock, поэтому lock должен быть на месте до build; очереди
# объявляются до старта пула консьюмеров, иначе тот крутится на 404 (см. README,
# раздел «Объявление очередей обязательно»).
setup:
	make env-copy
	make down
	make build
	make up
	make composer c=install
	make demo-link-vendor
	make demo-art c=key:generate
	make demo-art c="migrate --force"
	make queues-declare
	make restart
	@echo "demo: http://localhost:$(APP_PORT)"
```

`env-copy` идёт с `cp -i` — на повторном `setup` он спросит про перезапись, что
правильно: `.env` уже могли поправить.

`demo-link-vendor` создаёт симлинк `demo/vendor -> ../vendor`, если его нет
(см. решение 2 в [00-overview.md](00-overview.md)). Симлинк коммитится, цель нужна для
случая, когда git его не восстановил (Windows, архив).

### Приложение и демо

| Цель | Команда |
|---|---|
| `composer` | `$(PHP_CLI) composer ${c}` |
| `demo-art` | `$(PHP_CLI) php demo/artisan ${c}` |
| `workers-art` | `$(WORKERS_CLI) php demo/artisan ${c}` |
| `queues-declare` | `make workers-art c='sconcur:rabbitmq:declare'` |
| `tinker` | `make demo-art c=tinker` |

Комментарий к `queues-declare` — по смыслу тот же, что в `slogger.back/makefile`:
она стоит на каждом пути установки, потому что ни драйвер публикации, ни
`QueueConsumer` очередь не объявляют.

### Управление мастером SConcur

Повторяет `sconcur-*` цели из `slogger.back`, но через artisan-команды пакета:

| Цель | Команда |
|---|---|
| `sconcur-status` | `make workers-art c='sconcur:servers:master:status'` |
| `sconcur-reload` | `make workers-art c='sconcur:servers:master:reload'` — свежие воркеры на текущем коде, мастер жив |
| `sconcur-stop` | `make workers-art c='sconcur:servers:master:stop'` — снимает и мастера, supervisor поднимет заново |
| `http-reload` | `... c='sconcur:servers:master:reload --group=http'` |
| `rabbitmq-reload` | `... c='sconcur:servers:master:reload --group=rabbitmq'` |
| `tasks-stop` | `make demo-art c='sconcur:tasks:stop'` — через кэш, из CLI-контейнера |
| `tasks-restart` | `make demo-art c='sconcur:tasks:restart'` |
| `ext-status` | `make demo-art c='sconcur:extension:status'` |

`tasks-*` намеренно идут из контейнера `php`, а не `workers`: канал управления пулом
задач — ключ кэша, и это ровно тот случай, ради которого он сделан (README, «Управление
снаружи»).

### Проверки

| Цель | Команда |
|---|---|
| `test` | `$(PHP_CLI) ./vendor/bin/phpunit` с флагами из `slogger.laravel` (`--testdox`, весь набор `--display-*`, `-d memory_limit=512M`), `tests ${c}` |
| `stan` | `$(PHP_CLI) ./vendor/bin/phpstan analyse --memory-limit=1G` |
| `cs-fixer-check` | `... php-cs-fixer fix --config php-cs-fixer.dist.php --dry-run --diff --verbose` |
| `cs-fixer-fix` | то же без `--dry-run` |
| `declare-strict` | `grep -Lr "declare(strict_types=1);" ./src ./demo/app | grep .php` |
| `check` | `cs-fixer-check` + `stan` + `test` |

### Матрица версий Laravel

Копируем из `slogger.laravel` целиком (`check-laravel-all`, `restore-composer`,
`set-laravel-N`) — вместе с комментарием про то, зачем `trap 'make restore-composer'
EXIT`. Набор версий приводим к тому, что решится по открытому вопросу о матрице
(см. [00-overview.md](00-overview.md)).

## Проверка шага

```bash
make setup
curl -sS http://localhost:48081/api/health
make check
```
