# Инициализация репозитория sconcur-laravel как самостоятельного пакета

Сейчас `sconcur-laravel` — это выделенный из `slogger.back/packages/sconcur/sconcur-laravel`
каталог с `src/`, `config/`, `docs/` и `README.md`. У него нет ни своего окружения, ни
тестов, ни анализаторов, ни способа что-либо запустить: `docker/` пустой,
`composer.lock` отсутствует, `vendor/` нет.

Цель — довести репозиторий до состояния, в котором **клонирование + `make setup`**
поднимает демо-приложение на `http://localhost:${APP_PORT}` и даёт полный набор
проверок (`make check`).

## Что берём из соседних проектов

| Артефакт | Источник | Комментарий |
|---|---|---|
| `.ai/README.md` + `AGENTS.md` + `CLAUDE.md` | `sconcur-php`, `slogger.back` | единая точка правил, корневые файлы — только указатели |
| `.editorconfig` | любой из трёх (файлы идентичны, md5 совпадает) | копируется как есть |
| `php-cs-fixer.dist.php` | `slogger.laravel` | у него finder уже под `src`/`workbench`/`tests` |
| `phpstan.neon` | `slogger.laravel` (level) + `sconcur-php` (`ignoreErrors` на функции расширения) | |
| `phpunit.xml`, `tests/bootstrap.php`, `testbench.yaml`, `workbench/` | `slogger.laravel` | тестовый стенд пакета |
| `makefile` | `slogger.laravel` (composer/test/stan/cs-fixer/матрица Laravel) + `slogger.back` (up/down/workers/queues-declare/sconcur-*) | |
| `docker-compose.yml` + tmpfs-режим БД | `sconcur-php` | mysql и rabbitmq в память |
| `docker/php/Dockerfile` (multi-stage), `docker/nginx`, `docker/supervisor` | `slogger.back` | там уже отлажена связка nginx → workers → мастер SConcur |
| `.github/workflows/release.yml` | `slogger.laravel` | тесты + релиз по `version` из `composer.json` |
| `.gitignore` | `slogger.laravel` | |

## Итоговое дерево

```
.ai/README.md               — правила для агентов (единый источник)
.ai/plans/init/*            — этот план
AGENTS.md, CLAUDE.md        — указатели на .ai/README.md
.editorconfig
.env.example                — порты, креды mysql/rabbitmq, DOCKER_USER_ID/GROUP_ID
.github/workflows/release.yml
.gitignore
composer.json               — + require-dev, autoload-dev (тесты, workbench, демо)
composer.lock               — коммитится: из него Dockerfile берёт версию sconcur.so
docker-compose.yml          — nginx, php, workers, mysql, rabbitmq
docker/php/Dockerfile       — стадии php_base → php_cli / php_workers
docker/php/conf/*.ini
docker/nginx/templates/default.conf.template
docker/supervisor/conf/supervisor.conf
makefile
php-cs-fixer.dist.php
phpstan.neon
phpunit.xml
testbench.yaml
config/sconcur.php          — как есть (каркас пакета)
src/                        — как есть
docs/                       — как есть
tests/                      — новое: тесты пакета
workbench/                  — новое: стенд testbench для тестов
demo/                       — новое: демо-приложение (то, что видно на localhost:PORT)
```

## Порядок шагов

Шаги независимы настолько, насколько это возможно, но порядок ниже — тот, в котором
каждый следующий можно проверить.

| # | Шаг | Файл плана |
|---|---|---|
| 1 | Правила, стиль, анализаторы, composer | [01-tooling.md](01-tooling.md) |
| 2 | Docker-окружение: контейнеры, расширение, nginx, supervisor | [02-docker.md](02-docker.md) |
| 3 | makefile и сценарий `make setup` | [03-makefile.md](03-makefile.md) |
| 4 | Демо-приложение `demo/` | [04-demo-app.md](04-demo-app.md) |
| 5 | Тесты: `workbench/` + `tests/` | [05-tests-workbench.md](05-tests-workbench.md) |
| 6 | CI и документация | [06-ci-docs.md](06-ci-docs.md) |

## Ключевые решения

