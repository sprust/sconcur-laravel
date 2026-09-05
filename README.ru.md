[English](README.md) | Русский

# SConcur Laravel

Laravel-интеграция для [SConcur](https://github.com/sprust/sconcur): конкурентный
HTTP-воркер и coroutine-scoped приложение.

`AsyncApplication` подключается в `bootstrap/app.php` как подкласс
`Illuminate\Foundation\Application`. В воркере на каждый фибер изолированы
`request`, `auth`, `session`, `cookie`, наложение конфигурации, текущий маршрут, локаль,
`View::share` и `defer`. Вне корутины поведение обычное: один вызывающий, один экземпляр.

Соединение `sconcur_mysql` даёт ORM неблокирующий MySQL и транзакции на корутину.
У соединения `mysql` поверх PDO есть своё ограничение на транзакции — см.
[Базу данных](docs/database.ru.md).

## Зачем

SConcur исполняет каждый HTTP-запрос в отдельном PHP-Fiber конкурентно в одном процессе.
Модель Octane — клонирование `$app` и подмена глобального контейнера — под такой
конкуренцией не fiber-safe. Этот пакет держит состояние запроса в **контексте корутины**,
не подменяя глобальное состояние и не клонируя приложение.

Coroutine-scoped модель (`AsyncApplication` плюс состояние на корутину) взята из
[yangusik/laravel-spawn](https://github.com/yangusik/laravel-spawn), где она сделана
поверх PHP TrueAsync, и адаптирована на стандартный PHP плюс SConcur (`Context::current()`
вместо TrueAsync-контекста). PSR-7 мост воркера следует модели Laravel Octane.

## Документация

| Документ | О чём |
|---|---|
| [Установка](docs/installation.ru.md) | требования, расширение, конфиг, `bootstrap/app.php`, первый запуск |
| [Структура](docs/layout.ru.md) | что где лежит в репозитории |
| [Конфигурация (ENV)](docs/configuration.ru.md) | все переменные окружения и их дефолты |
| [База данных](docs/database.ru.md) | соединение `sconcur_mysql`, транзакции на корутину и ограничение PDO-соединения |
| [Очередь](docs/queue.ru.md) | драйвер `sconcur_rabbitmq` и пул консьюмеров |
| [WebSocket](docs/websocket.ru.md) | ws-пул: примеры, протокол, подписи каналов, шина, presence |
| [Пул задач](docs/task-pool.ru.md) | периодические задачи, по корутине на каждую |
| [Разработка](docs/development.ru.md) | docker-окружение, `make`, тесты |
| [Демо-приложение](demo/README.ru.md) | что показывает демо и как его поднять |

Каждый документ существует на английском и на русском; переключатель — в первой строке.

## Среды выполнения

Их четыре, и все они — группы одного супервизор-процесса, мастера SConcur
(`sconcur:servers:master:start`), описанные как `groups` в `config/sconcur.php`.

| Среда | Команда в foreground | Документ |
|---|---|---|
| HTTP-сервер | `sconcur:servers:http:start` | [Установка](docs/installation.ru.md) |
| Консьюмеры очереди | `sconcur:servers:rabbitmq:start` | [Очередь](docs/queue.ru.md) |
| WebSocket | `sconcur:servers:ws:start` | [WebSocket](docs/websocket.ru.md) |
| Периодические задачи | `sconcur:tasks:start` | [Пул задач](docs/task-pool.ru.md) |

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
обрабатываются конкурентно; для прод-многопроцессного режима — `master:start`
(плюс `reusePort`).

## Демо-приложение

В репозитории лежит демо — минимальное Laravel-приложение, которое отдаёт сам мастер
SConcur. Оно нужно, чтобы пакет можно было посмотреть в работе, а не только прочитать
про него.

```bash
make setup
```

Дальше — `http://localhost:48081` (порт меняется через `APP_PORT` в `.env`). Что видно
на странице и что стоит попробовать руками — [demo/README.ru.md](demo/README.ru.md).

Приложению, которое ставит пакет обычным способом, ничего из этого не нужно: там
провайдер находится автообнаружением, а конфиг публикуется через
`vendor:publish --tag=sconcur-laravel`.
