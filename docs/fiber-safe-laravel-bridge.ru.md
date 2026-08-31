# ТЗ: fiber-safe Laravel поверх SConcur HTTP Server (план B3)

Статус: проблема воспроизведена и продиагностирована; выбрана целевая архитектура —
**scoped-контейнер без клонирования** (модель `yangusik/laravel-spawn`, портированная на
стандартный PHP + SConcur Fiber).

Адресаты:
- разработчик библиотеки `sconcur/sconcur` — раздел §6 (нужен per-coroutine context API);
- мы (мост `sconcur-laravel`) — разделы §5, §7, §8.

Связанные файлы:
- `packages/sconcur/sconcur-laravel/bin/sconcur-http-server` — текущий воркер-мост.
- `vendor/sconcur/sconcur/src/Features/HttpServer/HttpServer.php` — HTTP-сервер (spawn-on-request).
- `vendor/sconcur/sconcur/src/Scheduler/Scheduler.php`, `vendor/sconcur/sconcur/src/State.php` — планировщик и per-fiber реестр.
- `vendor/laravel/octane/src/CurrentApplication.php` — текущая (проблемная) подмена глобального контейнера.

Референс-реализация (другой движок, берём дизайн, не код целиком):
`https://github.com/yangusik/laravel-spawn` — Laravel поверх PHP TrueAsync.

---

## 1. Симптом

```
{"type":"throwable","class":"Illuminate\\Contracts\\Container\\BindingResolutionException",
 "message":"Target [Illuminate\\Contracts\\Debug\\ExceptionHandler] is not instantiable.", ...}
```

Печатается через `Laravel\Octane\Stream::throwable()` из листенера `ReportException`
(`WorkerErrorOccurred`). Плавающая ошибка: под нагрузкой так же падают другие биндинги.
`ExceptionHandler is not instantiable` — вторичный сбой: исходная ошибка запроса не смогла
зарепортиться, потому что контейнер под HTTP-ядром был подменён/сфлашен другим запросом.

---

## 2. Корневая причина

Текущий воркер построен по модели **Laravel Octane**: один корневой `$app`, на каждый
запрос `clone $app` (sandbox) и переключение **глобальных на процесс** статиков через
`CurrentApplication::set()`:

```php
Container::setInstance($app);          // глобальный singleton контейнера
Facade::clearResolvedInstances();      // сброс кэша корней фасадов
Facade::setFacadeApplication($app);    // глобальный контейнер всех фасадов
```

плюс `$currentApp->flush()` в `finally`. Эта модель валидна только при **одном запросе в
воркере одновременно**.

SConcur же исполняет **каждый запрос в отдельном PHP-Fiber, конкурентно в одном процессе**
(`Scheduler::spawn`). Когда хендлер уходит в async-вызов (Mongo) и фибер засыпает,
планировщик пускает другой запрос, который перетирает/флашит глобальный контейнер,
перенаправляет singleton HTTP Kernel и сбрасывает кэш фасадов. Проснувшийся первый запрос
работает с чужим/очищенным контейнером → `BindingResolutionException`.

Гонка (сокращённо):

| шаг | Fiber A | Fiber B | глоб. контейнер |
|-----|---------|---------|-----------------|
| 1 | `set(A)`; `handle()` → Mongo → **suspend** | — | A |
| 2 | (спит) | `set(B)`; `handle()`; `finally`: `flush(B)`; `set(root)` | root |
| 3 | **resume**; ошибка → ядро зовёт `app[ExceptionHandler]->report()` из чужого контейнера | — | root |
| 4 | биндинга нет → **`not instantiable`** | — | — |

---

## 3. Почему не подходят промежуточные варианты

- **«Не клонировать и не флашить, просто звать Kernel».** Убирает краш, но `Kernel::sendRequestThroughRouter()`
  делает `$this->app->instance('request', $request)` — глобально. Конкурентные фиберы
  перетирают `request`/`auth`/`session` друг друга → **тихая путаница данных между
  пользователями**. Хуже краша, потому что не видно.
- **«Клонирование + хук подмены контейнера на каждый resume».** Корректно, но на каждый активный
  запрос висит свой `clone $app`. Память масштабируется числом одновременных запросов.
- **`maxConcurrency=1` + больше воркеров.** Корректно и просто, но убивает внутрипроцессную
  конкуренцию, ради которой и затевался SConcur HTTP. Годится как временный обход.

