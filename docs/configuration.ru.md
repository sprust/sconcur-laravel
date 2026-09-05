[English](configuration.md) | Русский

# Конфигурация (ENV)

Все значения `config/sconcur.php` берутся из ENV. Дефолты ниже — пакетные, из каркаса;
в опубликованном файле приложение ставит свои.

## Оглавление

- [Общие](#общие)
- [Мастер (supervisor)](#мастер-supervisor)
- [Группа `http`](#группа-http)
- [HTTP-сервер (блок `server` группы `http`)](#http-сервер-блок-server-группы-http)
- [Группы (SConcur 0.12)](#группы-sconcur-012)
- [Группа `ws`](#группа-ws)
- [WebSocket-сервер (блок `server` группы `ws`)](#websocket-сервер-блок-server-группы-ws)
- [Протокол WebSocket (`sconcur.ws`)](#протокол-websocket-sconcurws)

## Общие

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_PANEL_HOST` | `http://127.0.0.1:28081/api/stats` | откуда дашборд читает статистику мастера |

Переключателя у coroutine-scoped приложения нет и определения режима тоже: провайдер
ставит адаптеры в любом процессе.

## Мастер (supervisor)

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

## Группа `http`

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_WORKER_COUNT` | `2` | число воркеров группы (0 = по числу ядер) |

`workerCount` — ключ группы, а не мастера, поэтому и таблица своя.

## HTTP-сервер (блок `server` группы `http`)

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

## Группы (SConcur 0.12)

Один мастер супервизит несколько непохожих пулов под одним локом и одним журналом,
поэтому `workerScript`, `workerCount`, `workerArgs` и `server` живут не на верхнем
уровне конфига, а в элементе списка `groups`.

Блок `server` группы мастер форвардит в argv её воркеров как есть, поэтому все три
команды — `http:start`, `rabbitmq:start` и `ws:start` — объявляют эти флаги: artisan
отвергает то, чего не объявлено. Читают их `HttpServer::fromArgs`,
`QueueConsumer::fromArgs` и `WsServer::fromArgs`. Всё, что не
скаляр (список очередей), мастер кодирует в JSON по дороге.

Запуск без мастера форвардить некому, поэтому команда в этом случае берёт тот же блок
`server` из конфига своей группы. Группа ищется по тому, что она запускает, а не по
имени, — иначе переименование группы тихо оставило бы standalone-запуск на дефолтах
библиотеки.

## Группа `ws`

| ENV | По умолчанию | Что делает |
|---|---|---|
| `SCONCUR_WS_WORKER_COUNT` | `0` | воркеров в группе; меньше 1 убирает группу из конфига |

## WebSocket-сервер (блок `server` группы `ws`)

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

## Протокол WebSocket (`sconcur.ws`)

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
