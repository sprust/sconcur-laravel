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

Клиент `sconcur` переводит `Redis::` на фичу Redis из SConcur, а кэш-стор `sconcur_redis` —
`Cache::` — см. [Redis](docs/redis.ru.md).

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
| [HTTP-ответы](docs/http.ru.md) | как ответ попадает на сервер, потоковые ответы chunked-ом, буферы вывода в корутинах |
| [База данных](docs/database.ru.md) | соединение `sconcur_mysql`, транзакции на корутину и ограничение PDO-соединения |
| [Redis](docs/redis.ru.md) | Redis-клиент `sconcur`, кэш-стор `sconcur_redis` и то, от чего они отказываются |
| [Файловая система](docs/filesystem.ru.md) | диск `sconcur_local` и фасад `File` на фиче Files |
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
| HTTP-сервер | `sconcur:servers:http:start` | [Установка](docs/installation.ru.md), [HTTP-ответы](docs/http.ru.md) |
| Консьюмеры очереди | `sconcur:servers:rabbitmq:start` | [Очередь](docs/queue.ru.md) |
| WebSocket | `sconcur:servers:ws:start` | [WebSocket](docs/websocket.ru.md) |
| Периодические задачи | `sconcur:tasks:start` | [Пул задач](docs/task-pool.ru.md) |

## Артизан-команды

`sconcur:servers:master:start` строит `WorkerMaster` библиотеки прямо из
`config('sconcur.master')` в своём процессе, после загрузки приложения. Каждый воркер —
fork этого процесса: он стартует с уже загруженным приложением и выполняет роль своей
группы — HTTP-сервер, пул консьюмеров очереди, WebSocket-сервер или пул задач. `stop` и
`status` находят запущенный мастер через `MasterControl` по его `runtimeDir` и `name`.

```
sconcur:servers:master:start|stop                 # супервизор: строит мастер, форкает воркеры
sconcur:servers:master:status [--group=NAME]      # статус: все пулы или один
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

Reload нет: мастер держит загруженное им приложение, поэтому новый код или новый конфиг —
это новый мастер: `master:stop`, и супервизор процессов запускает его заново.

Команды `*:start` четырёх сред запускают одну из них в foreground без мастера — с флагами
из блока `server` своей группы, если своих не передано. Обработчик coroutine-safe
(per-fiber контекст), запросы внутри процесса обрабатываются конкурентно; для
прод-многопроцессного режима — `master:start`.

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