Целевой план B3 (ниже) снимает и краш, и претензию к памяти: **клонов нет вообще**.

---

## 4. Целевая модель B3: scoped-контейнер без клонирования

### 4.1. Принцип

Один общий `Application` на процесс. Глобальный контейнер и фасады **не подменяются**. Небольшой
набор **request-scoped** сервисов резолвится из **per-coroutine контекста** (карта на фибер);
всё остальное (config, view-определения, маршруты, менеджеры) — общие синглтоны, как обычно.

На фибер висит **не клон приложения, а маленькая карта** `{request, auth, session, cookie, ...}`.

### 4.2. `AsyncApplication extends Application`

Переопределяет резолвинг (как `Spawn\Laravel\Foundation\AsyncApplication`):

- `resolve()` и `offsetGet()`: если alias входит в scope-набор — отдать инстанс из
  `current_context()` (или создать и положить туда); иначе — `parent::`.
- `'request'` всегда резолвится: из контекста → из `instances['request']` → fallback
  `Request::createFromGlobals()` (чтобы код, трогающий `$app['request']` на бутстрапе/в
  обработчике ошибок до первого запроса, не падал).
- `bound('request')` возвращает `true` (тот же мотив — обращения на бутстрапе).
- `scoped()` / `scopedSingleton()` — регистрация пользовательских scoped-фабрик.

### 4.3. `current_context()` поверх SConcur

Примитив-сердце всей модели = **per-coroutine key-value store** (`find($key)` / `set($key,$value)`).
В референсе это `Async\current_context()` из TrueAsync. На SConcur кладётся на стандартный
Fiber:

- ключ корутины — `spl_object_id(Fiber::getCurrent())`;
- SConcur **уже** ведёт per-fiber реестр в `SConcur\State` (`getCurrentFlow()` по
  `Fiber::getCurrent()`, `registerFiberFlow`, `unRegisterFiber`) — туда же логично повесить
  и пользовательский контекст;
- очистка карты — на завершении фибера (хук жизненного цикла корутины), чтобы не текло.

### 4.4. Набор scope-сервисов (точный список)

Из `ScopedService` референса — копируем как есть:

| alias | что изолируем |
|---|---|
| `request` | объект запроса |
| `session` | данные сессии |
| `auth` | гварды и аутентифицированный юзер |
| `auth.driver` | конкретный guard |
| `cookie` | очередь cookie |

`db` **намеренно НЕ скоупим** в контейнере: `DatabaseServiceProvider::boot()` делает
`Model::setConnectionResolver($app['db'])` статиком; scoped DatabaseManager будет собран GC
после завершения корутины → `Model::$resolver` повиснет на уничтоженном объекте. Изоляцию БД
решаем иначе — см. §6.

### 4.5. `ScopedServiceProxy` (фасады)

Фасады кэшируют резолв в статике `Facade::$resolvedInstance` — под конкуренцией он
становится общим. Вместо гонок с `clearResolvedInstances()` (тот самый источник исходного
бага) `offsetGet()` для scope-сервиса возвращает **прокси**, который кэшируется один раз, а
каждый вызов через `__call`/`__get` re-резолвит из `current_context()`. DI по типу
(`make`/`resolve`) при этом отдаёт реальный инстанс — type-hints работают.

Ограничение из референса: проксировать так можно только сервисы, не передаваемые в
типизированные параметры (поэтому `cookie` и `auth.driver` из прокси-набора исключены —
прокси не наследует их типы).

### 4.6. Адаптеры остального per-request состояния (чек-лист)

Готовый список того, что ещё держит per-request состояние (каждый — тонкий subclass,
изолирующий только нужные свойства через `current_context()`, общий кэш остаётся в родителе):

| Компонент | Что изолировать |
|---|---|
| `AsyncRouter` | текущий маршрут и request |
| `AsyncTranslator` | активная локаль (кэш `$loaded` общий) |
| `AsyncConfig` | оверлей `config()->set()` на корутину |
| `AsyncViewFactory` | данные `View::share()` |
| `AsyncDispatcher` | состояние `defer()` (флаг + очередь) |
| `CoroutineTransactions` | счётчик глубины транзакции (трейт в Connection-subclass) |

Сторонние (если используются в проекте — проверить): `spatie/laravel-permission` (team id,
индекс wildcard-прав), `inertiajs/inertia-laravel` (sharedProps и пр.), `laravel/socialite`,
`laravel/telescope`, `barryvdh/laravel-debugbar`. `livewire/livewire` — **несовместим** в
async-режиме (глубокое per-request состояние в синглтоне).

