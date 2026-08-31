# Шаг 4. Демо-приложение `demo/`

То, что видит человек по адресу `http://localhost:${APP_PORT}` после `make setup`.
Оно же — живая проверка всех трёх рантаймов пакета.

## 4.1 Чем оно является и чем не является

Это минимальный Laravel-скелет, а не результат `laravel new`: Laravel 11+ держит
дефолты конфигов в `vendor/laravel/framework/config`, поэтому в приложении нужны только
те файлы, которые оно переопределяет. Composer-файла у демо нет — оно ходит в корневой
`vendor/` через симлинк (решение 2 в [00-overview.md](00-overview.md)), а свои классы
получает из `autoload-dev` корневого `composer.json` (`Demo\App\` → `demo/app/`).

Это не workbench: workbench собирается testbench-ем и служит только тестам
([05-tests-workbench.md](05-tests-workbench.md)).

## 4.2 Дерево

```
demo/
├── artisan                      — точка входа CLI; её же мастер задаёт как workerScript
├── vendor -> ../vendor          — симлинк (коммитится)
├── bootstrap/
│   ├── app.php                  — сборка AsyncApplication
│   ├── providers.php
│   └── cache/.gitignore
├── config/
│   ├── app.php                  — только то, что переопределяем (name, key, url)
│   ├── database.php             — соединения mysql (PDO) и sconcur_mysql
│   ├── queue.php                — соединение sconcur_rabbitmq
│   └── sconcur.php              — опубликованный конфиг пакета, заполненный под демо
├── app/
│   ├── Http/Controllers/        — DemoController, ConcurrencyController,
│   │                              NoteController, JobController, StatsController
│   ├── Jobs/DemoJob.php
│   ├── Models/Note.php, JobResult.php, Heartbeat.php
│   └── Tasks/HeartbeatTask.php  — реализация SConcur\Laravel\Tasks\TaskInterface
├── database/migrations/         — notes, job_results, heartbeats
├── resources/views/demo.blade.php
├── routes/web.php, routes/api.php
└── storage/                     — app, framework/{cache,sessions,views}, logs,
                                   sconcur/{runtime,logs} — с .gitignore
```

## 4.3 `demo/bootstrap/app.php`

Ключевой файл: приложение должно быть экземпляром
`SConcur\Laravel\Foundation\AsyncApplication`, иначе `request`/`session`/`auth`/`cookie`
не станут per-coroutine и весь смысл пакета пропадёт.

Laravel 11/12 собирает приложение через `Illuminate\Foundation\Configuration\ApplicationBuilder`,
и билдеру можно передать готовый экземпляр:

```php
return (new ApplicationBuilder(new AsyncApplication(dirname(__DIR__))))
    ->withKernels()
    ->withEvents(discover: false)
    ->withCommands()
    ->withRouting(web: __DIR__ . '/../routes/web.php', api: __DIR__ . '/../routes/api.php')
    ->withMiddleware(fn ($middleware) => null)
    ->withExceptions(fn ($exceptions) => null)
    ->create();
```

Проверить по установленной версии фреймворка, что конструктор `ApplicationBuilder`
принимает экземпляр приложения (в 11/12 — да, `Application::configure()` именно так и
делает). Если сигнатура разойдётся — падаем на ручную сборку через
`Illuminate\Foundation\Bootstrap\*`, но сначала пробуем билдер.

`demo/artisan` — стандартный: `require vendor/autoload.php`,
`$app = require bootstrap/app.php`, `$status = $app->handleCommand(new ArgvInput)`.

## 4.4 `demo/config/sconcur.php`

Копия пакетного `config/sconcur.php` (как будто выполнили
`vendor:publish --tag=sconcur-laravel`) с правками под демо:

- `panel_host` → `http://127.0.0.1:` + `SCONCUR_PANEL_PORT` + `/api/stats`;
- группа `http` — 2 воркера (дефолт пакета уже такой, и его комментарий объясняет
  почему: rolling reload должен иметь куда отдать трафик);
- группа `rabbitmq` — включается через `SCONCUR_RABBITMQ_WORKER_COUNT=1` из `.env`,
  очередь `demo` с весом 4;
- `queue.rabbitmq.queues` — `['demo']`, это то, что объявит `sconcur:rabbitmq:declare`;
- `tasks.list` — одна задача:
  ```php
  ['name' => 'heartbeat', 'task' => HeartbeatTask::class, 'idle' => 2, 'busy' => 2, 'backoff' => 5],
  ```
- `tasks.preemption_quantum_ms` — оставить пакетный дефолт `1000`. **Важно:**
  `HeartbeatTask` пишет через `sconcur_mysql`, а не через PDO, поэтому запрет
  преемпции из README (про транзакцию на общем PDO-соединении) к нему не относится.

## 4.5 `demo/config/database.php`

Два соединения, чтобы демо показывало разницу:

- `mysql` — обычное PDO-соединение;
- `sconcur_mysql` — `'driver' => 'sconcur_mysql'` с теми же host/port/database/
  username/password. Точный набор ключей взять из раздела «База данных (`sconcur_mysql`)»
  README (строки конфигурации там расписаны) и сверить с
  `src/Database/Mysql/Connector.php` и `src/Database/Mysql/Dsn.php` — **не угадывать**.

`'default' => env('DB_CONNECTION', 'sconcur_mysql')` — демо по умолчанию работает на
неблокирующем соединении; переключение на `mysql` одной переменной окружения — часть
демонстрации.

Миграции гоняются из контейнера `php`, то есть вне корутины. Это рабочий путь:
провайдер регистрирует драйвер безусловно ровно ради таких случаев (комментарий
`registerDatabaseDriver` в `src/SConcurServiceProvider.php`).

## 4.6 `demo/config/queue.php`

```php
'sconcur_rabbitmq' => [
    'driver' => 'sconcur_rabbitmq',
    'queue'  => env('SCONCUR_RABBITMQ_QUEUE', 'demo'),
    'dsn'    => env('SCONCUR_RABBITMQ_DSN'),
],
```

`SCONCUR_RABBITMQ_DSN` собирается в `.env.example` из тех же кредов rabbitmq:
`amqp://scl_user:_scl_password_567@scl-rabbitmq:5672/%2f`. Точный формат сверить с
`src/Queue/Rabbitmq/Connector.php`.

`'default' => 'sconcur_rabbitmq'`, плюс оставить `sync` для тестов.

## 4.7 Что демонстрирует демо

Одна страница `GET /` (blade) со ссылками и живыми цифрами, плюс API за ней.

| Эндпоинт | Что показывает |
|---|---|
| `GET /` | страница демо: список сценариев, последние заметки, последний heartbeat, результаты джоб |
| `GET /api/health` | `{"ok":true}` — используется в `make setup` и в CI как smoke-check |
| `GET /api/concurrent?n=10&ms=200` | `n` параллельных пауз в одной корутинной группе; в ответе `elapsed_ms` ≈ `ms`, а не `n*ms`. Это самая наглядная демонстрация: тот же обработчик, тот же процесс |
| `GET /api/notes`, `POST /api/notes` | Eloquent поверх `sconcur_mysql` — чтение и запись без блокировки процесса |
| `POST /api/notes/bulk` | несколько вставок конкурентно в одной корутинной группе + транзакция на корутину |
| `POST /api/jobs` | `dispatch(new DemoJob(...))` в очередь `demo`; группа `rabbitmq` мастера её съедает и пишет строку в `job_results` |
| `GET /api/jobs` | что уже обработано — видно, что консьюмер жив |
| `GET /api/stats` | проксирует панель телеметрии мастера (`config('sconcur.panel_host')` + bearer `SCONCUR_HTTP_ADMIN_TOKEN`): воркеры по группам, in-flight, память |

`HeartbeatTask::tick()` раз в 2 секунды пишет строку в `heartbeats` и возвращает
`TickResultEnum::Worked`. На странице видно, что метка обновляется — значит третий
рантайм (пул задач) работает. Контракт задачи — `name()` + `tick()`, ничего больше
(README, «Как пишется задача»).

`DemoJob` — обычная Laravel-джоба (`ShouldQueue`), с искусственной паузой, чтобы на
`GET /api/stats` было видно несколько сообщений в работе одновременно на одном
процессе-консьюмере.

Все три рантайма таким образом наблюдаемы с одной страницы, и ровно этого просил
исходный запрос: mysql, rabbitmq, workers-контейнер, rabbit-tasks.

## 4.8 Порядок реализации внутри шага

1. Скелет: `artisan`, `bootstrap/`, `storage/`, симлинк vendor, `config/app.php`.
   Проверка: `make demo-art c=list` показывает команды `sconcur:*`.
2. `config/database.php` + миграции + модели. Проверка: `make demo-art c="migrate --force"`.
3. Роуты и контроллеры, blade-страница. Проверка: `make demo-art c='sconcur:servers:http:start'`
   вручную в контейнере `workers`, `curl` по `/api/health`.
4. Мастер под supervisor (группа `http`). Проверка: `make up`, страница в браузере.
5. `config/queue.php`, `DemoJob`, `sconcur:rabbitmq:declare`, группа `rabbitmq`.
   Проверка: `POST /api/jobs` → строка в `job_results`.
6. `HeartbeatTask`, группа `tasks`. Проверка: метка растёт; `make tasks-stop` её
   останавливает, `make tasks-restart` возвращает.

## Открытые вопросы шага

- **Порт по умолчанию.** Предлагаю `APP_PORT=48081`. Нужен ли другой?
- **Оформление страницы.** Предлагаю один blade без сборки ассетов: никакого npm в
  репозитории пакета. Инлайновый CSS, обновление цифр через `fetch` раз в секунду.
- **`DB_CONNECTION` по умолчанию** — `sconcur_mysql` (демонстрирует пакет) против
  `mysql` (безопаснее). Предлагаю первое.
