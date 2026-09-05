[English](README.md) | Русский

# SConcur Laravel

Laravel-интеграция для [SConcur](https://github.com/sprust/sconcur): конкурентный HTTP-воркер и
coroutine-scoped приложение.

`AsyncApplication` подключается в `bootstrap/app.php` как подкласс
`Illuminate\Foundation\Application`. В воркере на каждый фибер изолированы
`request`, `auth`, `session`, `cookie`, наложение конфигурации, текущий маршрут, локаль,
`View::share` и `defer`. Вне корутины поведение обычное: один вызывающий, один экземпляр.

Соединение `sconcur_mysql` даёт ORM неблокирующий MySQL и транзакции на корутину
(раздел «База данных»). У соединения `mysql` поверх PDO есть своё ограничение на
транзакции — раздел «Транзакции на PDO-соединении».

## Зачем

SConcur исполняет каждый HTTP-запрос в отдельном PHP-Fiber конкурентно в одном процессе.
Модель Octane — клонирование `$app` и подмена глобального контейнера — под такой
конкуренцией не fiber-safe. Этот пакет держит состояние запроса в **контексте корутины**,
не подменяя глобальное состояние и не клонируя приложение.

## Установка

### Требования

| Компонент | Версия | Зачем |
|---|---|---|
| PHP | 8.4, NTS | |
| `ext-msgpack` | 3.0.1 | весь трафик через границу PHP↔расширение; жёсткое требование `sconcur/sconcur`, его проверяет composer |
| расширение `sconcur` | 0.12.1 | ровно версия `sconcur/sconcur`, ставится отдельно (шаг 2) |
| `ext-pcntl` | — | graceful-остановка мастера и долгоживущих воркеров |
| MySQL | 8.4 | только под соединение `sconcur_mysql` |
| RabbitMQ | 4.1 | только под очередь `sconcur_rabbitmq` |

`.so` и PHP-сторона пересекают границу протокола, которая меняется вместе с версией,
поэтому `sconcur/sconcur` закреплён точно (`0.12.1`), а не кареткой, а расширение должно
совпадать с ним ровно: разошедшуюся версию оно отвергает на загрузке, а не работает
как-нибудь.

### 1. Пакет

```bash
composer require sconcur/laravel
```

Провайдер `SConcur\Laravel\SConcurServiceProvider` подхватывается автообнаружением
(`extra.laravel.providers` в `composer.json` пакета) — в `bootstrap/providers.php` его
дописывать не нужно. Он регистрирует артизан-команды, драйвер очереди
`sconcur_rabbitmq`, драйвер БД `sconcur_mysql`, пул задач и корутинные адаптеры.

### 2. Расширение `sconcur.so`

Расширения нет ни в PECL, ни в других реестрах — оно лежит ассетом релиза. Версию
команда берёт не из аргумента, а из `Extension::REQUIRED_EXTENSION_VERSION`, поэтому
скачанный файл заведомо проходит проверку на загрузке.

```bash
php artisan sconcur:extension:load "$(php-config --extension-dir)/sconcur.so"
echo "extension=sconcur.so" > "$(php-config --ini-dir)/sconcur.ini"
php artisan sconcur:extension:status
```

Аргумент — путь назначения, и каталог должен существовать. Без аргумента файл ложится в
`base_path('servers/sconcur')`, и включать расширение придётся флагом
(`php -d extension=servers/sconcur/sconcur.so`): для пробы годится, для воркеров, которых
мастер запускает сам, — нет.

В образе то же самое — одной инструкцией после `composer install`:

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > "$(php-config --ini-dir)/sconcur.ini"
```

Если расширение нужно поставить **до** `composer install` — когда установка зависимостей
идёт уже с загруженным `.so`, — версию берут из `composer.lock` и качают ассет напрямую;
готовый пример в [docker/php/Dockerfile](docker/php/Dockerfile).

`sconcur:extension:status` печатает требуемую и установленную версии и выходит с кодом
`1`, если они разошлись, — годится как проверка на деплое.

### 3. Конфиг

```bash
php artisan vendor:publish --tag=sconcur-laravel
```

Публикация обязательна. Пакет не мержит свой конфиг в приложение, поэтому опубликованный
файл — это весь `config('sconcur')`: приложение владеет каждым значением, включая
дефолты. Мерж оставлял бы за спиной приложения пакетные значения, и удалённый ключ тихо
возвращался бы к пакетному дефолту; а ещё пакету пришлось бы держать дефолты для того,
чего он знать не может, — какие очереди читать и с каким весом. Без публикации команды
говорят об этом прямо, а не падают на пустом конфиге.

В пакете лежит каркас: то, что верно для любого приложения. Детали — свои очереди, их
веса и число процессов — живут в опубликованном файле.

Минимум в `.env`, чтобы мастер поднялся; полный перечень — раздел «Конфигурация (ENV)»:

```dotenv
SCONCUR_HTTP_NAME=my-app
SCONCUR_HTTP_ADDRESS=0.0.0.0:28080
SCONCUR_HTTP_WORKER_COUNT=2

# панель телеметрии: пустой токен её выключает
SCONCUR_HTTP_PANEL_PORT=28081
SCONCUR_HTTP_ADMIN_TOKEN=change-me
SCONCUR_PANEL_HOST=http://127.0.0.1:28081/api/stats

# пул консьюмеров: меньше 1 — группы в конфиге мастера не будет вовсе
SCONCUR_RABBITMQ_WORKER_COUNT=0
```

### 4. `bootstrap/app.php`

Единственная обязательная правка в коде приложения: приложением должен быть
`AsyncApplication`, а не `Illuminate\Foundation\Application`. Без неё `request`,
`session`, `auth` и `cookie` остаются процессными синглтонами, и два запроса, идущие
корутинами в одном процессе, читают состояние друг друга.

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

Переписывать здесь нечего: `Application::configure()` из скелета — это ровно
`new ApplicationBuilder(new static($basePath))` с четырьмя `with*` следом, а билдер
принимает готовый экземпляр. Всё, что стояло после `configure()` в вашем файле,
остаётся как было.

### 5. Каталоги рантайма

Мастер держит в `storage/sconcur` лок, файл состояния, сокет телеметрии и логи. Пути
задаются в опубликованном конфиге (`master.runtimeDir` и `master.logDir`) и по умолчанию
указывают сюда:

```bash
mkdir -p storage/sconcur/runtime storage/sconcur/logs
```

Каталоги должны быть доступны на запись тому пользователю, под которым идут мастер и его
воркеры. Если приложение деплоится выкаткой нового каталога релиза, `storage` у него,
как обычно, общий — тогда переживший релиз лок и состояние это как раз то, что нужно.

### 6. Соединение `sconcur_mysql` (по желанию)

Неблокирующий MySQL включается там же, где выбирается любое соединение Laravel, —
в `config/database.php` и `DB_CONNECTION`. Подробности и ограничения — раздел
«База данных».

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

Штатное соединение `mysql` из конфига не убирайте: оно нужно тому, чему нужен настоящий
объект PDO, — `schema:dump` и драйверу очереди `database`. И проверьте `batching` и
`failed` в `config/queue.php`: там должно стоять `null`, а не `env('DB_CONNECTION')`, —
`null` следует за `database.default`, и `failed_jobs` не расходится с остальным.

### 7. Очередь `sconcur_rabbitmq` (по желанию)

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

Пул консьюмеров поднимается ненулевым `SCONCUR_RABBITMQ_WORKER_COUNT`, а очереди, которые
он читает, перечисляются в `sconcur.queue.rabbitmq.queues` опубликованного конфига.

Объявление очередей обязательно и должно стоять на каждом пути установки и деплоя — ни
драйвер при публикации, ни консьюмер топологию не создают:

```bash
php artisan sconcur:rabbitmq:declare
```

Пропустить её — значит терять джобы молча (публикация уходит в дефолтный обменник на
routing key, к которому никто не привязан) и крутить пул в цикле рестартов на `404`.
Разбор — раздел «Объявление очередей обязательно».

### 8. Запуск

Мастер — один процесс, который держит все пулы: `http`, `rabbitmq` и `tasks`. Он же
и есть то, что запускает супервизор:

```ini
[program:sconcur-master]
command=php /srv/app/artisan sconcur:servers:master:start
autostart=true
autorestart=true
stopsignal=TERM
; мастер форвардит SIGTERM своим группам и выжидает их shutdownTimeoutMs — до 30 с
; у пула задач. Дефолтные 10 с убили бы мастер посреди дренажа, и весь graceful ниже
; стал бы бессмысленным.
stopwaitsecs=40
```

Отдельного `php-fpm` в схеме нет: HTTP отдаёт сам SConcur, а nginx перед ним —
обратный прокси.

```nginx
location / {
    proxy_pass http://127.0.0.1:28080;

    # 1.0 — дефолт, и chunked-ответ он не переносит
    proxy_http_version 1.1;

    proxy_set_header Host              $host;
    proxy_set_header X-Real-IP         $remote_addr;
    proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

Выкатка новой версии кода — `sconcur:servers:master:reload`: rolling restart воркеров,
мастер остаётся жив. Отдельная группа обновляется через `--group=http`.

### 9. Проверка

```bash
php artisan sconcur:extension:status        # ready: yes
php artisan sconcur:servers:master:status   # running: pid=… workers=… groups=…
curl -i http://localhost/                   # ответ отдаёт уже воркер SConcur
```

`sconcur:servers:master:status` печатает по строке на группу: сколько воркеров у неё
поднято и каким скриптом. Пустой список групп означает, что конфиг не опубликован или
`groups` в нём пуст; группы `rabbitmq` не будет при `SCONCUR_RABBITMQ_WORKER_COUNT` ниже
единицы — это штатный способ её выключить.

Живьём всё то же самое можно посмотреть на демо-приложении в этом репозитории — раздел
«Демо-приложение».

## Структура

```
config/sconcur.php        — конфиг (panel_host, scoped_services, master + groups, queue, ws, tasks)
src/SConcurServiceProvider — провайдер (команды + проводка адаптеров в воркере)
src/Console/              — артизан-команды
src/Servers/              — MasterRunner (обёртка над SConcur\Worker\MasterCli)
src/Queue/Rabbitmq/       — драйвер очереди и консьюмер-пул (Connector, Queue, Job, ConsumerRunner)
src/Database/Mysql/       — соединение sconcur_mysql (Connector, Connection, Dsn, TransactionStack)
src/Tasks/                — пул периодических задач (TaskPool, TaskPoolController, TaskRegistry,
                            CooperativeSleeper, TaskPoolTelemetry + TaskPoolMetrics)
src/Tasks/Control/        — канал управления через кэш (stop/restart из другого контейнера)
src/Http/                 — HttpServerRunner + LaravelHttpHandler (build + serve)
src/Ws/                   — WebSocket-пул (WsServerRunner, ConnectionHandler,
                            ConnectionRegistry, Protocol, Auth, Bus, Presence, Broadcasting)
src/Foundation/           — AsyncApplication, ScopedService, ScopedServiceProxy
src/Config/               — AsyncConfig (overlay config()->set per-coroutine)
src/Events/               — AsyncDispatcher (defer() per-coroutine)
src/Routing/              — AsyncRouter (current route/request per-coroutine)
src/Translation/          — AsyncTranslator (локаль per-coroutine)
src/View/                 — AsyncViewFactory (View::share per-coroutine)
docs/                     — ТЗ и план

demo/                     — демо-приложение, которое отдаёт мастер (см. demo/README.md)
workbench/                — приложение testbench, на котором идут тесты
tests/                    — тесты пакета
docker/                   — образы, nginx и supervisor окружения разработки
```

Приложение корутинно-скоупленное всегда, без переключателей и без определения режима.
Адаптеры ставятся в любом процессе, и контейнер всегда резолвит `request`/`session`/
`auth`/`cookie` из контекста корутины.

Вне корутины это ничего не стоит: контекст сводится к корню процесса, то есть к одному
хранилищу для одного вызывающего — тому же, чем являются штатные реализации. Всё
состояние запроса — `request`, `auth`, `session`, `cookie`, наложение конфигурации,
текущий маршрут, локаль, `View::share`, `defer` — живёт в контексте корутины.

Контекст корутины берётся из библиотеки: `SConcur\Context\Context::current()`
(`find/has/set/forget`). Семантика — `vendor/sconcur/sconcur/docs/coroutine-context.ru.md`.

## Артизан-команды

Мастер инстанцируется прямо в командах из `config('sconcur.master')`
(через `MasterConfig::fromArray`), без прокидывания JSON-пути.

```
sconcur:servers:master:start|stop                 # MasterRunner (supervisor, спавнит воркеры)
sconcur:servers:master:status [--group=NAME]      # статус: все пулы или один
sconcur:servers:master:reload [--group=NAME]      # rolling restart: все пулы или один
sconcur:servers:http:start                        # один HTTP-сервер в foreground (build + serve)
sconcur:servers:rabbitmq:start                    # пул консьюмеров очереди в foreground
sconcur:rabbitmq:declare                          # объявить очереди, которые читает пул — обязательна
sconcur:servers:ws:start                          # один WebSocket-сервер в foreground
sconcur:tasks:start [--only=NAME]                 # пул периодических задач в foreground
sconcur:tasks:stop [--task=NAME]                  # остановить пул или одну его задачу
sconcur:tasks:restart [--task=NAME]               # пересобрать все задачи или одну
sconcur:extension:load                            # скачать .so (запускает downloader)
sconcur:extension:status                          # статус расширения (in-process)
```

`reload` — единственная команда, которой нужен файл: мастер перечитывает конфиг с диска
в своём процессе, поэтому in-memory объект до него не доходит. `masterConfigPath()`
сериализует тот же самый массив в `{runtimeDir}/{name}.config.json` и отдаёт путь —
так файл, из которого мастер перезагружается, и конфиг, которым его супервизят,
не расходятся.

Мастер спавнит воркеры как `php artisan sconcur:servers:http:start --masterPid=N`
(`workerScript=artisan`, `workerArgs=[команда]`). Тот же `http:start` запускается и
standalone. Обработчик coroutine-safe (per-fiber контекст), запросы внутри процесса
обрабатываются конкурентно; для прод-многопроцессного режима — `master:start` (+ `reusePort`).

Источник подхода: coroutine-scoped модель (AsyncApplication + per-coroutine состояние)
взята из [yangusik/laravel-spawn](https://github.com/yangusik/laravel-spawn) (там — поверх
PHP TrueAsync) и адаптирована на стандартный PHP + SConcur (`Context::current()` вместо
TrueAsync-контекста). PSR-7 мост воркера — на базе модели Laravel Octane.

## Транзакции на PDO-соединении (важно)

Относится к обычному соединению `mysql`. У `sconcur_mysql` этого ограничения нет — см.
раздел «База данных».

Пока корутина держит транзакцию на блокирующем PDO, нельзя отдавать управление другой
корутине: PDO общий на процесс, и соседняя корутина сходит в то же физическое соединение
— попадёт в твою транзакцию или закроет её. Изоляция счётчика транзакций этого не лечит,
поэтому отдельных DB-методов и не добавляли.

Дело не в `await` как таковом, а в любом переключении корутины. Его вызывают:

- любой вызов, уходящий в расширение, — Mongo, SQL-фича, HTTP-клиент, AMQP, `Sleeper`;
- `WaitGroup` — и на запуске дочерних корутин, и на ожидании их завершения;
- вытесняющее переключение по кванту (`preemption_quantum_ms` у пула задач): переключает
  даже чистый PHP-код, в котором нет ни одного вызова наружу;
- `Fiber::suspend()` в чужом коде — например, внутри вызванного пакета.

Практически на PDO под конкуренцией безопасна только транзакция, внутри которой не
происходит ничего, кроме SQL к тому же PDO, и то при выключенном вытеснении (проверено:
30/30 параллельных вложенных транзакций). Всё остальное — до `beginTransaction`, после
`commit` или в очередь.

## База данных (`sconcur_mysql`)

Соединение Laravel, за которым вместо PDO стоит SQL-фича SConcur. Statement уходит в
расширение, пока вызвавшая корутина приостановлена, поэтому конкурентные обработчики
одного процесса не ждут друг друга на общем блокирующем дескрипторе. Вне корутины те же
вызовы работают синхронно.

`Connection` наследует `Illuminate\Database\MySqlConnection`, поэтому грамматики, схема,
пост-процессор и `instanceof MySqlConnection` остаются на месте; заменены только методы,
которым потребовался бы объект PDO. Все они по-прежнему идут через `Connection::run()` —
замер времени, `QueryExecuted`, лог запросов и оборачивание в `QueryException` работают
как обычно.

### Конфигурация

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

`charset`, `collation`, `timezone`, `strict`/`modes` едут в DSN, а не отдельными `SET`
после подключения. Первые три — параметры подключения, которые расширение знает по имени;
`sql_mode` — и всё, что лежит в `dsn_params`, — это то, чем нераспознанный параметр в этом
формате DSN и является: системная переменная сессии, которая уходит одним `SET` на каждое
открытое пулом соединение, после собственной настройки драйвера и потому поверх неё.
`unix_socket` тоже работает — как `unix(/path/to.sock)`.

`parseTime` не отправляется и ничего бы не дал: расширение его принимает и игнорирует, а
`DATE`/`DATETIME`/`TIMESTAMP` всегда приходят в RFC3339.

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_DB_TIMEOUT_MS` | `30000` | предел на один statement; для курсора — на всю его жизнь |
| `SCONCUR_DB_MAX_OPEN_CONNS` | `20` | размер пула в расширении; `0` — это не «без лимита», а его собственные 32 |
| `SCONCUR_DB_MAX_IDLE_CONNS` | `0` | простаивающих соединений; `0` — равно `max_open_conns` |
| `SCONCUR_DB_CONN_MAX_LIFETIME_MS` | `0` | время жизни соединения; `0` — без предела |

Пул с ограничением — не перестраховка: каждый одновременный statement берёт своё
соединение, поэтому безлимитный пул при фан-ауте упирается в `max_connections` сервера
(ошибка MySQL 1040).

### Какое соединение использует приложение

Никакого выбора в рантайме здесь нет, и это осознанно. SQL-фича работает и синхронно —
вне корутины те же вызовы просто не приостанавливают ничего, — поэтому соединение
выбирается там же, где у любого Laravel-приложения: `DB_CONNECTION` и `database.default`.
Проверено: миграции, `Schema::create` с индексами, `hasTable` и `getColumnListing` через
этот драйвер работают.

Соединение `mysql` на PDO стоит оставить в конфиге для того, чему нужна настоящая
объект PDO: `schema:dump` зовёт `mysqldump` мимо соединения, а драйвер очереди `database`
спрашивает у PDO имя и версию драйвера.

Ещё два места в `config/queue.php` — `batching` и `failed` — должны называть соединение
как `null`, а не через `env('DB_CONNECTION')`: `null` следует за `database.default`, и
тогда `failed_jobs` не расходится с тем, чем пользуется всё остальное.

### Транзакции

Уровень вложенности живёт не в свойстве соединения (объект один на все корутины), а в
`TransactionStore`: внутри корутины это контекст корутины, вне её — собственный массив
хранилища, а не корневой контекст. Разница существенная: корневой контекст никогда не
освобождается и читается всеми корутинами насквозь, так что транзакция, открытая до
появления первого фибера, досталась бы каждому запросу, сообщению и задаче разом. Первый уровень — настоящий `BEGIN` фичи, выше — савпоинты, как и на
PDO.

- Сёстры друг друга не видят: конкурентные запросы и джобы — соседи в дереве контекста, а
  не предки, поэтому транзакция одной другой не видна.
- Дочерняя корутина транзакцию наследует, и её запросы идут в ту же транзакцию. Иначе
  `WaitGroup` из пяти `UPDATE` внутри `DB::transaction()` тихо ушёл бы пятью автокоммитами
  мимо неё.
- Закрывает транзакцию тот, кто её открыл. `commit()`/`rollBack()` корневого уровня из
  чужой корутины бросают исключение: закоммитить общий объект она бы смогла, а запись
  владельца в контексте осталась бы на месте, указывая на мёртвую транзакцию.
- Вложенный уровень дочерняя корутина открывает и закрывает сама. Имена савпоинтов берутся
  из счётчика, общего на транзакцию, а не из глубины: по глубине две сестринские корутины
  выдали бы одно имя, а MySQL при повторном `SAVEPOINT` удаляет прежний.

Что остаётся: недочитанный курсор (`cursor()`, прерванный до конца) держит соединение
транзакции, и следующая команда любой корутины в ней будет его ждать. При фан-ауте внутри
транзакции пользуйся `select()`/`get()`, которые вычитывают выборку целиком.

`afterCommit` и `dispatchAfterCommit` корректны под конкуренцией: `db.transactions` — это
`CoroutineTransactionsManager`, который держит по менеджеру фреймворка на корутину и
только маршрутизирует к нужному. Регистрируется он всегда, а не по типу процесса: вне
корутины у него один собственный менеджер, и поведение неотличимо от штатного. Без него один синглтон на
процесс ключевал бы записи именем соединения, а не тем, кто открыл транзакцию, и коммит
одной корутины выполнял бы `afterCommit` соседней при ещё открытой у той транзакции.
Это не теория: `Model::saveOrFail()` — это транзакция, так что обычное создание модели
уже туда попадает.

### Отличия от PDO

- Типы. PDO с эмуляцией отдаёт всё строками; расширение нормализует: целые → `int`,
  `FLOAT`/`DOUBLE` → `float`, `DECIMAL` → строка, `NULL` → `null`. Eloquent это скрывает,
  а строгое `===` со строкой в прикладном коде — нет. `TINYINT(1)` — это `int`, а не
  `bool`, а беззнаковый `BIGINT` больше `PHP_INT_MAX` приходит десятичной строкой:
  в знаковый 64-битный int он не помещается.
- Даты. `DATE`/`DATETIME`/`TIMESTAMP` приходят в RFC3339 (`2026-09-05T10:17:40Z`), а не
  строкой `Y-m-d H:i:s`, как у PDO. Eloquent это не задевает — `Model::getDateFormat()`
  не совпадает, и `asDateTime()` проваливается в `Date::parse()`, который её читает, —
  а вот сырое значение из `select()`, отданное туда, где ждут написание PDO, задевает.
- `UPDATE` отвечает числом **совпавших** строк, а не изменённых. Драйвер негоциирует
  `CLIENT_FOUND_ROWS`, а PDO — нет, поэтому `DB::update()` по строке, где новое значение
  уже стоит, вернёт здесь 1, а там 0. Код, который читает это число как «что-то
  действительно изменилось», читает другое. Переключателя нет: флаг негоциируется в
  handshake, `clientFoundRows=false` в DSN отвергается, а не применяется, и `ROW_COUNT()`
  отвечает тем же числом. Свести два соединения к одной семантике можно только с другой
  стороны: `'options' => [PDO::MYSQL_ATTR_FOUND_ROWS => true]` в записи `mysql` сажает
  PDO на тот же флаг. Иначе спрашивайте в самом запросе — исключите строки, которые не
  изменятся, и совпавшие станут изменёнными:

  ```php
  DB::table('notes')
      ->where('id', $id)
      ->whereRaw('NOT (title <=> ?)', [$title])
      ->update(['title' => $title]);
  ```

  `<=>` — NULL-безопасное сравнение, поэтому колонка с `NULL` сравнивается, а не
  выпадает, как выпала бы на `<>`.
- `getPdo()`/`getReadPdo()` бросают исключение: объекта PDO нет. Код, которому нужен PDO, работает через
  соединение `mysql`.
- `selectResultSets()` не поддерживается: фича отдаёт один result set на запрос.
- Строки всегда приходят как `stdClass` — это дефолтный `fetchMode` Laravel, менять его
  соединение всё равно не даёт.
- **Порядок колонок в строке не гарантирован.** PDO отдаёт их в порядке `SELECT`, здесь
  строка едет через границу PHP↔расширение как msgpack-map, а у map порядок ключей не сохраняется
  — так что подряд идущие одинаковые запросы дают разный порядок полей. По имени всё
  читается как обычно, поэтому ORM, `->id`, `->toArray()` и `where` этого не замечают.
  Замечает то, что на порядок опирается: `array_values($row)`, деструктуризация
  `[$a, $b] = array_values(...)`, `fputcsv` строки как есть, сравнение двух строк через
  `==` на массивах и `json_encode` ответа API — ключи в JSON будут переставляться от
  запроса к запросу. Если порядок важен, задавайте его сами: перечисляйте поля при сборке
  ответа, а не отдавайте строку целиком.
- Read/write-разделение (`read`/`write`/`sticky`) не поддерживается — у фичи один DSN.
- `pretend()` держит флаг в общем объекте: в корутинном рантайме им пользоваться нельзя.
- `schema:dump` зовёт `mysqldump` мимо соединения — это не про драйвер.
  Миграции же через него работают: `Schema` ходит теми же `select`/`statement`.
- Драйвер очереди `database` (`Illuminate\Queue\DatabaseQueue`) на этом соединении не
  работает: он спрашивает у PDO имя и версию драйвера. Приложению, которое держит очередь
  в таблице, соединение для неё нужно назвать явно.

Всё, что соединение по имени не называет, идёт за `database.default`: `Auth` через
eloquent-провайдер, модели без `protected $connection`, служебные таблицы фреймворка.
Модель, которой нужно другое хранилище, называет своё соединение явно.

### Задержка и подтверждения (`config/queue.php`)

`confirm_publishes` включает подтверждение для каждой публикации,
`confirm_timeout_seconds` — сколько его ждать; отложенная публикация подтверждается
всегда, независимо от них.

## Разработка

В репозитории есть собственное окружение и демо-приложение, поэтому пакет можно
не только читать, но и запустить:

```bash
make setup
```

После этого демо отвечает на `http://localhost:48081` (порт — `APP_PORT` в `.env`).
Что там показано и что стоит попробовать руками — [demo/README.md](demo/README.md).

### Что поднимается

| Контейнер | Роль |
|---|---|
| `scl-nginx` | единственный опубликованный вход; проксирует в пул `http` |
| `scl-php` | только CLI: composer, artisan, phpunit, анализаторы. php-fpm здесь нет — HTTP отдаёт сам SConcur |
| `scl-workers` | supervisor, под ним мастер SConcur с группами `http`, `rabbitmq` и `tasks` |
| `scl-mysql` | MySQL 8.4, данные в `tmpfs` — стираются при пересоздании контейнера |
| `scl-rabbitmq` | RabbitMQ 4.1 с панелью, тоже в `tmpfs` |

Расширение `sconcur.so` вшито в образ: `docker/php/Dockerfile` читает версию
`sconcur/sconcur` из `composer.lock` и качает соответствующий ассет релиза. Поэтому
`composer.lock` лежит в репозитории — без него на свежем клоне нечего пинить. Версия
библиотеки закреплена точно, а не кареткой: `.so` и PHP-сторона пересекают границу
протокола, которая меняется вместе с версией.

`composer.lock` собирается одноразовым контейнером (`make composer-lock`), не образом
проекта: образ не построится без лока, который он же и читает. Целевую платформу
резолвинга задаёт `config.platform` в `composer.json`.

### Команды

```bash
make up / make stop / make restart    # окружение
make demo-art c=...                   # artisan демо-приложения
make workers-art c=...                # тот же artisan внутри контейнера воркеров
make queues-declare                   # объявить очереди, которые читает пул консьюмеров
make sconcur-status                   # статус мастера: группы и воркеры
make sconcur-reload                   # rolling restart пулов, мастер остаётся жив
make tasks-stop / make tasks-restart  # управление пулом задач из другого контейнера
make check                            # cs-fixer, phpstan, тесты
make test c=--filter=DsnTest          # один тест
```

Тестам нужно поднятое окружение: они грузят `sconcur.so`, а интеграционные ходят в
живые MySQL и RabbitMQ.

### Тесты и демо — разные приложения

`workbench/` живёт под `orchestra/testbench` и принадлежит тестам. `demo/` — отдельное
минимальное приложение со своим `bootstrap/app.php`, потому что ему нужен
`AsyncApplication`, а testbench собирает `Illuminate\Foundation\Application` сам.
Своего `composer.json` у демо нет: `demo/vendor` — симлинк на корневой `vendor`, классы
автозагружаются через `autoload-dev` корня. Один install, один лок, и пакет с
приложением, которое его демонстрирует, не могут разъехаться.

## Конфигурация (ENV)

Все значения `config/sconcur.php` берутся из ENV. Дефолты ниже — пакетные, из каркаса;
в опубликованном файле приложение ставит свои.

### Общие

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_PANEL_HOST` | `http://127.0.0.1:28081/api/stats` | откуда дашборд читает статистику мастера |

Переключателя у coroutine-scoped приложения нет и определения режима тоже: провайдер
ставит адаптеры в любом процессе.

### Мастер (supervisor)

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_PHP_BINARY` | `php` | PHP-бинарь для воркеров |
| `SCONCUR_HTTP_PANEL_PORT` | `28081` | порт телеметрия-панели (0 = выкл) |
| `SCONCUR_HTTP_ADMIN_TOKEN` | `` (пусто) | Bearer-токен панели (пусто = выкл) |
| `SCONCUR_HTTP_NAME` | `sconcur-http-server` | имя сервера (lock/state/log файлы) |
| `SCONCUR_HTTP_ROTATE_DAYS` | `3` | ротация логов, дней |
| `SCONCUR_HTTP_LOG_TO` | `both` | куда логировать (`file`/`stdout`/`both`) |
| `SCONCUR_HTTP_RESTART_POLICY` | `always` | политика рестарта воркеров |
| `SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS` | `10000` | таймаут graceful-остановки воркера, мс |
| `SCONCUR_HTTP_RESTART_BACKOFF_MS` | `200` | стартовый backoff рестарта, мс |
| `SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS` | `30000` | макс. backoff рестарта, мс |

### Группа `http`

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_WORKER_COUNT` | `2` | число воркеров группы (0 = по числу ядер) |

`workerCount` — ключ группы, а не мастера, поэтому и таблица своя.

### HTTP-сервер (блок `server` группы `http`)

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_ADDRESS` | `0.0.0.0:28080` | адрес прослушивания |
| `SCONCUR_HTTP_REUSE_PORT` | `true` | `SO_REUSEPORT` (несколько процессов на один порт) |
| `SCONCUR_HTTP_MAX_REQUESTS` | `0` | стоп после N запросов (0 = ∞) |
| `SCONCUR_HTTP_MAX_CONCURRENCY` | `0` | макс. одновременных запросов (0 = ∞) |
| `SCONCUR_HTTP_MAX_REQUEST_BODY` | `10485760` | лимит тела запроса, байт |
| `SCONCUR_HTTP_READ_HEADER_TIMEOUT_MS` | `10000` | таймаут чтения заголовков, мс |
| `SCONCUR_HTTP_READ_TIMEOUT_MS` | `30000` | таймаут чтения, мс |
| `SCONCUR_HTTP_WRITE_TIMEOUT_MS` | `30000` | таймаут записи, мс |
| `SCONCUR_HTTP_IDLE_TIMEOUT_MS` | `60000` | idle-таймаут keep-alive, мс |
| `SCONCUR_HTTP_HANDLER_TIMEOUT_MS` | `60000` | таймаут обработки запроса, мс |
| `SCONCUR_HTTP_SERVER_SHUTDOWN_TIMEOUT_MS` | `5000` | таймаут остановки сервера, мс |

Не из ENV: `workerScript=base_path('artisan')`, `workerArgs=['sconcur:servers:http:start']`,
`phpArgs=[]`, `runtimeDir`/`logDir`=`storage_path('sconcur/runtime'|'sconcur/logs')`.

### Группы (SConcur 0.12)

Один мастер супервизит несколько непохожих пулов под одним локом и одним журналом,
поэтому `workerScript`, `workerCount`, `workerArgs` и `server` живут не на верхнем
уровне конфига, а в элементе списка `groups`.

Блок `server` группы мастер форвардит в argv её воркеров как есть, поэтому обе команды —
`http:start` и `rabbitmq:start` — объявляют эти флаги: artisan отвергает то, чего не
объявлено. Читают их `HttpServer::fromArgs` и `QueueConsumer::fromArgs`. Всё, что не
скаляр (список очередей), мастер кодирует в JSON по дороге.

Запуск без мастера форвардить некому, поэтому команда в этом случае берёт тот же блок
`server` из конфига своей группы. Группа ищется по тому, что она запускает, а не по
имени, — иначе переименование группы тихо оставило бы standalone-запуск на дефолтах
библиотеки.

### Группа `ws`

| ENV | По умолчанию | Что делает |
|---|---|---|
| `SCONCUR_WS_WORKER_COUNT` | `0` | воркеров в группе; меньше 1 убирает группу из конфига |

### WebSocket-сервер (блок `server` группы `ws`)

| ENV | По умолчанию | Что делает |
|---|---|---|
| `SCONCUR_WS_ADDRESS` | `0.0.0.0:28090` | адрес прослушивания |
| `SCONCUR_WS_REUSE_PORT` | `true` | `SO_REUSEPORT` (несколько процессов на одном порту) |
| `SCONCUR_WS_PATH` | `/app/${SCONCUR_WS_APP_KEY}` | точный путь, на котором принимается Upgrade; пустая строка — любой |
| `SCONCUR_WS_HANDSHAKE_TIMEOUT_MS` | `10000` | сколько может занять чтение заголовков Upgrade, мс |
| `SCONCUR_WS_IDLE_TIMEOUT_MS` | `0` | простой между входящими сообщениями (0 — выключено) |
| `SCONCUR_WS_WRITE_TIMEOUT_MS` | `30000` | отправка одного сообщения, мс |
| `SCONCUR_WS_PING_INTERVAL_MS` | `30000` | частота keepalive-пинга сервера (0 — выключено) |
| `SCONCUR_WS_MAX_MESSAGE_BYTES` | `1048576` | предел размера входящего сообщения |
| `SCONCUR_WS_MAX_CONCURRENCY` | `0` | соединений в обслуживании одновременно (0 — ∞) |
| `SCONCUR_WS_MAX_CONNECTIONS` | `0` | остановиться после N обслуженных соединений (0 — ∞) |
| `SCONCUR_WS_SHUTDOWN_TIMEOUT_MS` | `10000` | дедлайн мягкой остановки, мс |
| `SCONCUR_WS_PREEMPTION_QUANTUM_MS` | `5` | квант вытеснения во время обслуживания |

`handlerTimeoutMs` в списке нет и жёстко равен `0`. Здесь это дедлайн на всю жизнь
соединения, а не на один кадр, поэтому любое значение выше нуля рвёт всех клиентов по
таймеру — ws-пулу не нужно ни одно из них.

Путь сравнивается без query-строки, поэтому `/app/{key}?protocol=7&client=js` совпадает,
а чужой ключ получает `404` на рукопожатии, ещё до PHP.

### Протокол WebSocket (`sconcur.ws`)

| ENV | По умолчанию | Что делает |
|---|---|---|
| `SCONCUR_WS_APP_KEY` | `` (пусто) | публичный ключ; браузер несёт его в пути |
| `SCONCUR_WS_APP_SECRET` | `` (пусто) | подписывает подписки на каналы; только http- и ws-воркеры |
| `SCONCUR_WS_PATH_PREFIX` | `/app` | часть пути подключения до ключа |
| `SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS` | `120` | сколько клиент может молчать, прежде чем пинговать |
| `SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION` | `100` | каналов на одно соединение |
| `SCONCUR_WS_CLIENT_EVENTS` | `false` | разрешить `client-*` на private/presence каналах |
| `SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE` | `60` | ограничение их частоты, на соединение |
| `SCONCUR_WS_BUS_DRIVER` | `amqp` | `amqp` или `local` — последний ничего не доставляет между процессами и годится для тестов |
| `SCONCUR_WS_BUS_DSN` | `${SCONCUR_RABBITMQ_DSN}` | брокер, на котором работает шина |
| `SCONCUR_WS_BUS_EXCHANGE` | `sconcur.ws` | fanout-обмен, к которому привязывается каждый воркер |
| `SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS` | `5.0` | пульс подписчика; он же ограничивает мягкую остановку |
| `SCONCUR_WS_BUS_REOPEN_BACKOFF_MS` | `1000` | пауза перед переоткрытием упавшего подписчика |
| `SCONCUR_WS_PRESENCE_STORE` | `auto` | `memory`, `cache` или `auto` — по размеру пула |
| `SCONCUR_WS_PRESENCE_TTL_SECONDS` | `3600` | сколько живёт список участников канала без изменений |
| `SCONCUR_WS_PRESENCE_CACHE_PREFIX` | `sconcur:ws:presence` | префикс ключей в кэше |

`SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS` — не сетевая настройка. Подписчик шины получает
управление обратно только на доставке или на этом таймауте, и именно тогда он замечает,
что последнее соединение ушло, и останавливается — а это то, что позволяет мягкой
остановке сервера завершиться. Поэтому значение обязано оставаться заметно меньше
`shutdownTimeoutMs` группы.

Список участников, лежащий в одном процессе, верен ровно пока процесс один. При пуле
`memory` не неполон, а неверен — каждый воркер отвечает своими подписчиками; `auto` берёт
там `cache`, а явный `memory` команда старта не принимает молча, а сообщает о нём.

## Очередь (`sconcur_rabbitmq`)

Драйвер очереди Laravel поверх AMQP-фичи SConcur плюс пул консьюмеров, который читает
очереди корутинами в одном процессе вместо одного блокирующего `queue:work` на воркера.
Выигрыш — на стороне консьюмера: и `ext-amqp`, и `php-amqplib` держат PHP-поток на
чтении очереди, а здесь подвешивается только своя корутина, поэтому один процесс тянет
несколько очередей, а медленная джоба стоит одного сообщения, а не воркера.

### Объявление очередей обязательно

Очередь не появляется сама ни с одной стороны: драйвер при публикации ничего не
объявляет, `QueueConsumer` — тоже. Топология принадлежит своему владельцу, а консьюмер,
передекларировавший чужую очередь своими флагами, уронил бы канал `406` вместо того чтобы
читать. Поэтому `sconcur:rabbitmq:declare` обязана выполниться до первой публикации и до
старта пула, и стоять на каждом пути установки и деплоя, а не запускаться руками однажды.
В этом репозитории она заведена как цель `make queues-declare`, которую вызывает
`make setup`; в приложении её место — на каждом пути установки и деплоя.

Что бывает, если пропустить:

- публикация уходит в дефолтный обменник с routing key, равным имени очереди, и брокер
  молча выбрасывает сообщение, к чьему routing key никто не привязан. Ошибки нет — джобы
  просто теряются. Исключение одно: отложенная публикация всегда идёт через
  `publishConfirmed`, поэтому бросает `UnroutableMessageException`;
- пул на `basic.consume` несуществующей очереди получает `SConcur\Exceptions\Amqp\QueueException`
  с текстом `Server channel error: 404, message: NOT_FOUND - no queue 'default' in vhost '/'`.
  Воркер выходит с кодом `1`, мастер поднимает замену, и так по кругу с растущим backoff:
  пул не читает ничего, а в панели телеметрии его группа стоит без воркеров.

Команда объявляет то, что перечислено в `sconcur.queue.rabbitmq.queues`, флагами
`durable`, не `exclusive`, не `autoDelete`, без аргументов — теми же, что и
`vladimir-yuldashev/laravel-queue-rabbitmq` (раздел «Совместимость»). Повторный запуск
безвреден: объявление существующей очереди теми же флагами не меняет ничего, поэтому
команду держат на деплое, не проверяя, выполнялась ли она раньше.

Очереди ожидания её не касаются: их создаёт сама отложенная публикация, которой они
нужны, — раздел «Соединение».

### Совместимость

Формат на проводе — не наш: тело, свойства сообщения и заголовок попыток ровно те, что
пишет `vladimir-yuldashev/laravel-queue-rabbitmq`. Джоба, отправленная любым из двух
драйверов, читается и выполняется другим — проверено в обе стороны.

Держится это на трёх вещах, и менять их нельзя в одностороннем порядке:

- счётчик попыток живёт в заголовке `laravel.attempts`, а не в `x-death`; на нём
  `Worker::process()` строит `maxTries` и запись в `failed_jobs`;
- очередь объявляется теми же флагами — `durable`, не `exclusive`, не `autoDelete`, без
  аргументов; расхождение даёт `406`, который закрывает канал;
- публикация идёт в дефолтный обменник с routing key, равным имени очереди.

### Соединение

```php
// config/queue.php
'sconcur_rabbitmq' => [
    'driver'    => 'sconcur_rabbitmq',
    'queue'     => env('RABBITMQ_QUEUE', 'default'),
    'dsn'       => env('SCONCUR_RABBITMQ_DSN'),   // amqp://user:pass@host:5672/%2f
],
```

В AMQP нет отложенной публикации: `later()` и `release()` ходят через очередь, которую
никто не читает и которая по TTL отправляет сообщение обратно. Очередь на задержку, а не
одна с per-message TTL, потому что классическая очередь протухает только с головы: TTL
стоит на очереди, поэтому внутри неё у всех сообщений один срок и голова никого не
держит.

Очередь ожидания создаётся той самой публикацией, которой она нужна, и называется точной
задержкой — `<очередь>.wait.<мс>`. Так же устроен
`vladimir-yuldashev/laravel-queue-rabbitmq`. Это даёт точность: заранее объявленная
лестница фиксированных ступеней обслуживает только заложенные в неё задержки и округляет
до них все остальные, а очередь на точную задержку выдерживает ровно её. Убирать за собой
тоже не нужно: `x-expires`
велит брокеру снести очередь ожидания, которой не пользовались вдвое дольше её задержки,
а повторное объявление на каждом ретрае — это то, что держит живой ту, которая ещё
нужна.

Отложенная публикация всегда идёт через `publishConfirmed`, независимо от настроек
соединения: обычная публикация на routing key, к которому никто не привязан, молча
выбрасывается брокером — а `publishConfirmed` по умолчанию mandatory и бросает
`UnroutableMessageException`.

### Консьюмер

Пул — это группа мастера, поэтому он живёт под тем же супервизором, что и HTTP, и
отчитывается в ту же панель телеметрии (секция `consumers`).

```
php artisan sconcur:rabbitmq:declare
php artisan sconcur:servers:rabbitmq:start --queues='[{"name":"default","coroutineCount":8}]' --prefetchCount=1
```

Обработка идёт через `Illuminate\Queue\Worker::process()` — события джобы, `maxTries`,
`backoff` и `failed_jobs` достаются готовыми. `Worker::daemon()` не используется: это
строго последовательный цикл, одна джоба за раз, и его `sleep()` блокирует процесс.

Запись в `failed_jobs` делает не `Worker`, а команда `queue:work`, которую пул заменяет,
— поэтому `ConsumerRunner` вешает тот же слушатель `JobFailed` сам.

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_RABBITMQ_WORKER_COUNT` | `0` | процессов в пуле; меньше `1` — группа не попадает в конфиг мастера вовсе |
| `SCONCUR_RABBITMQ_QUEUE` | `default` | очередь, которую читает пул |
| `SCONCUR_RABBITMQ_QUEUE_CONSUMERS` | `1` | вес этой очереди — сколько консьюмеров она получает |
| `SCONCUR_RABBITMQ_PREFETCH_COUNT` | `1` | неподтверждённых сообщений на консьюмера |
| `SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS` | `0` | предел на одно сообщение в обработчике; `0` — без предела |
| `SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE` | `false` | вернуть упавшее сообщение в очередь вместо dead-letter |
| `SCONCUR_RABBITMQ_MAX_MESSAGES` | `0` | дренировать и выйти после N сообщений |
| `SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS` | `0` | дренировать и выйти через N секунд |
| `SCONCUR_RABBITMQ_MAX_MEMORY_BYTES` | `0` | дренировать и выйти по размеру кучи |
| `SCONCUR_RABBITMQ_CONNECTION` | `sconcur_rabbitmq` | соединение `config/queue.php` для джоб |
| `SCONCUR_RABBITMQ_MEMORY_MB` | `128` | предел памяти воркера, МиБ |
| `SCONCUR_RABBITMQ_TRIES` | `1` | попыток до `failed_jobs` |
| `SCONCUR_RABBITMQ_BACKOFF` | `0` | задержка перед повтором, секунд |

Ноль в `SCONCUR_RABBITMQ_WORKER_COUNT` не значит «ни одного воркера»: для мастера
`workerCount: 0` — это воркер на ядро (`WorkerGroup`, `Cpu::count()`). Поэтому пул
выключается не нулём в группе, а тем, что группы в конфиге не оказывается.

Каркас описывает одну очередь, потому что больше он знать не может: список очередей и
их веса — то, что приложение ставит в опубликованном файле, где `queues` может быть
списком любой длины. Он же и объявляется командой `sconcur:rabbitmq:declare`, которая
читает `sconcur.queue.rabbitmq.queues`.

Вес очереди — аналог числа процессов `queue:work` на эту очередь: сколько консьюмеров
она получает, каждый на своём канале. Обработчик при этом всё равно
выполняется в отдельной корутине на сообщение.

`handlerTimeoutMs` нулевой по умолчанию, потому что предел не замедляет джобу, которую
поймал, а отклоняет её, — решать это приложению, знающему свои джобы.

`handlerTimeoutMs` разматывает зависший обработчик и отклоняет его сообщение; воркер
берёт следующее. `WorkerOptions::$timeout` при этом ноль намеренно: `SIGALRM` воркера
Laravel убил бы процесс вместе со всеми обработчиками, работающими рядом.

## WebSocket (`sconcur:servers:ws:start`)

Четвёртая среда выполнения пакета: пул WebSocket-воркеров под тем же мастером, сеть — в
расширении, каждое поднятое соединение — своя корутина. Подробно —
[docs/websocket.ru.md](docs/websocket.ru.md).

Протокол — совместимое подмножество Pusher, поэтому `laravel-echo` работает без своего
клиента, а авторизация канала идёт через штатный маршрут приложения `/broadcasting/auth`.
Со стороны приложения это обычный драйвер вещания:

```php
broadcast(new OrderShipped($order))->toOthers();
```

### Как включить

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

Меньше одного воркера убирает группу из конфига мастера целиком; `0` означал бы один
воркер на ядро, а не ни одного — то же правило, что у пула консьюмеров.

Шине нужен брокер: `SCONCUR_WS_BUS_DSN`, который падает на `SCONCUR_RABBITMQ_DSN`.
Команда `declare` не нужна — обмен и очереди воркеров принадлежат пакету, он их и
объявляет.

nginx должен пропускать Upgrade, и отдельным `location`: ws-соединение долгоживущее, а
таймауты обычного прокси-блока рвали бы каждого клиента раз в минуту. Готовый блок — в
`docker/nginx/templates/default.conf.template`.

### Сторона браузера

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

Для `toOthers()` событие обязано использовать
`Illuminate\Broadcasting\InteractsWithSockets`: этот трейт объявляет свойство `$socket`,
в которое пишется socket id вызывающего. Без него `toOthers()` молча ничего не делает.

### Чего он не делает

- Ни TLS, ни `permessage-deflate`: первое терминирует nginx, второго расширение пока не
  умеет.
- Ни HTTP-API Pusher, ни вебхуков, ни статистики — их место заняла шина.
- Шифрованных каналов нет; подписка на такой отклоняется явно.
- Истории событий нет: переподключившийся клиент не получает пропущенное, а воркер,
  отсутствовавший секунду, пропускает события, а не получает их пачкой. Вещание — это
  уведомление; то, что обязано дойти, — это очередь.
- `sconcur:servers:master:reload` рвёт ws-соединения. Для http-пула reload незаметен,
  здесь — заметен, и Echo переподключается сам.

## Пул задач (`sconcur:tasks:start`)

Третий рантайм пакета: один процесс, каждая настроенная задача — своя корутина
`WaitGroup`. Задача реализует `tick()` и больше ничего; цикл, паузы, отчётность и
остановка принадлежат пулу. Подробно — [docs/task-pool.ru.md](docs/task-pool.ru.md).

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_TASKS_CONTROL_KEY` | `sconcur:tasks:control` | ключ кэша, через который до пула доходят `stop` и `restart` |
| `SCONCUR_TASKS_LOCK_PATH` | `storage/sconcur/runtime/tasks.lock` | flock, не даёт подняться второму пулу |
| `SCONCUR_TASKS_MEMORY_MB` | `256` | предел памяти процесса; за ним — выход с `EXIT_RESTART` |
| `SCONCUR_TASKS_SLEEP_CHUNK_MS` | `250` | на какие кванты дробится пауза, то есть как быстро пул замечает сигнал |
| `SCONCUR_TASKS_PREEMPTION_QUANTUM_MS` | `1000` | автоматическое переключение корутин; `0` — выключено (см. docs) |
| `SCONCUR_TASKS_REPORT_TICKS` | `true` | показывать тики в секции `consumers` панели |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS` | `20` | сколько ждать текущие тики перед разматыванием группы |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS` | `30000` | сколько мастер ждёт воркер пула; должен превышать предыдущий |

Группа пула объявляет `restartPolicy: on-failure`, а не наследует мастерское `always`:
`sconcur:tasks:stop` выходит с нулём, и под `always` мастер поднял бы замену через
секунду. Единственный выход, которому замена нужна, — предел памяти, и он не нулевой.

### Как пишется задача

Контракт — `SConcur\Laravel\Tasks\TaskInterface`, два метода:

```php
public function name(): string;          // имя, которым задача адресуется в командах и в логе
public function tick(): TickResultEnum;  // одна порция работы
```

`stop()` в интерфейсе нет намеренно: PHP не умеет прерывать чужой фибер, поэтому такой
метод мог бы только поднять флаг, который цикл задачи обязан не забыть проверить.
Своего цикла у задачи нет — пул просто перестаёт звать `tick()`.

Исход тика выбирает следующую паузу, и все три настраиваются на задачу:

| `TickResultEnum` | Что значит | Пауза из конфига |
|---|---|---|
| `Worked` | работа была и сделана | `busy` |
| `Idle` | работы не нашлось | `idle` |
| `Failed` | тик бросил исключение | `backoff` |

```php
// config/sconcur.php
'tasks' => [
    'list' => [
        ['name' => 'cron', 'task' => CronTask::class, 'idle' => 5, 'busy' => 5, 'backoff' => 5],
    ],
],
```

Два правила, которые пул за вас не проверит:

1. **Тик обязан вернуться сам.** Прервать его нечем, поэтому зависший навсегда тик держит
   свою корутину до жёсткого дедлайна остановки, который разматывает всю группу.
2. **Тик не трогает процессно-глобальное состояние** — `config()->set`, `Auth`, `Request`,
   статические свойства. Тики разных задач чередуются, а с включённой преемпцией — на
   любой границе опкодов. Транзакция сюда не относится, если она на `sconcur_mysql`:
   уровень вложенности лежит в контексте корутины, а расширение закрепляет её за
   отдельным физическим соединением, так что соседняя задача в неё не войдёт. На
   PDO-соединении относится — там объект PDO один на процесс.

Пауза идёт через `CooperativeSleeper`, а не через родной `sleep()`: тот заморозил бы весь
процесс, все его корутины разом. Ожидание нарезано на куски (`SCONCUR_TASKS_SLEEP_CHUNK_MS`)
по двум причинам — паузу надо уметь оборвать, когда ждать уже нечего, и PHP должен
регулярно доходить до границы опкода, иначе отложенный обработчик сигнала не выполнится
вовсе: процесс, все корутины которого припаркованы в расширении, не исполняет PHP и SIGTERM не
увидит.

### Управление снаружи

`sconcur:tasks:stop` и `sconcur:tasks:restart` не ищут процесс и не требуют его pid: они
кладут команду в кэш под ключом `SCONCUR_TASKS_CONTROL_KEY`, а пул забирает её своим
контроллерным тиком. Поэтому пулом можно управлять из другого контейнера — того же
`php-fpm`, например, — не зная, где он поднят.

Без `--task` команда адресована всему пулу, с `--task=NAME` — одной задаче: `stop`
паркует её, оставив соседние задачи работать, `restart` собирает её заново и возвращает
в строй. Припаркованная задача держит свою корутину — иначе `restart` было бы некому
прочитать, — и ждёт, пока её вернут или пул остановят. Когда припаркованы все задачи,
тикать больше нечему, и пул завершается сам: это то же окончание, что и у `stop` без
`--task`.

У `sconcur:tasks:start` обратная опция, `--only=NAME` (повторяемая): поднять только
перечисленные задачи вместо всех настроенных.

### Телеметрия пула

Пул — единственный воркер мастера, который отчитывается о себе сам. У остальных снапшоты
шлёт собственный рантайм расширения, а здесь никакого рантайма нет, поэтому
`TaskPoolTelemetry` раз в секунду снимает RSS и CPU из `/proc` и пишет кадр в unix-сокет
коллектора (4-байтовый префикс длины, JSON-тело). Числа живых задач в рантайме расширения
(`runtimeTasks`) у такого воркера не существует, и оно уходит нулём.

Счётчики тиков (`TaskPoolMetrics`) уходят в секцию `consumers` снапшота: тик для задачи —
то же, что доставка для консьюмера, поэтому колонки панели наполняются без изменений на
её стороне. Холостой тик не считается нигде — иначе `Finished` мерил бы интервал
опроса, а средняя длительность — стоимость пустого опроса вместо стоимости работы.

Мастер суммирует эту секцию по всем воркерам, поэтому в его сводных числах тики пула
складываются с доставками AMQP-пула. Пер-групповые числа при этом остаются раздельными.
Отчётность выключается через `SCONCUR_TASKS_REPORT_TICKS=false`.

## Демо-приложение

В репозитории лежит демо — минимальное Laravel-приложение, которое отдаёт сам мастер
SConcur. Оно нужно, чтобы пакет можно было посмотреть в работе, а не только прочитать
про него.

```bash
make setup
```

Дальше — `http://localhost:48081` (порт меняется через `APP_PORT` в `.env`).
Подробности — [demo/README.md](demo/README.md).

Что видно на странице:

| Блок | Что показывает |
|---|---|
| Master telemetry | панель мастера как есть: воркеры по группам, сколько запросов и сообщений в работе прямо сейчас, память, живые задачи в рантайме расширения. Обновляется раз в секунду |
| Pool sizes | сколько процессов держит каждый пул и сколько консьюмеров получает очередь в каждом из них; применение прокатывает только те группы, чьи числа изменились |
| Concurrency | одна и та же кооперативная пауза N раз: корутинами одной `WaitGroup` против последовательного прохода. На 20 паузах по 150 мс — 152 мс против 3026 мс в одном процессе |
| MySQL | Eloquent поверх `sconcur_mysql`, в том числе несколько вставок конкурентно, каждая в своей транзакции |
| Queue | джобы через `sconcur_rabbitmq`; в результатах видно, что все они прошли через один процесс-консьюмер |
| Periodic tasks | счётчик, который наращивает пул периодических задач |

Демо — не тестовый стенд: тесты идут на `workbench/` под `orchestra/testbench`, а у демо
свой `bootstrap/app.php`, потому что ему нужен `AsyncApplication`. Своего
`composer install` у него нет — `demo/vendor` симлинк на корневой `vendor`, поэтому
пакет и приложение, которое его демонстрирует, не могут разъехаться по версиям.

Приложению, которое ставит пакет обычным способом, ничего из этого не нужно: там
провайдер находится автообнаружением, а конфиг публикуется через
`vendor:publish --tag=sconcur-laravel`.