---

## 5. Что делаем в мосте `sconcur-laravel`

1. Заменить `Illuminate\Foundation\Application` на `AsyncApplication` в `bootstrap/app.php`
   (или собрать отдельный async-bootstrap, чтобы не ломать CLI/Octane).
2. Реализовать `current_context()` поверх `Fiber::getCurrent()` + `SConcur\State`.
3. Перенести из референса: `AsyncApplication`, `ScopedService`, `ScopedServiceProxy`,
   `CoroutineTransactions`, набор адаптеров, PHPStan-правило `MutableStaticPropertyRule`.
4. В обработчике запроса (`bin/sconcur-http-server`): **убрать** `clone $app`,
   `CurrentApplication::set()` и `$app->flush()`; вместо этого на каждый запрос —
   открыть scope корутины, положить `request` в контекст, обработать через Kernel, в
   `finally` очистить контекст корутины (без флаша общего контейнера).
5. `Async\delay()`/`sleep()` в горячем пути заменить на `SConcur\...\Sleeper::usleep()`
   (блокирующий `sleep()` встанет колом на всём цикле).

---

## 6. Запрос к библиотеке SConcur (суженный)

Подход с подменой глобального контейнера на каждый resume этому решению не нужен. В модели B3 этого **не
нужно** — глобальное состояние не трогается. Достаточно дать публичный **per-coroutine context**.

> Полное отдельное ТЗ для разработчика SConcur (фреймворк-нейтральное, с точками интеграции в
> `State`/`Scheduler`/`WaitGroup`, скетчем реализации и тестами) — в
> [sconcur-coroutine-context.ru.md](sconcur-coroutine-context.ru.md). Ниже — краткая выжимка.

### 6.1. Требование

```php
namespace SConcur\Scheduler;

interface Context
{
    public function find(string $key): mixed;     // null если нет
    public function set(string $key, mixed $value, bool $replace = false): void;
    public function forget(string $key): void;
}

// доступ к контексту текущей корутины:
function current_context(): Context;   // по Fiber::getCurrent()
```

### 6.2. Гарантии

1. Контекст привязан к корутине (`spl_object_id(Fiber::getCurrent())`); у каждой
   spawned-корутины — свой.
2. **Наследование дочерними корутинами.** Если хендлер сам порождает корутины
   (`spawn`/WaitGroup для параллельных Mongo), дочерние видят **тот же** контекст приложения
   (иначе дочерний фибер выполнится с пустым request/auth). По умолчанию — наследовать от
   родителя; при необходимости — явный изолированный контекст.
3. **Очистка** контекста при завершении/`forget`/`detach` корутины — без утечек ссылок.
4. Дёшево: `find/set` зовутся на каждый scoped-резолв (горячий путь).

### 6.3. Маппинг TrueAsync → SConcur

| laravel-spawn (TrueAsync) | SConcur |
|---|---|
| `Async\current_context()` | новый `current_context()` поверх `State`/Fiber (§6.1) |
| `Async\coroutine_context()` | то же |
| `Async\delay()` | `Sleeper::usleep()` (есть) |
| C-уровневый PDO Pool | **отсутствует** — см. §6.4 |
| `TrueAsync\HttpServer` | `SConcur\...\HttpServer` (есть) |

### 6.4. DB-изоляция — главный гэп

Референс изолирует физические соединения **на C-уровне TrueAsync** (`PDO::ATTR_POOL_ENABLED/
MIN/MAX/HEALTHCHECK`). В стандартном PHP под SConcur таких атрибутов нет. Нужно наше решение
для MySQL (users/services/auth) — выбрать одно:

- **(а)** гонять MySQL через async-Sql фичу самого SConcur (`vendor/sconcur/.../Features/Sql`)
  с соединением на корутину;
- **(б)** PHP-уровневый пул: на корутину выдавать отдельный PDO, возвращать на завершении;
- **(в)** временно — правило «никаких sconcur-await внутри MySQL-транзакции» + проверка
  линтером.

Без этого опасен сценарий: открыта MySQL-транзакция → внутри `await` Mongo (фибер заснул) →
другой фибер сходил в **то же** общее PDO-соединение → транзакция повреждена. Счётчик
транзакций (`CoroutineTransactions`) переносится как есть, но физическую изоляцию соединения
он не заменяет.

