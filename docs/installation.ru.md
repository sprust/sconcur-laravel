[English](installation.md) | Русский

# Установка

Десять шагов от пустого приложения до мастера, отдающего запросы. Шаги 6, 7 и 8
необязательны: они добавляют неблокирующее соединение с MySQL, драйвер очереди и Redis, а
HTTP-сервер работает и без них. WebSocket-пул необязателен так же, и его настройка — в
[websocket.ru.md](websocket.ru.md).

## Оглавление

- [Требования](#требования)
- [1. Пакет](#1-пакет)
- [2. Расширение `sconcur.so`](#2-расширение-sconcurso)
- [3. Конфиг](#3-конфиг)
- [4. `bootstrap/app.php`](#4-bootstrapappphp)
- [5. Каталоги рантайма](#5-каталоги-рантайма)
- [6. Соединение `sconcur_mysql` (по желанию)](#6-соединение-sconcur_mysql-по-желанию)
- [7. Очередь `sconcur_rabbitmq` (по желанию)](#7-очередь-sconcur_rabbitmq-по-желанию)
- [8. Redis: клиент `sconcur` и кэш `sconcur_redis` (по желанию)](#8-redis-клиент-sconcur-и-кэш-sconcur_redis-по-желанию)
- [9. Запуск](#9-запуск)
- [10. Проверка](#10-проверка)

## Требования

| Компонент | Версия | Зачем |
|---|---|---|
| PHP | 8.4, NTS | |
| расширение `sconcur` | 0.18.0 | ровно версия `sconcur/sconcur`, ставится отдельно (шаг 2) |
| MySQL | 8.4 | только под соединение `sconcur_mysql` |
| RabbitMQ | 4.1 | только под очередь `sconcur_rabbitmq` |
| Redis | 8.2 | только под Redis-клиент `sconcur` и кэш-стор `sconcur_redis` |

`.so` и PHP-сторона пересекают границу протокола, которая меняется вместе с версией,
поэтому `sconcur/sconcur` закреплён точно (`0.18.0`), а не кареткой, а расширение должно
совпадать с ним ровно: разошедшуюся версию оно отвергает на загрузке, а не работает
как-нибудь.

## 1. Пакет

```bash
composer require sconcur/laravel
```

Провайдер `SConcur\Laravel\SConcurServiceProvider` подхватывается автообнаружением
(`extra.laravel.providers` в `composer.json` пакета) — в `bootstrap/providers.php` его
дописывать не нужно. Он регистрирует артизан-команды, драйвер очереди
`sconcur_rabbitmq`, драйвер БД `sconcur_mysql`, Redis-клиент `sconcur`, кэш-стор
`sconcur_redis`, драйвер диска `sconcur_local`, бродкастер `sconcur`, пул задач,
WebSocket-пул, корутинные адаптеры и слушатели из `sconcur.listeners`.

## 2. Расширение `sconcur.so`

Расширения нет ни в PECL, ни в других реестрах — оно лежит ассетом релиза. Версию
команда берёт не из аргумента, а из `Extension::REQUIRED_EXTENSION_VERSION`, поэтому
скачанный файл заведомо проходит проверку на загрузке.

```bash
php artisan sconcur:extension:load "$(php-config --extension-dir)/sconcur.so"
echo "extension=sconcur.so" > "$(php-config --ini-dir)/sconcur.ini"
php artisan sconcur:extension:status
```

Аргумент — место назначения: путь к файлу или существующий каталог, в который файл
ложится как `sconcur.so`; каталог в любом случае должен существовать. Без аргумента цель —
`base_path('servers/sconcur')`, и это должен быть существующий каталог, а включать
расширение придётся флагом (`php -d extension=servers/sconcur/sconcur.so`): для пробы
годится, для мастера под супервизором процессов — нет.

В образе то же самое — одной инструкцией после `composer install`:

```dockerfile
RUN vendor/bin/sconcur-load "$(php-config --extension-dir)/sconcur.so" \
    && echo "extension=sconcur.so" > "$(php-config --ini-dir)/sconcur.ini"
```

Если расширение нужно поставить **до** `composer install` — когда установка зависимостей
идёт уже с загруженным `.so`, — версию берут из `composer.lock` и качают ассет напрямую;
готовый пример в [docker/php/Dockerfile](../docker/php/Dockerfile).

`sconcur:extension:status` печатает требуемую и установленную версии и выходит с кодом
`1`, если они разошлись, — годится как проверка на деплое.

## 3. Конфиг

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

Минимум в `.env`, чтобы мастер поднялся; полный перечень — в
[configuration.ru.md](configuration.ru.md):

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

Единственная обязательная правка в коде приложения: приложением должен быть
`AsyncApplication`, а не `Illuminate\Foundation\Application`. Без неё `request`,
`session`, `auth` и `cookie` остаются процессными синглтонами, и два запроса, идущие
корутинами в одном процессе, читают состояние друг друга.

Она же ставит два адаптера. Laravel собирает оба своих ядра до регистрации первого
сервис-провайдера, и каждое запоминает то, что ему отдали: консольное ядро — диспетчер
событий, HTTP-ядро — роутер, так что провайдер отдать им уже ничего не успевает.
Поэтому корутинобезопасную пару биндит сам `AsyncApplication`, в собственном
конструкторе: консольное ядро собирается на том диспетчере, который слушает приложение,
и `CommandStarting`, `CommandFinished` и `Terminating` доходят до слушателей.

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

## 5. Каталоги рантайма

Мастер держит в `storage/sconcur` лок, файл состояния, сокет телеметрии и логи. Пути
задаются в опубликованном конфиге (`master.runtimeDir` и `master.logDir`) и по умолчанию
указывают сюда:

```bash
mkdir -p storage/sconcur/runtime storage/sconcur/logs
```

Каталоги должны быть доступны на запись тому пользователю, под которым идут мастер и его
воркеры. Если приложение деплоится выкаткой нового каталога релиза, `storage` у него,
как обычно, общий — тогда переживший релиз лок и состояние это как раз то, что нужно.

## 6. Соединение `sconcur_mysql` (по желанию)

Неблокирующий MySQL включается там же, где выбирается любое соединение Laravel, —
в `config/database.php` и `DB_CONNECTION`. Подробности и ограничения — в
[database.ru.md](database.ru.md).

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

## 7. Очередь `sconcur_rabbitmq` (по желанию)

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
Разбор — в [queue.ru.md](queue.ru.md).

## 8. Redis: клиент `sconcur` и кэш `sconcur_redis` (по желанию)

Соединения описываются в секции `redis` файла `config/database.php` отдельными полями, и
секция выписывается целиком: в записях самого фреймворка есть `max_retries` и `backoff_*`, а
клиент от них отказывается. `prefix` сохраняется и ставится на ключи так же, как это делает
phpredis; приложению, которое переходит с phpredis, — раздел
[«Переход с phpredis»](redis.ru.md#переход-с-phpredis).

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'sconcur'),

    'default' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => env('REDIS_PORT', '6379'),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_DB', '0'),
    ],

    'cache' => [
        'host'     => env('REDIS_HOST', '127.0.0.1'),
        'port'     => env('REDIS_PORT', '6379'),
        'password' => env('REDIS_PASSWORD'),
        'database' => env('REDIS_CACHE_DB', '1'),
    ],
],
```

```php
// config/cache.php
'stores' => [
    'sconcur_redis' => [
        'driver'          => 'sconcur_redis',
        'connection'      => 'cache',
        'lock_connection' => 'default',
    ],
],
```

`CACHE_STORE=sconcur_redis` делает его стором по умолчанию. От `redis.client` стор не
зависит, так что фасад может остаться на другом клиенте. Ключи, отказы и ограничения — в
[redis.ru.md](redis.ru.md).

## 9. Запуск

Мастер — один процесс, который держит все пулы: `http`, `rabbitmq`, `ws` и `tasks`, — и
именно его запускает супервизор. Он загружает приложение и затем форкает из себя каждый
воркер, так что воркер стартует с уже загруженным приложением:

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

WebSocket-пулу нужен второй `location` рядом с этим — с заголовками Upgrade и большим
таймаутом чтения: блок выше рвал бы каждый сокет раз в минуту. Он есть в
[websocket.ru.md](websocket.ru.md).

Новая версия кода — это новый мастер. Мастер держит загруженное им приложение, и каждый
воркер, которого он форкает, получает именно его, поэтому reload нет:
`sconcur:servers:master:stop` дренирует воркеры и останавливает мастер, а супервизор
запускает его заново на новом релизе. Пока новый мастер загружается, порт никто не
слушает; это покрывает прокси перед ним, который повторяет отвергнутое соединение. Деплой,
который подменяет файлы на месте без перезапуска мастера, смешивает версии: воркер,
форкнутый после него, получает старый код из памяти мастера, а для класса, который мастер
ещё не загрузил, — новый с диска.

## 10. Проверка

```bash
php artisan sconcur:extension:status        # ready: yes
php artisan sconcur:servers:master:status   # running: pid=… workers=… groups=…
curl -i http://localhost/                   # ответ отдаёт уже воркер SConcur
```

`sconcur:servers:master:status` печатает по строке на группу (`--group=` оставляет одну):
сколько воркеров она держит. Если мастер не запущен, команда печатает `stopped` и выходит
с кодом `3`; без опубликованного конфига она падает и говорит об этом. Группы `rabbitmq`
не будет при `SCONCUR_RABBITMQ_WORKER_COUNT` ниже единицы — это штатный способ её
выключить.

Живьём всё то же самое можно посмотреть на демо-приложении в этом репозитории —
[demo/README.ru.md](../demo/README.ru.md).
