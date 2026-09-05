# Конфиг, ENV и окружение

## `config/sconcur.php`

Два дополнения. Первое — группа `ws` в `master.groups`, по образцу `rabbitmq`:
её нет в конфиге, пока воркеров меньше одного.

```php
(int) env('SCONCUR_WS_WORKER_COUNT', 0) < 1 ? null : [
    'name'         => 'ws',
    'workerScript' => base_path('artisan'),
    'workerCount'  => (int) env('SCONCUR_WS_WORKER_COUNT'),
    'workerArgs'   => ['sconcur:servers:ws:start'],
    'server'       => [
        'address'             => env('SCONCUR_WS_ADDRESS', '0.0.0.0:28090'),
        'reusePort'           => (bool) env('SCONCUR_WS_REUSE_PORT', true),
        // Точная строка, а не префикс: query-строка в сравнение не входит, так
        // что Echo с /app/{key}?protocol=7 совпадает, а чужой ключ получает 404
        // ещё на рукопожатии. Пустая строка приняла бы любой путь.
        'path'                => env('SCONCUR_WS_PATH', '/app/' . env('SCONCUR_WS_APP_KEY', '')),
        'handshakeTimeoutMs'  => (int) env('SCONCUR_WS_HANDSHAKE_TIMEOUT_MS', 10000),
        // Ноль: молчащий клиент — норма, живость держит ping.
        'idleTimeoutMs'       => (int) env('SCONCUR_WS_IDLE_TIMEOUT_MS', 0),
        'writeTimeoutMs'      => (int) env('SCONCUR_WS_WRITE_TIMEOUT_MS', 30000),
        'pingIntervalMs'      => (int) env('SCONCUR_WS_PING_INTERVAL_MS', 30000),
        'maxMessageBytes'     => (int) env('SCONCUR_WS_MAX_MESSAGE_BYTES', 1048576),
        'maxConcurrency'      => (int) env('SCONCUR_WS_MAX_CONCURRENCY', 0),
        // Ноль обязателен: это дедлайн на всю жизнь соединения, а не на кадр.
        'handlerTimeoutMs'    => 0,
        'maxConnections'      => (int) env('SCONCUR_WS_MAX_CONNECTIONS', 0),
        'shutdownTimeoutMs'   => (int) env('SCONCUR_WS_SHUTDOWN_TIMEOUT_MS', 10000),
        'preemptionQuantumMs' => (int) env('SCONCUR_WS_PREEMPTION_QUANTUM_MS', 5),
    ],
],
```

Второе — секция `ws` верхнего уровня, рядом с `queue` и `tasks`:

```php
'ws' => [
    'app_key'    => env('SCONCUR_WS_APP_KEY', ''),
    'app_secret' => env('SCONCUR_WS_APP_SECRET', ''),

    // Префикс пути подключения; Echo использует /app/{key}.
    'path_prefix' => env('SCONCUR_WS_PATH_PREFIX', '/app'),

    // Сколько секунд клиенту ждать между своими ping; уходит в
    // pusher:connection_established.
    'activity_timeout_seconds' => (int) env('SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS', 120),

    'max_channels_per_connection' => (int) env('SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION', 100),

    // client-* события: выключены, как и у Pusher.
    'client_events' => (bool) env('SCONCUR_WS_CLIENT_EVENTS', false),
    'client_events_per_minute' => (int) env('SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE', 60),

    'bus' => [
        'driver'                => env('SCONCUR_WS_BUS_DRIVER', 'amqp'),
        'dsn'                   => env('SCONCUR_WS_BUS_DSN', env('SCONCUR_RABBITMQ_DSN')),
        'exchange'              => env('SCONCUR_WS_BUS_EXCHANGE', 'sconcur.ws'),
        // Простой подписчика: по нему корутина просыпается и проверяет реестр.
        'read_timeout_seconds'  => (float) env('SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS', 5.0),
        'reopen_backoff_ms'     => (int) env('SCONCUR_WS_BUS_REOPEN_BACKOFF_MS', 1000),
    ],

    'presence' => [
        'store'           => env('SCONCUR_WS_PRESENCE_STORE', 'auto'),
        'ttl_seconds'     => (int) env('SCONCUR_WS_PRESENCE_TTL_SECONDS', 3600),
    ],
],
```

Конфиг публикуемый, а не сливаемый — значит, у существующих приложений после
обновления пакета секции `ws` не появится. Команда старта обязана сказать об
этом прямо, как это делают остальные команды при пустом `config('sconcur')`,
а не падать на пустом массиве.

## ENV

В `.env.example` — новый блок рядом с блоком rabbitmq-пула:

```
# sconcur — ws pool
# Ниже 1 — группы нет в конфиге мастера; 0 означало бы «по воркеру на ядро».
SCONCUR_WS_WORKER_COUNT=2
SCONCUR_WS_PORT=28090
SCONCUR_WS_APP_KEY=_scl_ws_key_567
SCONCUR_WS_APP_SECRET=_scl_ws_secret_567
SCONCUR_WS_CLIENT_EVENTS=false
```

Порт отдельной переменной, а не полным адресом: он нужен и nginx, и
docker compose, а две переменные, которые обязаны совпадать, — это способ их
рассинхронизировать. Ровно так уже сделано для `SCONCUR_HTTP_PORT`.

## nginx

`docker/nginx/templates/default.conf.template` — блок для Upgrade. Отдельным
`location`, потому что таймауты и заголовки у него другие:

```
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

location /app/ {
    set $sconcur_ws workers:${SCONCUR_WS_PORT};

    proxy_pass http://$sconcur_ws;
    proxy_http_version 1.1;

    proxy_set_header Upgrade    $http_upgrade;
    proxy_set_header Connection $connection_upgrade;
    proxy_set_header Host       $host;
    proxy_set_header X-Real-IP  $remote_addr;

    # Соединение долгоживущее: 60 s общего блока разорвали бы его.
    proxy_read_timeout 3600s;
    proxy_send_timeout 3600s;
}
```

`map` объявляется на уровне `http`, а не внутри `server`; шаблон придётся
дополнить соответствующей секцией.

## docker

Публиковать порт ws-пула наружу не нужно — до него добирается nginx, как и до
http-пула. `SCONCUR_WS_PORT` добавляется в окружение сервиса `nginx` (для
подстановки в шаблон) и в `workers`.

## Демо

Демо-приложение получает страницу, на которой видно, что это работает:
подписка на публичный и приватный канал, кнопка, отправляющая событие через
обычный `broadcast()`, и лог входящих кадров. Echo подключается по конфигу из
`.env`. Страница нужна не как витрина, а как способ проверить сборку руками —
у демо уже есть такая же роль для очередей и телеметрии.
