[English](configuration.md) | Русский

# Конфигурация (ENV)

Значения `config/sconcur.php` берутся из ENV, кроме немногих, названных ниже, и здесь
перечислена каждая переменная, которую читает пакет. Дефолты — пакетные, из каркаса; в опубликованном файле приложение
ставит свои.

## Оглавление

- [Все переменные сразу](#все-переменные-сразу)
- [Общие](#общие)
- [Мастер (supervisor)](#мастер-supervisor)
- [Группы](#группы)
- [Группа `http`](#группа-http)
- [HTTP-сервер](#http-сервер)
- [Группа `rabbitmq` и её консьюмеры](#группа-rabbitmq-и-её-консьюмеры)
- [Группа `ws`](#группа-ws)
- [WebSocket-сервер](#websocket-сервер)
- [Протокол WebSocket](#протокол-websocket)
- [Пул задач](#пул-задач)
- [Redis](#redis)
- [Файловая система](#файловая-система)
- [Слушатели](#слушатели)

## Все переменные сразу

Все переменные ниже со значениями по умолчанию из пакета: блок можно вставить в `.env` и
оставить то, что приложение меняет. Переменная, которой нет в `.env`, получает то же значение
по умолчанию, так что нужны только изменённые строки. Четыре из них — не простые значения:
у `SCONCUR_RABBITMQ_DSN` значения по умолчанию нет, показан пример; `SCONCUR_WS_PATH` и
`SCONCUR_WS_BUS_DSN` собираются из других переменных так же, как их собирает конфиг (`.env`
раскрывает `${...}`, и только из строк выше); `SCONCUR_TASKS_LOCK_PATH` закомментирована,
потому что её значение по умолчанию вычисляет `storage_path()`, а пустое значение — не оно.

```dotenv
SCONCUR_PANEL_HOST=http://127.0.0.1:28081/api/stats

# The master (supervisor)
SCONCUR_HTTP_PANEL_PORT=28081
SCONCUR_HTTP_ADMIN_TOKEN=
SCONCUR_HTTP_NAME=sconcur-http-server
SCONCUR_HTTP_ROTATE_DAYS=3
SCONCUR_HTTP_LOG_TO=both
SCONCUR_HTTP_RESTART_POLICY=always
SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS=10000
SCONCUR_HTTP_RESTART_BACKOFF_MS=200
SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS=30000
SCONCUR_HTTP_WATCHDOG_TIMEOUT_MS=60000
SCONCUR_HTTP_READY_TIMEOUT_MS=30000
SCONCUR_HTTP_ACCESS_LOG=file

# The http group and its server
SCONCUR_HTTP_WORKER_COUNT=2
SCONCUR_HTTP_ADDRESS=0.0.0.0:28080
SCONCUR_HTTP_MAX_REQUESTS=0
SCONCUR_HTTP_MAX_CONCURRENCY=0
SCONCUR_HTTP_MAX_REQUEST_BODY=10485760
SCONCUR_HTTP_READ_HEADER_TIMEOUT_MS=10000
SCONCUR_HTTP_READ_TIMEOUT_MS=30000
SCONCUR_HTTP_WRITE_TIMEOUT_MS=30000
SCONCUR_HTTP_IDLE_TIMEOUT_MS=60000
SCONCUR_HTTP_HANDLER_TIMEOUT_MS=60000
SCONCUR_HTTP_SERVER_SHUTDOWN_TIMEOUT_MS=5000

# The rabbitmq group and its consumers; below 1 worker the group is left out
SCONCUR_RABBITMQ_WORKER_COUNT=0
SCONCUR_RABBITMQ_QUEUE=default
SCONCUR_RABBITMQ_QUEUE_CONSUMERS=1
SCONCUR_RABBITMQ_PREFETCH_COUNT=1
SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS=0
SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE=false
SCONCUR_RABBITMQ_MAX_MESSAGES=0
SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS=0
SCONCUR_RABBITMQ_MAX_MEMORY_BYTES=0
SCONCUR_RABBITMQ_MEMORY_MB=128
SCONCUR_RABBITMQ_CONNECTION=sconcur_rabbitmq
SCONCUR_RABBITMQ_TRIES=1
SCONCUR_RABBITMQ_BACKOFF=0
# no default: the broker the queue and the ws bus run on
SCONCUR_RABBITMQ_DSN=amqp://guest:guest@127.0.0.1:5672/%2f

# The ws group and its server; below 1 worker the group is left out
SCONCUR_WS_WORKER_COUNT=0
SCONCUR_WS_ADDRESS=0.0.0.0:28090
SCONCUR_WS_HANDSHAKE_TIMEOUT_MS=10000
SCONCUR_WS_IDLE_TIMEOUT_MS=0
SCONCUR_WS_WRITE_TIMEOUT_MS=30000
SCONCUR_WS_PING_INTERVAL_MS=30000
SCONCUR_WS_MAX_MESSAGE_BYTES=1048576
SCONCUR_WS_MAX_CONCURRENCY=0
SCONCUR_WS_MAX_CONNECTIONS=0
SCONCUR_WS_SHUTDOWN_TIMEOUT_MS=10000
SCONCUR_WS_PREEMPTION_QUANTUM_MS=0

# The WebSocket protocol
SCONCUR_WS_APP_KEY=
SCONCUR_WS_APP_SECRET=
SCONCUR_WS_PATH_PREFIX=/app
SCONCUR_WS_PATH=${SCONCUR_WS_PATH_PREFIX}/${SCONCUR_WS_APP_KEY}
SCONCUR_WS_ACTIVITY_TIMEOUT_SECONDS=120
SCONCUR_WS_MAX_CHANNELS_PER_CONNECTION=100
SCONCUR_WS_CLIENT_EVENTS=false
SCONCUR_WS_CLIENT_EVENTS_PER_MINUTE=60
SCONCUR_WS_BUS_DRIVER=amqp
SCONCUR_WS_BUS_DSN=${SCONCUR_RABBITMQ_DSN}
SCONCUR_WS_BUS_EXCHANGE=sconcur.ws
SCONCUR_WS_BUS_READ_TIMEOUT_SECONDS=5.0
SCONCUR_WS_BUS_REOPEN_BACKOFF_MS=1000
SCONCUR_WS_PRESENCE_STORE=auto
SCONCUR_WS_PRESENCE_TTL_SECONDS=3600
SCONCUR_WS_PRESENCE_CACHE_PREFIX=sconcur:ws:presence

# The task pool
SCONCUR_TASKS_CONTROL_KEY=sconcur:tasks:control
# default storage_path('sconcur/runtime/tasks.lock'); an absolute path when set
#SCONCUR_TASKS_LOCK_PATH=
SCONCUR_TASKS_MEMORY_MB=256
SCONCUR_TASKS_SLEEP_CHUNK_MS=250
SCONCUR_TASKS_PREEMPTION_QUANTUM_MS=0
SCONCUR_TASKS_REPORT_TICKS=true
SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS=20
SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS=30000

# Filesystem
SCONCUR_FILESYSTEM_FILES=false
SCONCUR_FILESYSTEM_TIMEOUT_MS=0
```

## Общие

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_PANEL_HOST` | `http://127.0.0.1:28081/api/stats` | откуда дашборд демо читает статистику мастера; сам пакет её не читает |

Переключателя у coroutine-scoped приложения нет и определения режима тоже: провайдер
ставит адаптеры в любом процессе.

## Мастер (supervisor)

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_PANEL_PORT` | `28081` | порт телеметрия-панели (0 = выкл) |
| `SCONCUR_HTTP_ADMIN_TOKEN` | `` (пусто) | Bearer-токен панели (пусто = выкл) |
| `SCONCUR_HTTP_NAME` | `sconcur-http-server` | имя сервера (lock/state/log файлы) |
| `SCONCUR_HTTP_ROTATE_DAYS` | `3` | ротация логов, дней |
| `SCONCUR_HTTP_LOG_TO` | `both` | куда логировать (`file`/`stdout`/`both`) |
| `SCONCUR_HTTP_RESTART_POLICY` | `always` | политика рестарта воркеров (`always`/`on-failure`/`never`) |
| `SCONCUR_HTTP_SHUTDOWN_TIMEOUT_MS` | `10000` | таймаут graceful-остановки воркера, мс |
| `SCONCUR_HTTP_RESTART_BACKOFF_MS` | `200` | стартовый backoff рестарта, мс |
| `SCONCUR_HTTP_MAX_RESTART_BACKOFF_MS` | `30000` | макс. backoff рестарта, мс |
| `SCONCUR_HTTP_WATCHDOG_TIMEOUT_MS` | `60000` | сколько PHP-поток воркера может не возвращаться в планировщик, прежде чем мастер убьёт и заменит воркер; `0` — выкл, иначе не меньше `5000` |
| `SCONCUR_HTTP_READY_TIMEOUT_MS` | `30000` | воркер, умерший раньше, чем сообщил, что поднялся, и в пределах этого времени, не стартовал; не меньше `1000` |
| `SCONCUR_HTTP_ACCESS_LOG` | `file` | куда воркеры пишут строки access-лога: `file` (каждый воркер в `<name>-access-YYYY-MM-DD.log` в `logDir`, удаляется вместе с журналом через `rotateDays`), `stdout` (в журнал мастера; трубы всех воркеров читает один процесс, и под большой нагрузкой строки теряются), `off` |

Последние семь — значения по умолчанию для каждой группы: группа может задать любое из
них сама, как группа `tasks` задаёт `restartPolicy` и `shutdownTimeoutMs`.

Не из ENV: `runtimeDir`/`logDir`=`storage_path('sconcur/runtime'|'sconcur/logs')` и
`env` — переменные окружения, которые каждый воркер получает поверх окружения мастера; они
выставляются в воркере сразу после fork (`putenv`, `$_ENV`, `$_SERVER`). Собственный `env`
группы ложится поверх этого списка, и её значение побеждает:

```php
'master' => [
    'env'    => ['LOG_CHANNEL' => 'stderr'],
    'groups' => [
        [
            'name' => 'tasks',
            'role' => 'tasks',
            'env'  => ['LOG_CHANNEL' => 'daily'], // tasks workers: LOG_CHANNEL=daily
        ],
    ],
],
```

Переменную, заданную так, видят `getenv()` в воркере и то, что воркер запускает.
Конфигурация, которую приложение загрузило в мастере, в воркере заново не читается, поэтому
вызовы `env()` в `config/*.php` сохраняют значения, которые видел мастер.

## Группы

Один мастер супервизит несколько непохожих пулов под одним локом и одним журналом,
поэтому `role`, `workerCount` и `server` живут не на верхнем уровне конфига, а в элементе
списка `groups`.

`sconcur:servers:master:start` строит мастер в своём процессе после загрузки приложения, и
каждый воркер — fork этого процесса. `role` группы — то, что выполняют её воркеры: `http`,
`rabbitmq`, `ws` или `tasks`. Блок `server` — настройки сервера или консьюмера, которого
строит каждый воркер: он превращается во флаги `--name=value`, их читают
`HttpServer::fromArgs`, `QueueConsumer::fromArgs` и `WsServer::fromArgs`; всё, что не
скаляр (список очередей), идёт в JSON.

Команды `sconcur:servers:http:start`, `sconcur:servers:rabbitmq:start` и
`sconcur:servers:ws:start` запускают сервер без мастера. Они
объявляют те же флаги, а если ни одного не передано, берут блок `server` группы своей
роли. Группа ищется по роли, а не по имени, — иначе переименование группы тихо оставило
бы standalone-запуск на дефолтах библиотеки.

Каждый сервер биндит адрес с `SO_REUSEPORT`: воркеры группы делят порт, и ядро
распределяет соединения между ними. Настройки для этого нет.

То, что приложение делает при загрузке, происходит один раз, в процессе мастера, и каждый
воркер это наследует. Отсюда два следствия. Во время загрузки нельзя делать асинхронных
вызовов: запрос через `sconcur_mysql` или команда Redis-клиента `sconcur` в
сервис-провайдере запускают рантайм расширения, а процесс с ним не может форкать — мастер
откажется стартовать и назовёт причину. И соединение, открытое при загрузке, было бы общим
для всех воркеров, а не скопированным, поэтому команда запуска закрывает соединения с базой
и Redis, которые приложение открыло, до fork; каждый воркер открывает свои при первом
запросе. Настройку на уровне воркера — в слушатель [`WorkerForked`](#workerforked).

`workerCount` меньше единицы — это и есть способ выключить пул: группа не попадает в
конфиг мастера вовсе. Ноль так не работает: для мастера `workerCount: 0` означает воркер
на ядро (`WorkerGroup`, `Cpu::count()`).

## Группа `http`

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_WORKER_COUNT` | `2` | число воркеров группы (0 = по числу ядер) |

## HTTP-сервер

Блок `server` группы `http`.

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_HTTP_ADDRESS` | `0.0.0.0:28080` | адрес прослушивания |
| `SCONCUR_HTTP_MAX_REQUESTS` | `0` | стоп после N запросов (0 = ∞) |
| `SCONCUR_HTTP_MAX_CONCURRENCY` | `0` | макс. одновременных запросов (0 = ∞) |
| `SCONCUR_HTTP_MAX_REQUEST_BODY` | `10485760` | лимит тела запроса, байт |
| `SCONCUR_HTTP_READ_HEADER_TIMEOUT_MS` | `10000` | таймаут чтения заголовков, мс |
| `SCONCUR_HTTP_READ_TIMEOUT_MS` | `30000` | таймаут чтения, мс |
| `SCONCUR_HTTP_WRITE_TIMEOUT_MS` | `30000` | таймаут записи, мс |
| `SCONCUR_HTTP_IDLE_TIMEOUT_MS` | `60000` | idle-таймаут keep-alive, мс |
| `SCONCUR_HTTP_HANDLER_TIMEOUT_MS` | `60000` | таймаут обработки запроса, мс |
| `SCONCUR_HTTP_SERVER_SHUTDOWN_TIMEOUT_MS` | `5000` | таймаут остановки сервера, мс |

## Группа `rabbitmq` и её консьюмеры

Как устроен пул — в [queue.ru.md](queue.ru.md).

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_RABBITMQ_WORKER_COUNT` | `0` | процессов в пуле; меньше `1` — группа не попадает в конфиг мастера |
| `SCONCUR_RABBITMQ_QUEUE` | `default` | очередь, которую читает пул |
| `SCONCUR_RABBITMQ_QUEUE_CONSUMERS` | `1` | вес этой очереди — сколько консьюмеров она получает |
| `SCONCUR_RABBITMQ_PREFETCH_COUNT` | `1` | неподтверждённых сообщений на консьюмера |
| `SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS` | `0` | дедлайн на одно сообщение в обработчике; `0` — нет |
| `SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE` | `false` | возвращать упавшее сообщение в очередь вместо dead-letter |
| `SCONCUR_RABBITMQ_MAX_MESSAGES` | `0` | дренировать и выйти после N сообщений |
| `SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS` | `0` | дренировать и выйти через N секунд |
| `SCONCUR_RABBITMQ_MAX_MEMORY_BYTES` | `0` | дренировать и выйти по размеру кучи |
| `SCONCUR_RABBITMQ_MEMORY_MB` | `128` | `memory` в `WorkerOptions` Laravel, МиБ; `Worker::process()` его не проверяет — консьюмер останавливает лимит `SCONCUR_RABBITMQ_MAX_MEMORY_BYTES` |
| `SCONCUR_RABBITMQ_CONNECTION` | `sconcur_rabbitmq` | соединение `config/queue.php`, на котором идут джобы |
| `SCONCUR_RABBITMQ_TRIES` | `1` | попыток до `failed_jobs` |
| `SCONCUR_RABBITMQ_BACKOFF` | `0` | пауза перед повтором, секунд |
| `SCONCUR_RABBITMQ_DSN` | — | брокер, в виде `amqp://user:pass@host:5672/%2f` |

Каркас описывает одну очередь, потому что больше он знать не может. Список любой длины и
его веса приложение пишет в `sconcur.queue.rabbitmq.queues` опубликованного файла, и этот
же список объявляет `sconcur:rabbitmq:declare`.

## Группа `ws`

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_WS_WORKER_COUNT` | `0` | воркеров в группе; меньше 1 убирает группу из конфига |

## WebSocket-сервер

Блок `server` группы `ws`.

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_WS_ADDRESS` | `0.0.0.0:28090` | адрес прослушивания |
| `SCONCUR_WS_PATH` | `/app/${SCONCUR_WS_APP_KEY}` | точный путь, на котором принимается Upgrade; пустая строка — любой |
| `SCONCUR_WS_HANDSHAKE_TIMEOUT_MS` | `10000` | сколько может занять чтение заголовков Upgrade, мс |
| `SCONCUR_WS_IDLE_TIMEOUT_MS` | `0` | простой между входящими сообщениями (0 — выключено) |
| `SCONCUR_WS_WRITE_TIMEOUT_MS` | `30000` | отправка одного сообщения, мс |
| `SCONCUR_WS_PING_INTERVAL_MS` | `30000` | частота keepalive-пинга сервера (0 — выключено) |
| `SCONCUR_WS_MAX_MESSAGE_BYTES` | `1048576` | предел размера входящего сообщения |
| `SCONCUR_WS_MAX_CONCURRENCY` | `0` | соединений в обслуживании одновременно (0 — ∞) |
| `SCONCUR_WS_MAX_CONNECTIONS` | `0` | остановиться после N обслуженных соединений (0 — ∞) |
| `SCONCUR_WS_SHUTDOWN_TIMEOUT_MS` | `10000` | дедлайн мягкой остановки, мс |
| `SCONCUR_WS_PREEMPTION_QUANTUM_MS` | `0` | квант вытеснения во время обслуживания; `0` — выключено |

`handlerTimeoutMs` в списке нет и жёстко равен `0`. Здесь это дедлайн на всю жизнь
соединения, а не на один кадр, поэтому любое значение выше нуля рвёт всех клиентов по
таймеру — ws-пулу не нужно ни одно из них.

Путь сравнивается без query-строки, поэтому `/app/{key}?protocol=7&client=js` совпадает,
а чужой ключ получает `404` на рукопожатии, ещё до PHP.

`SCONCUR_WS_PREEMPTION_QUANTUM_MS` равен `0`, и вытеснение выключено — это значение по
умолчанию самой библиотеки. Когда оно включено, расширение паркует обработчик по таймеру, и
между любыми двумя его операторами может выполниться другая корутина. Часть состояния PHP
принадлежит процессу, а не корутине — `json_last_error()`, `preg_last_error()`,
`error_get_last()`, буферы вывода, обработчики ошибок, — и сосед, выполнившийся в промежутке,
его перезаписывает: обработчик читает результат чужого соединения. Движок проверяет таймер
сразу после возврата из внутренней функции, поэтому переключение между `json_decode()` и
`json_last_error()` — ожидаемый случай, а не редкий. Цена `0`: обработчик, который считает и
ничего не ждёт, держит остальные соединения своего воркера, пока не закончит. Квант больше
нуля — для воркера, чьи обработчики и их библиотеки этого состояния не читают; полный список
с безопасной формой для каждого случая — в `vendor/sconcur/sconcur/docs/coroutine-switching.ru.md`
(Состояние процесса).

У групп `http` и `rabbitmq` такого ключа нет, и они работают на том же значении по умолчанию
по той же причине. `preemptionQuantumMs` в их блоке `server` включает вытеснение: обе команды
этот флаг объявляют.

## Протокол WebSocket

Секция `sconcur.ws`. Как устроен пул — в [websocket.ru.md](websocket.ru.md).

| ENV | Дефолт | Назначение |
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

`SCONCUR_WS_PRESENCE_STORE` со значением `memory` верен ровно пока процесс один. При пуле
он не неполон, а неверен — каждый воркер отвечает своими подписчиками; `auto` берёт там
`cache`, а о явном `memory` каждый ws-воркер при старте пишет в лог.

## Пул задач

Как устроен пул — в [task-pool.ru.md](task-pool.ru.md).

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_TASKS_CONTROL_KEY` | `sconcur:tasks:control` | ключ кэша, через который до пула доходят `stop` и `restart` |
| `SCONCUR_TASKS_LOCK_PATH` | `storage/sconcur/runtime/tasks.lock` | префикс пути flock, по файлу на задачу (`<path>.<task>`); не даёт стартовать второй копии задачи |
| `SCONCUR_TASKS_MEMORY_MB` | `256` | лимит памяти процесса, по большему из кучи PHP и RSS; за ним — выход с `EXIT_RESTART` |
| `SCONCUR_TASKS_SLEEP_CHUNK_MS` | `250` | насколько мелко режется пауза, то есть как быстро пул замечает сигнал |
| `SCONCUR_TASKS_PREEMPTION_QUANTUM_MS` | `0` | автоматическое переключение корутин; `0` — выключено |
| `SCONCUR_TASKS_REPORT_TICKS` | `true` | показывать тики в секции `consumers` панели |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_SECONDS` | `20` | сколько ждать текущие тики, прежде чем размотать группу |
| `SCONCUR_TASKS_SHUTDOWN_TIMEOUT_MS` | `30000` | сколько мастер ждёт воркера пула; обязан быть больше предыдущего |

Группа пула — один воркер и `restartPolicy: on-failure` вместо унаследованного от мастера
`always`, чтобы `sconcur:tasks:stop`, который выходит с нулём, не отменялся заменой через
секунду.

## Redis

Своих переменных окружения у клиента `sconcur` и кэш-стора `sconcur_redis` нет. Их
настройки — ключи секции `redis` в `config/database.php` и `cache.stores.sconcur_redis`, а
приложение заполняет их из любых переменных; ключи перечислены в [redis.ru.md](redis.ru.md).

## Файловая система

Как это устроено — в [filesystem.ru.md](filesystem.ru.md).

| ENV | Дефолт | Назначение |
|---|---|---|
| `SCONCUR_FILESYSTEM_FILES` | `false` | перевести фасад `File` на фичу Files для `copy`, `move`, `hash` и `replace` внутри корутины |
| `SCONCUR_FILESYSTEM_TIMEOUT_MS` | `0` | дедлайн одного такого вызова, мс; `0` — нет |

Своих ENV у диска `sconcur_local` нет: его `timeout_ms` — ключ его записи в
`config/filesystems.php`.

## Слушатели

Секция `sconcur.listeners` сопоставляет класс события со списком его слушателей — в том же
виде, что принимает `EventServiceProvider::$listen`. Сервис-провайдер регистрирует их в
каждом процессе.

```php
use App\Listeners\PrepareWorker;
use App\Listeners\ReportWorkerWatchdog;
use SConcur\Laravel\Servers\Events\WorkerForked;
use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;

'listeners' => [
    WorkerForked::class => [
        PrepareWorker::class,
    ],
    WorkerWatchdogTriggered::class => [
        ReportWorkerWatchdog::class,
    ],
],
```

| Событие | Где возникает | Когда |
|---|---|---|
| `SConcur\Laravel\Servers\Events\WorkerForked` | воркер мастера | сразу после fork, до того как воркер построит свой сервер, консьюмер или пул задач |
| `SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered` | процесс мастера | watchdog мастера действует на воркер, чей PHP-поток перестал возвращаться в планировщик |

### `WorkerForked`

Место для того, что нужно сделать один раз на воркер, а не один раз на мастер: каждый
воркер стартует с копией того, что загрузил процесс мастера, поэтому значение, посчитанное
при загрузке приложения, — id, метка времени, seed — у всех одинаковое.
`WorkerForked::$workerContext` — это `SConcur\Worker\WorkerContext` библиотеки: `group`,
`slot` (с `0`) и `masterPid`.

```php
namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use SConcur\Laravel\Servers\Events\WorkerForked;

class PrepareWorker
{
    public function handle(WorkerForked $workerForked): void
    {
        Log::withContext([
            'worker' => $workerForked->workerContext->group . '#' . $workerForked->workerContext->slot,
        ]);
    }
}
```

Подойдёт и замыкание, зарегистрированное в сервис-провайдере:

```php
Event::listen(static function (WorkerForked $workerForked): void {
    // ...
});
```

Слушатель выполняется в воркере, поэтому асинхронные вызовы в нём допустимы. Он
выполняется до того, как воркер сообщит, что поднялся: исключение, которое он выпустит,
завершает воркер, а когда так завершаются все воркеры группы, мастер останавливается с
кодом `70`.

### `WorkerWatchdogTriggered`

`WorkerWatchdogTriggered::$watchdogEvent` — это `SConcur\Worker\WatchdogEvent` библиотеки:
`event`, `group`, `slot`, `pid`, `ageSeconds` и `watchdogTimeoutMs`. Одно убийство даёт до
трёх событий, по одному на `event`: `HeartbeatLost`, когда воркеру отправлен `SIGTERM`,
`KillEscalated`, когда он не вышел и ему отправлен `SIGKILL`, `KillSurvived`, когда процесс
пережил `SIGKILL` и его слот остаётся пустым. `ageSeconds` заполнен только у первого.
Событие поднимает `sconcur:servers:master:start`; без слушателей мастер только пишет журнал
и считает `watchdogKills` для панели телеметрии.

Слушатель, отправляющий убийство в трекер ошибок:

```php
namespace App\Listeners;

use RuntimeException;
use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;

class ReportWorkerWatchdog
{
    public function handle(WorkerWatchdogTriggered $workerWatchdogTriggered): void
    {
        $watchdogEvent = $workerWatchdogTriggered->watchdogEvent;

        report(new RuntimeException(sprintf(
            'watchdog %s: worker %d of group "%s" #%d (limit %d ms)',
            $watchdogEvent->event->value,
            $watchdogEvent->pid,
            $watchdogEvent->group,
            $watchdogEvent->slot,
            $watchdogEvent->watchdogTimeoutMs,
        )));
    }
}
```

Слушатель выполняется внутри цикла надзора мастера, и пока он работает, мастер ни за чем
не следит, поэтому он должен быть коротким. Асинхронных вызовов в нём тоже быть не должно —
ни `sconcur_mysql`, ни Redis-клиента `sconcur`, ни HTTP-клиента расширения: процесс мастера
их отвергает, потому что процесс с запущенным рантаймом расширения не может форкать
воркеры. Всё, что он бросит, мастер пишет в журнал и отбрасывает.
