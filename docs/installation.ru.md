[English](installation.md) | Русский

# Установка

Девять шагов от пустого приложения до мастера, отдающего запросы. Шаги 6 и 7
необязательны: они добавляют неблокирующее соединение с MySQL и драйвер очереди, а
HTTP-сервер работает и без них.

## Оглавление

- [Требования](#требования)
- [1. Пакет](#1-пакет)
- [2. Расширение `sconcur.so`](#2-расширение-sconcurso)
- [3. Конфиг](#3-конфиг)
- [4. `bootstrap/app.php`](#4-bootstrapappphp)
- [5. Каталоги рантайма](#5-каталоги-рантайма)
- [6. Соединение `sconcur_mysql` (по желанию)](#6-соединение-sconcur_mysql-по-желанию)
- [7. Очередь `sconcur_rabbitmq` (по желанию)](#7-очередь-sconcur_rabbitmq-по-желанию)
- [8. Запуск](#8-запуск)
- [9. Проверка](#9-проверка)

## Требования

| Компонент | Версия | Зачем |
|---|---|---|
| PHP | 8.4, NTS | |
| `ext-msgpack` | 3.0.1 | весь трафик через границу PHP↔расширение; жёсткое требование `sconcur/sconcur`, его проверяет composer |
| расширение `sconcur` | 0.12.2 | ровно версия `sconcur/sconcur`, ставится отдельно (шаг 2) |
| `ext-pcntl` | — | graceful-остановка мастера и долгоживущих воркеров |
| MySQL | 8.4 | только под соединение `sconcur_mysql` |
| RabbitMQ | 4.1 | только под очередь `sconcur_rabbitmq` |

`.so` и PHP-сторона пересекают границу протокола, которая меняется вместе с версией,
поэтому `sconcur/sconcur` закреплён точно (`0.12.2`), а не кареткой, а расширение должно
совпадать с ним ровно: разошедшуюся версию оно отвергает на загрузке, а не работает
как-нибудь.

## 1. Пакет

```bash
composer require sconcur/laravel
```

Провайдер `SConcur\Laravel\SConcurServiceProvider` подхватывается автообнаружением
(`extra.laravel.providers` в `composer.json` пакета) — в `bootstrap/providers.php` его
дописывать не нужно. Он регистрирует артизан-команды, драйвер очереди
`sconcur_rabbitmq`, драйвер БД `sconcur_mysql`, пул задач и корутинные адаптеры.

## 2. Расширение `sconcur.so`

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

# панель телеметрии: пустой токен её выключает
SCONCUR_HTTP_PANEL_PORT=28081
SCONCUR_HTTP_ADMIN_TOKEN=change-me
SCONCUR_PANEL_HOST=http://127.0.0.1:28081/api/stats

# пул консьюмеров: меньше 1 — группы в конфиге мастера не будет вовсе
SCONCUR_RABBITMQ_WORKER_COUNT=0
```

## 4. `bootstrap/app.php`

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

## 8. Запуск

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

## 9. Проверка

```bash
php artisan sconcur:extension:status        # ready: yes
php artisan sconcur:servers:master:status   # running: pid=… workers=… groups=…
curl -i http://localhost/                   # ответ отдаёт уже воркер SConcur
```

`sconcur:servers:master:status` печатает по строке на группу: сколько воркеров у неё
поднято и каким скриптом. Пустой список групп означает, что конфиг не опубликован или
`groups` в нём пуст; группы `rabbitmq` не будет при `SCONCUR_RABBITMQ_WORKER_COUNT` ниже
единицы — это штатный способ её выключить.

Живьём всё то же самое можно посмотреть на демо-приложении в этом репозитории —
[demo/README.ru.md](../demo/README.ru.md).
