# Статус реализации

Что уже сделано в репозитории по этому плану. Обновляется по мере работы; когда всё
закрыто — этот файл и есть отчёт о том, чем инициализация закончилась.

## Сделано

### Шаг 1 — правила, стиль, анализаторы

- `.ai/README.md` — единый источник правил (проект, окружение, архитектура, тесты,
  стиль, исключения, документация, workflow, коммиты). `AGENTS.md` и `CLAUDE.md` —
  указатели на него.
- `.editorconfig` — копия из `sconcur-php` (файл во всех трёх проектах побайтово один).
- `php-cs-fixer.dist.php` — из `slogger.laravel`, finder расширен на `demo/*`,
  русские комментарии переведены.
- `phpstan.neon` — level 8, пути `src`, `tests`, `workbench`, `demo/app`.
- `.gitignore`, `composer.json` (laravel `^12.0`, `sconcur/sconcur` `0.11.0`,
  `version` `0.1.0`, `require-dev`, `autoload-dev`, `config.platform`).
- `composer.lock` сгенерирован и коммитится.

Отличие от плана: в `composer.json` добавлена секция `config.platform`
(`php: 8.4.15`, `ext-msgpack: 3.0.1`). Она понадобилась из-за курицы и яйца: образ не
собирается без лока (он читает из него версию расширения), а лок нельзя собрать
образом, которого ещё нет. `platform` фиксирует, под какую платформу резолвится лок,
поэтому его можно построить одноразовым контейнером (`make composer-lock`) и получить
ровно то, что построил бы образ.

### Шаг 2 — Docker

- `docker/php/Dockerfile` — `php_base` → `php_cli` / `php_workers`. База ставит PIE,
  msgpack `3.0.1`, `bcmath/pcntl/pdo/pdo_mysql/sockets/zip` и скачивает `sconcur.so`
  по версии из `composer.lock`, проверяя `php -m | grep -qx sconcur`.
- `docker-compose.yml` — `nginx`, `php`, `workers`, `mysql` (tmpfs), `rabbitmq` (tmpfs).
- `docker/nginx/templates/default.conf.template`, `docker/supervisor/conf/supervisor.conf`,
  `docker/php/conf/{php,php-workers}.ini`.
- `.env.example` — один файл на всё: его читает и docker compose, и демо.

Отличие от плана: демо получает переменные окружения из корневого `.env`, а не из
`demo/.env` — `demo/bootstrap/app.php` вызывает `useEnvironmentPath(dirname(__DIR__, 2))`.
Две копии одних и тех же кредов не нужны никому.

### Шаг 3 — makefile

Все цели из плана плюс `composer-lock` (см. выше) и `demo-link-vendor`.

### Шаг 4 — демо

`demo/` целиком: `artisan`, `bootstrap/app.php` на `AsyncApplication`, конфиги
(`sconcur`, `database`, `queue`), модели, миграции, `DemoJob`, `HeartbeatTask`, шесть
контроллеров, `Telemetry/SconcurStatClient`, страница `resources/views/demo.blade.php`
с живой телеметрией мастера, `demo/README.md`.

Отличие от плана: `demo/config/app.php` не создан — Laravel 12 мержит дефолты
фреймворка под конфиг приложения, а всё, что демо переопределяет, приходит из ENV.
Файл без единого собственного значения был бы шумом.

### Шаг 5 — тесты

`phpunit.xml`, `tests/bootstrap.php`, `testbench.yaml`, `workbench/` (задачи, провайдер,
конфиг, маршрут) и `tests/Feature/`: конфиг мастера, регистрация команд и их флагов,
`TaskRegistry`, `ControlChannel`, `CooperativeSleeper`, `TaskPoolOptions`, `AsyncConfig`,
`Dsn`, `sconcur:rabbitmq:declare`, провайдер целиком.

### Шаг 6 — CI и документация

- `.github/workflows/release.yml` — PHP 8.4 × Laravel 12, сервисы MySQL и RabbitMQ,
  установка `sconcur.so` из релиза по `composer.lock`, релиз по `version`.
- `README.md` — раздел «Разработка», обновлённая «Структура», убрана ссылка на цели
  `deploy-*`, которых в этом репозитории нет.

## Проверено на живом окружении

`make setup` прогнан целиком с нуля дважды (второй раз — после правок ниже, `exit=0`),
затем `http://localhost:48081` и проверки руками:

