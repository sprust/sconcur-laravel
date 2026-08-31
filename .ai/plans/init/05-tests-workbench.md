# Шаг 5. Тесты: `workbench/` + `tests/`

Стенд повторяет `slogger.laravel`: `orchestra/testbench` + `workbench/` как приложение,
на котором крутятся тесты пакета. Демо (`demo/`) тестами не используется — у них разные
задачи, и путать их значит либо ломать демо ради тестов, либо наоборот.

## 5.1 Инфраструктура

**`testbench.yaml`**

```yaml
workbench:
  discovers:
    config: true
    routes: true
```

`slogger.laravel` включает только `config`; нам нужны ещё и маршруты — часть тестов
проверяет прохождение запроса через `AsyncRouter`.

**`phpunit.xml`** — по образцу `slogger.laravel`:

```xml
bootstrap="tests/bootstrap.php"
<testsuite name="Feature"> tests/Feature </testsuite>
```

Секция `<php>`: `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`, плюс креды mysql и
rabbitmq из docker-сети — интеграционные тесты ходят в живые сервисы, они для того и
подняты в `in-memory` режиме.

**`tests/bootstrap.php`** — `require vendor/autoload.php`. Аналог трюка с
`LARAVEL_START` из `slogger.laravel` нам не нужен; если по ходу выяснится, что нужен —
добавить с таким же объясняющим комментарием.

**`workbench/`**

```
workbench/
├── app/
│   ├── Jobs/                 — джобы для тестов очереди
│   ├── Models/               — модель для тестов sconcur_mysql
│   ├── Tasks/                — задачи для тестов пула
│   └── Providers/WorkbenchServiceProvider.php
├── config/sconcur.php        — конфиг пакета для тестов
├── database/migrations/
└── routes/api.php
```

## 5.2 Что покрываем

Приоритет — то, что ломается молча и то, что своими руками написано в пакете. Логику
самой библиотеки `sconcur/sconcur` не дублируем.

### Конфиг и команды

- `sconcur:*` зарегистрированы и видны в `artisan list`.
- Без опубликованного конфига команды сообщают об этом, а не падают на пустом массиве
  (README, «Установка» — заявленное поведение, надо его зафиксировать тестом).
- `config/sconcur.php` → `MasterConfig::fromArray` проходит без исключения;
  `SCONCUR_RABBITMQ_WORKER_COUNT=0` действительно убирает группу `rabbitmq` из
  конфига и **не ломает список групп** — это ровно тот баг, ради которого в конфиге
  стоит `array_values(array_filter(...))` с большим комментарием.
- `masterConfigPath()` пишет тот же массив, которым мастера супервизят.

### Coroutine-scoped адаптеры (`src/Config`, `Events`, `Routing`, `Translation`, `View`)

Для каждого — один тест «две корутины не видят состояние друг друга» и один
«вне корутины ведёт себя как штатная реализация»:

- `AsyncConfig`: `config()->set` в корутине не виден соседней, но базовые значения
  общие;
- `AsyncRouter`: текущий маршрут и запрос — свои у каждой корутины;
- `AsyncTranslator`: `App::setLocale` в корутине не меняет локаль соседней;
- `AsyncViewFactory`: `View::share` изолирован;
- `AsyncDispatcher`: `defer()` выполняется в своей корутине.

Каркас для этих тестов — как `tests/Feature/Concurrency/BaseConcurrencyTestCase.php`
в `slogger.laravel`: базовый кейс, который поднимает `WaitGroup` и чередует корутины.
Посмотреть на него перед написанием своего.

### `AsyncApplication`

- `request`/`session`/`auth`/`cookie` резолвятся из контекста корутины;
- вне корутины — один экземпляр на процесс, как у штатного контейнера;
- фасадные прокси (`FACADE_PROXIED_MAP`) не утекают между корутинами.

### `Database/Mysql`

Интеграционные, на живом mysql:

- `Dsn` собирается из конфига соединения корректно;
- `select`/`insert`/`update`/`delete` через Eloquent;
- транзакция на корутину: две корутины держат свои транзакции, откат одной не
  трогает другую (это главное обещание `CoroutineTransactionsManager` и
  `TransactionStack`);
- `Model::saveOrFail()` открывает транзакцию — путь, который README называет
  неочевидным.

### `Queue/Rabbitmq`

Интеграционные, на живом rabbitmq:

- `sconcur:rabbitmq:declare` объявляет очереди из `sconcur.queue.rabbitmq.queues`,
  повторный запуск безвреден;
- публикация → потребление джобы, `Worker::process()` отрабатывает события;
- совместимость формата с `vladimir-yuldashev/laravel-queue-rabbitmq`: счётчик
  попыток в заголовке `laravel.attempts`, publish в дефолтный обменник с routing key,
  равным имени очереди. README перечисляет три вещи, которые нельзя менять
  односторонне, — на каждую по тесту;
- `later()`/`release()`: создаётся очередь ожидания `<очередь>.wait.<мс>`, сообщение
  возвращается по TTL;
- упавшая джоба попадает в `failed_jobs` (слушатель `JobFailed` вешает
  `ConsumerRunner`, а не `Worker` — регрессия здесь незаметна).

### `Tasks`

- `TaskRegistry` резолвит задачи из контейнера по `tasks.list`;
- `TickResultEnum` выбирает нужную паузу (`Worked`→`busy`, `Idle`→`idle`,
  `Failed`→`backoff`);
- `ControlChannel`: `stop`/`restart` кладутся в кэш и забираются контроллерным тиком,
  в том числе адресно по `--task=NAME`;
- `TaskPoolLock` не даёт подняться второму пулу;
- `CooperativeSleeper` режет паузу на куски и прерывается.

### Демо как smoke-тест

Отдельный тест не пишем — вместо него `make setup` заканчивается `curl` по
`/api/health` (шаг 3), а CI прогоняет то же самое ([06-ci-docs.md](06-ci-docs.md)).

## 5.3 Порядок

1. `phpunit.xml`, `tests/bootstrap.php`, `testbench.yaml`, `workbench/` каркас,
   `tests/Feature/BaseTestCase.php`. Проверка: один пустой тест зелёный.
2. Конфиг и команды.
3. Адаптеры + `AsyncApplication` (нужен базовый конкурентный кейс).
4. `Database/Mysql`.
5. `Queue/Rabbitmq`.
6. `Tasks`.

## Открытый вопрос

Интеграционные тесты требуют поднятых mysql и rabbitmq, то есть `make test` без
`make up` не пройдёт. Варианты: (а) так и оставить, как в `sconcur-php`, где тесты
идут только внутри окружения; (б) помечать интеграционные группой и пропускать без
сервисов. Предлагаю (а) — окружение и так требуется для `sconcur.so`.
