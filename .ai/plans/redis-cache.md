# Redis: клиент `sconcur` для фасада и кэш-стор `sconcur_redis`

Статус: реализовано на `sconcur/sconcur` 0.13.0, ветка `feature/sconcur-redis`. Отличия
реализации от плана — в разделе «Итог» в конце.

## Цель

Перейти на `sconcur/sconcur` 0.13.0, где появилась фича Redis
(`SConcur\Features\Redis\Connection`), и подключить её к Laravel в двух местах:

- клиент `sconcur` для `Illuminate\Redis\RedisManager` — фасад `Redis::`,
  `Redis::connection()`, `Redis::throttle()`/`funnel()`;
- кэш-стор `sconcur_redis`.

Команды уходят в расширение, корутина приостанавливается, одновременные команды многих
корутин идут одним пайплайном по мультиплексированному соединению. Вне корутины всё
работает синхронно, как у `sconcur_mysql`.

API сверен с веткой `feature/redis` библиотеки; после выхода релиза сверяется ещё раз по
`vendor/sconcur/sconcur`.

## Решения мейнтейнера

1. Соединение описывается отдельными полями (`host`/`port`/`username`/`password`/
   `database`), не строкой DSN.
2. Демо переходит на `CACHE_STORE=sconcur_redis`.
3. Фича подключается к фасаду `Redis::`, не только к кэшу.
4. Всё, что SConcur осознанно не реализует, выбрасывает исключение, а не игнорируется
   молча. Это та же позиция, что у библиотеки: параметр, который никто не читает, значит,
   что соединение ведёт себя не так, как написано в конфиге.

## Исключения

Свои классы в `src/Redis/Exceptions/`, по одному на случай — как `SConcur\Exceptions\` у
библиотеки. Все — ошибки использования, поэтому наследуют `LogicException`:

| Класс | Когда |
|---|---|
| `RedisClusterNotSupportedException` | `redis.clusters.*`, `options.cluster` на соединении `sconcur` |
| `UnsupportedRedisOptionException` | ключ соединения или `options`, который фича не читает: `prefix` (не пустой), `serializer`, `compression`, `max_retries`, `backoff_*`, `persistent`, `read_timeout`, `timeout`, `context`, `protocol` (RESP3) и любой неизвестный; `scheme` вне `tcp`/`tls`/`unix` |
| `UnsupportedRedisCallException` | вызов фасада, которого у фичи осознанно нет: `multi`/`exec`/`discard` отдельными вызовами (→ `transaction(Closure)`), `watch`/`unwatch`, `select` (→ `database`), `subscribe`/`psubscribe` сырой командой без колбэка, `ssubscribe` |

Сообщение называет замену, если она есть. Проверка конфига — в `Connector`, при создании
соединения, а не при первой команде: ошибка конфигурации видна на старте, а не в запросе.

`UnsupportedRedisCommandException` библиотеки не заменяется, а остаётся последней линией:
сырой `Redis::command('MULTI')` дойдёт до неё и так.

## 1. Зависимость и образ

- `composer.json`: `"sconcur/sconcur": "0.13.0"` (точная версия).
- `make composer-lock` → `make build` (новый `sconcur.so`) → `make composer c=install`.
- Проверить требования библиотеки к платформе (`ext-msgpack`, PHP) — при изменении
  поправить `config.platform`.
- Обновить упоминания `0.12.2`: `.ai/README.md`, `docs/installation.md` / `.ru.md`,
  комментарий в `src/Database/Mysql/Dsn.php` (если DSN-логика SQL в 0.13.0 не менялась).
- `make check` сразу после апгрейда — отдельно от нового кода.

## 2. Инфраструктура

- `docker-compose.yml`: сервис `redis` (`scl-redis`, `redis:7.4-alpine`), без
  персистентности (`--save "" --appendonly no`), healthcheck `redis-cli ping`, порт
  `${REDIS_DOCKER_PORT:-36379}:6379`; `php` и `workers` — `depends_on` на него.
- `.env.example`: `REDIS_CLIENT=sconcur`, `REDIS_HOST=scl-redis`, `REDIS_PORT`,
  `REDIS_DOCKER_PORT`, `CACHE_STORE=sconcur_redis`.
- `phpunit.xml`: `REDIS_HOST=scl-redis`, `REDIS_PORT=6379`.

## 3. Конфигурация

Соединения живут там же, где у штатного Laravel, — в `config/database.php`:

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'sconcur'),

    'default' => [
        'url'                  => env('REDIS_URL'),
        'scheme'               => env('REDIS_SCHEME', 'tcp'), // tcp | tls | unix
        'host'                 => env('REDIS_HOST', '127.0.0.1'),
        'port'                 => env('REDIS_PORT', '6379'),
        'path'                 => env('REDIS_SOCKET'),        // для scheme=unix
        'username'             => env('REDIS_USERNAME'),
        'password'             => env('REDIS_PASSWORD'),
        'database'             => env('REDIS_DB', '0'),
        'timeout_ms'           => 5000,
        'pool_size'            => null,
        'conn_max_lifetime_ms' => null,
    ],

    'cache' => [ /* то же, database => REDIS_CACHE_DB */ ],
],
```

