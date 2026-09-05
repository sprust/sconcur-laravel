English | [Русский](layout.ru.md)

# Layout

What lies where in the repository.

```
config/sconcur.php        — the config (panel_host, scoped_services, master + groups, queue, ws, tasks)
src/SConcurServiceProvider — the provider (commands + wiring the adapters into the worker)
src/Console/              — artisan commands
src/Servers/              — MasterRunner (a wrapper over SConcur\Worker\MasterCli)
src/Queue/Rabbitmq/       — the queue driver and the consumer pool (Connector, Queue, Job, ConsumerRunner)
src/Database/Mysql/       — the sconcur_mysql connection (Connector, Connection, Dsn, TransactionStack)
src/Tasks/                — the periodic task pool (TaskPool, TaskPoolController, TaskRegistry,
                            CooperativeSleeper, TaskPoolTelemetry + TaskPoolMetrics)
src/Tasks/Control/        — the control channel through the cache (stop/restart from another container)
src/Http/                 — HttpServerRunner + LaravelHttpHandler (build + serve)
src/Ws/                   — the WebSocket pool (WsServerRunner, ConnectionHandler,
                            ConnectionRegistry, Protocol, Auth, Bus, Presence, Broadcasting)
src/Foundation/           — AsyncApplication, ScopedService, ScopedServiceProxy
src/Config/               — AsyncConfig (a per-coroutine config()->set overlay)
src/Events/               — AsyncDispatcher (per-coroutine defer())
src/Routing/              — AsyncRouter (per-coroutine current route/request)
src/Translation/          — AsyncTranslator (per-coroutine locale)
src/View/                 — AsyncViewFactory (per-coroutine View::share)
docs/                     — this documentation, one topic per bilingual pair

demo/                     — the demo application the master serves
workbench/                — the testbench application the tests run against
tests/                    — the package's tests
docker/                   — the images, nginx and supervisor of the development environment
```

The application is coroutine-scoped always, with no switch and no mode detection. The
adapters are installed in every process, and the container always resolves
`request`/`session`/`auth`/`cookie` out of the coroutine context.

Outside a coroutine this costs nothing: the context collapses to the process root, that
is to one store for one caller — which is exactly what the stock implementations are. All
request state — `request`, `auth`, `session`, `cookie`, the config overlay, the current
route, the locale, `View::share`, `defer` — lives in the coroutine context.

The coroutine context comes from the library: `SConcur\Context\Context::current()`
(`find/has/set/forget`). Its semantics are in
`vendor/sconcur/sconcur/docs/coroutine-context.md`.
