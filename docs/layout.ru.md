[English](layout.md) | Русский

# Структура

Что где лежит в репозитории.

```
config/sconcur.php             — конфиг (panel_host, scoped_services, master + groups, queue, ws, tasks)
src/SConcurServiceProvider.php — провайдер (команды + проводка адаптеров в воркере)
src/Console/                   — артизан-команды
src/Servers/                   — MasterRunner (обёртка над SConcur\Worker\MasterCli)
src/Queue/Rabbitmq/            — драйвер очереди и консьюмер-пул (Connector, Queue, Job, ConsumerRunner)
src/Database/                  — TransactionStore (уровень вложенности транзакции, по корутинам)
src/Database/Mysql/            — соединение sconcur_mysql (Connector, Connection, Dsn, TransactionStack)
src/Tasks/                     — пул периодических задач (TaskPool, TaskPoolController, TaskRegistry,
                                 CooperativeSleeper, TaskPoolTelemetry + TaskPoolMetrics)
src/Tasks/Control/             — канал управления через кэш (stop/restart из другого контейнера)
src/Http/                      — HttpServerRunner + LaravelHttpHandler (build + serve)
src/Ws/                        — WebSocket-пул (WsServerRunner, ConnectionHandler,
                                 ConnectionRegistry, Protocol, Auth, Bus, Presence, Broadcasting)
src/Foundation/                — AsyncApplication, ScopedService, ScopedServiceProxy
src/Config/                    — AsyncConfig (overlay config()->set per-coroutine)
src/Events/                    — AsyncDispatcher (defer() per-coroutine)
src/Routing/                   — AsyncRouter (current route/request per-coroutine)
src/Translation/               — AsyncTranslator (локаль per-coroutine)
src/View/                      — AsyncViewFactory (View::share per-coroutine)
docs/                          — эта документация, по теме на двуязычную пару

demo/                          — демо-приложение, которое отдаёт мастер
workbench/                     — приложение testbench, на котором идут тесты
tests/                         — тесты пакета
docker/                        — образы, nginx и supervisor окружения разработки
```

Приложение всегда coroutine-scoped, без переключателей и без определения режима.
Адаптеры ставятся в любом процессе, и контейнер всегда резолвит `request`/`session`/
`auth`/`cookie` из контекста корутины.

Вне корутины это ничего не стоит: контекст сводится к корню процесса, то есть к одному
хранилищу для одного вызывающего — тому же, чем являются штатные реализации. Всё
состояние запроса — `request`, `auth`, `session`, `cookie`, наложение конфигурации,
текущий маршрут, локаль, `View::share`, `defer` — живёт в контексте корутины.

Контекст корутины берётся из библиотеки: `SConcur\Context\Context::current()`
(`find/has/set/forget`). Семантика — `vendor/sconcur/sconcur/docs/coroutine-context.ru.md`.