`config/cache.php`, по образцу штатного `redis`-стора:

```php
'sconcur_redis' => [
    'driver'          => 'sconcur_redis',
    'connection'      => env('REDIS_CACHE_CONNECTION', 'cache'),
    'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
],
```

Стор строит соединение из `database.redis.<connection>` сам, через тот же `Connector`,
и от `redis.client` не зависит: приложение может держать фасад на phpredis, а кэш — на
фиче.

`url` разбирается штатным `ConfigurationUrlParser` (так делает `RedisManager`), поля
перекрывают его части.

## 4. Код

### `src/Redis/Dsn`

Собирает DSN фичи из полей: `redis://` / `rediss://` (для `tls`) /
`unix:///path?db=&user=&pass=`; `username`/`password` через `rawurlencode`. Ключи,
которые ничего не делают на фиче (`max_retries`, `backoff_*`, `persistent`, `read_timeout`,
`context`), не передаются — фича отказывает на неизвестных query-параметрах, а в DSN они
и так не попадают.

### `src/Redis/Connector implements Illuminate\Contracts\Redis\Connector`

- `connect($config, $options)` → `Connection` поверх `SConcur\Features\Redis\Connection`;
- `connectToCluster()` — `RuntimeException`: фича не поддерживает кластер.

Регистрация: `resolving('redis', …)` + `RedisManager::extend('sconcur', …)`.

### `src/Redis/Connection extends Illuminate\Redis\Connections\Connection`

Магические вызовы в стиле predis: `Redis::set('k', 'v', 'EX', 10, 'NX')` →
`command('SET', [...])` сырой командой. Так работают встроенные потребители Laravel —
`RedisLock`, `ConcurrencyLimiter`, `DurationLimiter` зовут `eval($script, $numKeys, ...)`,
`set(..., 'EX', ..., 'NX')`, `setnx`, `del`. Ответ — в форме RESP2, как его отдаёт фича.

- `command()` переопределён: сырой вызов фичи, события `CommandExecuted`/`CommandFailed`
  как у базового класса; массивы в аргументах разворачиваются (`mget(['a', 'b'])`);
- `pipeline(?Closure)` / `transaction(?Closure)` — через `pipeline()`/`transaction()` фичи;
- `createSubscription()` — через `Subscription` фичи, колбэк получает
  `($payload, $channel)` как у штатных соединений;
- типизированный API фичи — через `Redis::connection()->client()`.

Не поддерживается и отказывает с понятным сообщением (это ограничения фичи):
`multi`/`exec`/`watch` отдельными вызовами (только `transaction(Closure)`), `select`,
кластер. `options.prefix` не применяется: у сырой команды нельзя надёжно отличить ключ от
аргумента — пишем в документацию.

### `src/Cache/Redis/Store extends TaggableStore implements LockProvider`