Примечание: обычный блокирующий PDO фибер не усыпляет, поэтому гонка возможна **только** на
flow «MySQL-транзакция + sconcur-async-await внутри неё». Это сужает поверхность, но не
обнуляет её.

---

## 7. Правила async-safe кода (из референса, применимо без изменений)

- Не писать в **мутабельные static-свойства** во время обработки запроса (общие на все
  корутины). Чтение boot-time статики — ок.
- Не хранить per-request данные в **синглтонах** — использовать `scoped()` или прокидывать
  аргументами.
- Не использовать `once()` на синглтоне с per-request данными (закэширует данные первого
  запроса для всех). На per-request объектах (модели, контроллеры) — безопасно.
- Не использовать суперглобалы (`$_GET/$_POST/$_SERVER/$_SESSION`) — только `Request`.
- Не использовать `sleep()/usleep()` — блокируют весь цикл; вместо них async-sleep.
- `Number::useLocale()` и подобные «глобальный static setter» — опасны; передавать локаль
  аргументом.
- Замыкания безопасны, если резолвят зависимости лениво: `fn() => $app['request']->url()`.

PHPStan `MutableStaticPropertyRule` — гоняем по `app/` и по vendor-пакетам, чтобы заранее
ловить мутабельные статики (в самом Laravel у референса 309 находок, все классифицированы).

---

## 8. План внедрения по этапам

1. **Прототип контекста.** `current_context()` поверх Fiber/State + минимальный
   `AsyncApplication` со scope только `request` и `auth`. Цель — доказать изоляцию.
2. **Тест изоляции request+auth** (см. §9, кейс 1) под реальной конкуренцией.
3. **DB-решение** (§6.4) — выбрать и прототипировать; стресс «MySQL-транзакция + Mongo-await».
4. **Полный scope-набор + адаптеры** (§4.4–4.6) + PHPStan-правило.
5. **Переключение воркера** на async-bootstrap, удаление clone/flush/`CurrentApplication`.
6. **Нагрузочный прогон** + замер RSS на long-run.

---

## 9. Критерии приёмки и тесты

1. **Изоляция request/auth.** Два параллельных запроса: «медленный» (`?sleep=1`, async-пауза,
   затем читает `auth()->id()`/`request()->path()`) и «быстрый» (`?sleep=0`, другой юзер).
   Assert: медленный видит **своего** юзера/путь, не чужого. Без фикса — путаница/`not instantiable`.
2. **Нет краша резолва.** ≥2 тыс. конкурентных запросов в Mongo при `workerCount:1`,
   `maxConcurrency:0` — ноль `BindingResolutionException`.
3. **Изоляция БД.** Параллельные транзакции на разных корутинах не видят/не ломают друг друга;
   стресс «MySQL-транзакция + Mongo-await» — ноль повреждённых транзакций.
4. **Память.** Long-run — плоский RSS (контексты корутин освобождаются; клонов app нет).
5. **Фреймворк-нейтральность.** В `sconcur/sconcur` нет упоминаний Laravel; вся специфика — в
   `sconcur-laravel`.

---

## 10. Приложение: что берём из laravel-spawn

| Файл референса | Назначение | Действие |
|---|---|---|
| `src/Foundation/AsyncApplication.php` | scoped `resolve()/offsetGet()` | портировать (заменить `Async\current_context` на наш) |
| `src/Foundation/ScopedService.php` | enum scope-алиасов | взять как есть |
| `src/Foundation/ScopedServiceProxy.php` | прокси фасадов | взять как есть |
| `src/Database/CoroutineTransactions.php` | счётчик транзакций в контексте | портировать |
| `src/Routing/AsyncRouter.php`, `Translation/AsyncTranslator.php`, `Config/AsyncConfig.php`, `View/AsyncViewFactory.php`, `Events/AsyncDispatcher.php` | адаптеры per-request состояния | портировать по мере надобности |
| `src/PHPStan/MutableStaticPropertyRule.php` | линтер мутабельных статиков | взять как есть |
| `ASYNC_ADAPTATION.md`, `adaptation.md` | классификация safe/unsafe + анализ статиков Laravel | методичка |
| `src/Database/ManagesDatabasePool.php`, `Server/TrueAsyncServer.php` | C-PDO-pool, TrueAsync-сервер | **НЕ берём** (другой движок) — §6.4 |