| Что | Результат |
|---|---|
| расширение | `sconcur` 0.11.0 загружено в обоих контейнерах |
| `/api/health` | `{"ok":true,"connection":"sconcur_mysql"}` |
| `/api/concurrent?n=20&ms=150` | конкурентно 154 мс против 3026 мс последовательно — 19.65× в одном процессе |
| миграции | прошли по соединению `sconcur_mysql` |
| `/api/notes/bulk` (20) | 20 вставок в своих транзакциях за 18 мс, id вперемешку |
| `/api/jobs` (8 джоб по 2 с) | все восемь обработаны одним процессом (`worker_pid` один), по четыре одновременно — вес очереди 4 |
| упавшая джоба | три попытки (`$tries = 3`), затем строка в `failed_jobs` |
| `/api/telemetry` | панель отвечает: 4 воркера, три группы, память и горутины по каждому |
| пул задач | тики идут; `sconcur:tasks:stop` из контейнера `php` их останавливает |
| `master:reload` | rolling restart, порт всё время обслуживается |
| `make check` | cs-fixer чисто, PHPStan level 8 — 0 ошибок, 40 тестов зелёные |

PHPStan level 8 на `src/` дал 39 ошибок при первом прогоне; все исправлены — в
основном это недостающие phpdoc-типы на методах, переопределяющих методы фреймворка.
Три содержательные правки в `src/`:

- `Foundation/AsyncApplication::resolve()` звал `getAlias($abstract)` для `$abstract`,
  который контейнер разрешает передавать как `callable`; теперь alias берётся только
  со строки, а callable уходит к родителю, как и должен;
- там же, `$this->build($concrete)` вызывался для любого не-Closure значения биндинга;
  теперь только для существующего класса, остальное отдаётся контейнеру;
- `SConcurServiceProvider::registerRouterAdapter()` звал `forgetInstance()` на
  `$this->app`, типизированном контрактом `Application`, у которого этого метода нет.

## Что вскрыл полный прогон `make setup`

Две вещи, которые нашлись только тогда, когда цель отработала целиком на пустом месте —
проверки по частям их не показывали.

1. **`make setup` заканчивался на `make restart`, и это стирало то, что он только что
   создал.** MySQL и RabbitMQ держат данные в `tmpfs`, а `docker compose stop` очищает
   его — так что миграции и объявленная очередь пропадали ровно на последнем шаге.
   Заканчивается теперь на `workers-restart` (перезапуск одного контейнера воркеров,
   чтобы мастер поднялся на установленном vendor), и у `restart` появился комментарий,
   почему им нельзя закрывать установку.

2. **У демо не было `demo/composer.json`.** `Application::getNamespace()` читает файл по
   base path без проверки, что он есть, — поэтому любая ошибка приложения приводила ко
   второй ошибке в обработчике, и на странице оказывалось
   `file_get_contents(/app/demo/composer.json)` вместо настоящей причины. Файл добавлен;
   из него ничего не устанавливается, `demo/vendor` по-прежнему симлинк на корневой.

## Найдено по дороге

`sconcur:tasks:restart --task=NAME` **не возвращает** задачу, остановленную через
`sconcur:tasks:stop --task=NAME`. Цикл задачи — `while ($state->isActive($name))`
(`src/Tasks/TaskPool.php`), а `requestRelaunch()` читается только внутри этого цикла;
после `deactivate()` цикла уже нет. `README.md` описывает пару stop/restart так, что
обратное прочитывается само собой. Это поведение пакета, а не окружения — оно не
чинилось в рамках инициализации; фактическое положение дел записано в
[demo/README.md](../../../demo/README.md). Нужно решение владельца: чинить `TaskPool`
или переписать раздел README.

## Осталось

- Интеграционные тесты на живых бэкендах: `sconcur_mysql` (транзакции на корутину) и
  `sconcur_rabbitmq` (публикация → потребление, совместимость формата с
  `vladimir-yuldashev/laravel-queue-rabbitmq`, `failed_jobs`). Описаны в
  [05-tests-workbench.md](05-tests-workbench.md). Демо покрывает те же пути руками, но
  автоматической проверки на них пока нет.
- Тесты на конкурентную изоляцию адаптеров (две корутины не видят состояние друг
  друга) — сейчас `AsyncConfig` проверен только вне корутины.
- Решение по `tasks:restart` (см. выше).
- Билингва документации — отдельным планом, по решению владельца.