1. **Демо-приложение и стенд тестов — это разные вещи.** `workbench/` живёт под
   `orchestra/testbench` и нужен только тестам (как в `slogger.laravel`). Демо —
   отдельное минимальное Laravel-приложение в `demo/`, потому что ему нужен свой
   `bootstrap/app.php`, возвращающий `SConcur\Laravel\Foundation\AsyncApplication`.
   Testbench собирает приложение сам (`Orchestra\Testbench\Foundation\Application`,
   класс `Illuminate\Foundation\Application` там зашит), и подменить его снаружи
   можно только через `workbench/bootstrap/app.php`, который читается лишь на пути
   тест-кейса (`WithLaravelBootstrapFile::getApplicationBootstrapFile`, проверка
   `usesTestingConcern(WithWorkbench::class) || $this instanceof Testbench`) — на пути
   `vendor/bin/testbench` он не читается. Строить демо на этой развилке — значит
   поставить его на внутренности чужого пакета.

2. **`demo/` не имеет своего `composer.json`.** Корневой `composer.json` уже требует
   `laravel/framework` и `sconcur/sconcur` в production-секции, поэтому демо ходит в
   корневой `vendor/` через симлинк `demo/vendor -> ../vendor`, а свои классы отдаёт
   через `autoload-dev` корня. Один `composer install`, один `composer.lock`, ноль
   расхождений версий между пакетом и демо. Симлинк нужен именно потому, что
   `PackageManifest` и `base_path('vendor/autoload.php')` считают vendor от base path
   приложения.

3. **mysql и rabbitmq — в памяти.** `tmpfs` вместо томов, как в `sconcur-php`:
   состояние стирается при пересоздании контейнера, тесты и демо стартуют с чистого
   листа. Закомментированные блоки с именованными томами оставляем рядом — тем же
   способом, что и в `sconcur-php`.

4. **`sconcur.so` вшивается в образ.** Версия читается из корневого `composer.lock`
   (`jq`) и качается с GitHub Releases — ровно как в `slogger.back/docker/php/Dockerfile`.
   Поэтому `composer.lock` обязан быть в репозитории: без него `make build` на свежем
   клоне нечего пинить.

5. **HTTP отдаёт мастер SConcur, а не php-fpm.** В `workers` под supervisor работает
   `php demo/artisan sconcur:servers:master:start` с тремя группами (`http`, `rabbitmq`,
   `tasks`), nginx проксирует на `workers:${SCONCUR_HTTP_PORT}`. php-fpm в этом
   репозитории не нужен вовсе — контейнер `php` чисто CLI-шный (composer, artisan,
   phpunit, анализаторы).

## Принятые решения

Подтверждены владельцем репозитория до начала реализации:

- **PHPStan level 8** — как в `slogger.laravel`. Ошибки, которые появятся на `src/`
  (он отдельно никогда не проверялся), правим по месту, а не понижаем уровень.
- **Laravel `^12.0` в `composer.json`**, PHP `^8.4`. Поддержка 10 и 11 снимается:
  `src/` использует синтаксис 8.4 (`private const array`, `new Foo()->bar()`), а
  Laravel 10 официально останавливается на PHP 8.3. Матрица CI — одна нога.
- **`sconcur/sconcur` пинится ровно на `0.11.0`** — текущая версия библиотеки. Не
  каретка: `.so` в образе и PHP-сторона в `vendor/` обязаны быть одной версии, а
  протокол PHP↔Go меняется вместе с ней (правило про три источника версии в
  `.ai/README.md` библиотеки).
- **`"version": "0.1.0"`** вместо `dev-master` — с этого начинается нумерация пакета.
- **`APP_PORT=48081`**.
- **Билингва документации — потом.** Отдельный план после того, как окружение
  заработает; см. [06-ci-docs.md](06-ci-docs.md).
- **Демо показывает телеметрию SConcur** — панель мастера, по образцу дашборда
  `slogger.back` (`app/Modules/Dashboard/Domain/Services/SconcurStatClient.php`).
  Подробности в [04-demo-app.md](04-demo-app.md).

## Риски

- **Релиз `.so` под нужную версию.** Сборка образа падает, если в релизе
  `github.com/sprust/sconcur/releases/tag/v0.11.0` нет ассета `sconcur.so`.
  Проверяем перед первым `make build`.