Типизированный API фичи. `TaggableStore` даёт теги через общий `TagSet` поверх стора
(`RedisTaggedCache` завязан на phpredis/predis).

| Метод | Команда |
|---|---|
| `get` | `GET` |
| `many` | `mGet` (ответ ключуется по запрошенному ключу) |
| `put` | `SET key value EX max(1, seconds)` |
| `putMany` | `transaction()` — `MULTI`/`EXEC` из `SET … EX` |
| `add` | `SET key value EX seconds NX` — атомарно, без Lua |
| `increment` / `decrement` | `INCRBY` / `DECRBY` |
| `forever` | `SET` без TTL |
| `forget` | `DEL` |
| `flush` | `FLUSHDB` — как у штатного `RedisStore`: вся БД, не только префикс |
| `lock` / `restoreLock` | `Lock` на `lock_connection` |
| `getPrefix` | `cache.prefix` / `prefix` стора |

Сериализация — как у `RedisStore`: числа (кроме `INF`/`NAN`) пишутся как есть, чтобы
работал `INCRBY`; остальное `serialize()`, чтение `unserialize()` с `allowed_classes` из
`cache.serializable_classes`.

### `src/Cache/Redis/Lock extends Illuminate\Cache\Lock`

`acquire` — `SET NX EX` (или `SET NX` при `seconds = 0`); `release` —
`eval(LuaScripts::releaseLock())`; `forceRelease` — `DEL`; `getCurrentOwner` — `GET`;
`refresh` — `eval(LuaScripts::refreshLock())`.

Регистрация стора: `resolving('cache', …)` + `CacheManager::extend('sconcur_redis', …)`.

## 5. Тесты

`tests/Feature/Redis/`:
- `DsnTest` — tcp/tls/unix, экранирование логина/пароля, `url` + поля;
- фасад: `Redis::set/get`, predis-стиль `set(..., 'EX', ..., 'NX')`, `mget` с массивом,
  `eval`, `pipeline`/`transaction`, `subscribe` + `publish` в `WaitGroup`, события
  `CommandExecuted`, отказ кластера и `multi()`;
- `Redis::throttle()` и `Redis::funnel()` — лимитеры Laravel работают поверх клиента.

`tests/Feature/Cache/`:
- get/put/many/putMany/add/forever/forget, истечение TTL, префикс, `flush`;
- increment/decrement, числа без сериализации; `serializable_classes`;
- теги; локи: acquire/release, чужой владелец, `restoreLock`, `block()`, `refresh`;
- конкурентность: N корутин `increment` одного ключа → ровно N; `add` → успех у одной;
- стор работает при `redis.client = phpredis` (не зависит от клиента фасада).

`workbench/config/cache.php` и `redis`-секция `workbench/config/database.php`.

## 6. Демо

- `demo/config/database.php` — секция `redis`; `demo/config/cache.php` — стор;
- `.env.example`: `CACHE_STORE=sconcur_redis`, `REDIS_CLIENT=sconcur`. Через кэш работают
  канал управления пулом задач и presence ws — проверить оба после переключения.

## 7. Документация (пары en/ru)

- новый `docs/redis.md` / `.ru.md`: клиент фасада (predis-стиль, что не поддерживается,
  `prefix`), кэш-стор (конфиг, команды, сериализация, локи, теги, `flush`);
- `README.md` / `.ru.md` — строка в таблице документов и абзац;
- `docs/configuration*.md` — переменные `REDIS_*`, которые читает пакет/скелет;
- `docs/layout*.md`, `docs/development*.md` — `src/Redis/`, `src/Cache/`, `scl-redis`;
- `demo/README*.md` — кэш демо на Redis;
- `.ai/README.md` — архитектура, шесть контейнеров, версия библиотеки.

## 8. Проверка

`make check`, затем `make workers-restart`, `make tasks-stop`/`tasks-restart` (канал
через кэш) и `make ws-check` (presence через кэш).

## Итог

Что получилось не так, как было задумано выше:

- `Lock::block()` переопределён: штатный ждёт между попытками через `usleep()`, что в
  корутине замораживает весь процесс. Внутри корутины пауза идёт через `Sleeper` фичи, вне —
  через `Sleep` фреймворка (чтобы работал `Sleep::fake()`). Это важно для
  `Tasks\Control\ControlChannel`, который берёт ключ через `block()`.
- `Redis::pipeline()`/`transaction()` отдают колбэку `CommandBatch` — обёртку над `Pipeline`
  фичи с predis-вызовами; `exec()` внутри колбэка отвергается, пустой пакет отвечает `[]`.
- Проверка конфига и сборка клиента — в одном `Redis\Connector`, стор строится через
  `Connector::clientForConnection()` (`Cache\Redis\StoreFactory`), минуя `RedisManager`.
- `redis.options.cluster` не отвергается: это лишь режим шардирования для `redis.clusters`,
  а запрос кластера отвергается сам. Выключенные значения (`null`/`false`/`''`/`0`/`[]`)
  любых ключей принимаются — иначе падал бы скелет Laravel.
- Замыкания в `extend()` обоих менеджеров не `static`: менеджеры делают `bindTo`, а статическое
  замыкание молча не привязывается.
- В тестах магические вызовы идут через `callMagic()` с именем в переменной: PHPStan
  проверяет их по сигнатурам phpredis из `@mixin \Redis` базового соединения.
- Штатный кэш-стор `redis` фреймворка на клиенте `sconcur` падает на `putMany()` (`multi()`);
  это записано в ограничениях `docs/redis.md`.
- Стор проверяет `redis.options` только при `redis.client = sconcur`: при phpredis/predis это
  опции того клиента (найдено ревью — иначе стор падал на `prefix` чужого фасада).
- Presence ws в демо на одном воркере живёт в памяти (`auto`), так что через Redis в демо
  ходит только канал управления пулом задач.

## Правки по код-ревью

Ревью суб-агентом с пустым контекстом после первого коммита. Исправлено:

- `CommandArguments::flatten()` решает по команде, а не по форме массива: `['0' => 'a']` в PHP
  неотличим от списка, и `mset` с числовыми ключами писал не те ключи. Парные команды
  (`MSET`, `MSETNX`, `HSET`, `HMSET`) — ключ/значение, `ZADD` — участник => счёт, остальные
  отвергают карту.
- Блокирующие команды фасада (`BLPOP 5`, `BLPOP 0`, `XREAD BLOCK`) получают дедлайн под своё
  ожидание (`BlockingCommands` повторяет разбор `blocking_timeout_ms` расширения) — раньше
  расширение отвергало их при `timeout_ms = 5000`.
- `funnel()`/`throttle()` возвращают наследников лимитеров с паузой через
  `Support\CooperativeSleep` (её же использует `Lock::block()`).
- `Connector` отвергает значения, которые фича прочла бы иначе: нецелые `database`/`port`/
  `*_ms`, `pool_size` вне 1…64, схема в `host`, путь сокета в `host`, ключи чужой схемы,
  `username` без `password` (проверено на живом сервере: redis-rs тогда не шлёт AUTH).
- Порядок в `clientForConnection()` как у `RedisManager`: запись соединения раньше кластера.
- Путь unix-сокета экранируется посегментно.
- Все записи стора идут через сырую `SET`, как `putMany()`: float пишется одинаково
  (`1e15` → `1000000000000000`, а не `1.0E+15`).
- `createSubscription()` не даёт ошибке закрытия подменить исключение, завершившее цикл.
- Документация: ошибка выполнения в транзакции не отменяет её (отменяет только ошибка
  постановки в очередь — `EXECABORT`).
- Тесты: «не замораживает» теперь меряет самую длинную паузу тикера-корутины
  (`longestStallMs()`), и отдельный тест доказывает, что замер видит нативный `usleep`;
  проверено мутацией — с нативной паузой оба теста падают (~251 мс). Тест независимости
  стора делает `RedisManager` непригодным. Подписка получила дедлайн вместо зависания.
