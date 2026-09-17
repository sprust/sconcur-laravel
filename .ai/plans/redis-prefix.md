# Redis: глобальный префикс ключей на клиенте `sconcur`

Статус: реализовано, ветка `feature/sconcur-redis`. Отличия от плана — в разделе «Итог».

## Цель

Принимать `prefix` в `redis.options` и в записи соединения и ставить его на ключи так же, как
это делает phpredis (`Redis::OPT_PREFIX`), — чтобы приложение, переходящее с phpredis со
скелетным `prefix = <app>_database_`, видело свои ключи под теми же именами, а несколько
приложений могли делить один Redis без отдельных баз.

Сейчас `prefix` отвергается (`src/Redis/Connector.php`, `OPTIONS_OFF_ONLY`) с объяснением
«сырая команда не говорит, какие аргументы — ключи». Проверка на живом phpredis 6.3.0 это
объяснение опровергает: phpredis тоже не угадывает ключи в сырой команде — он знает позиции
ключей для каждого своего метода, а `rawCommand` оставляет без префикса.

## Как ведёт себя phpredis (снято через `MONITOR`, `OPT_PREFIX = 'P_'`)

Префикс получают ключи — и только они:

| Вызов | Что ушло на сервер |
|---|---|
| `get`, `set`, `incr`, `expire`, `type`, `dump`, `ttl`, `move`, `hSet`, `lPush`, `sAdd`, `zAdd`, `xAdd`, `pfAdd`, `geoAdd`, … | первый аргумент — ключ |
| `mget`, `del`, `exists`, `unlink`, `touch`, `watch`, `sInter`, `pfCount` | все аргументы |
| `mset`, `msetnx` | ключи пар, значения нет |
| `rename`, `renameNx`, `copy`, `lMove`, `rpoplpush`, `sMove`, `lcs`, `zRangeStore` | два первых |
| `sInterStore`, `sDiffStore`, `pfMerge` | все ключи |
| `blPop`, `brPop`, `bzPopMin`, `brpoplpush`, `blmove` | ключи, но не таймаут |
| `bitOp AND dst k1 k2` | всё после операции |
| `lMpop`, `sintercard`, `zinter`, `zunion`, `zdiff`, `zMPop` | `numkeys` ключей после счётчика |
| `bzMPop` | `numkeys` ключей после таймаута и счётчика |
| `zInterStore`, `zUnionStore`, `zdiffstore` | назначение и `numkeys` ключей, `WEIGHTS`/`AGGREGATE` нет |
| `eval`, `evalsha` | `numkeys` первых аргументов (KEYS), ARGV нет |
| `xRead`, `xReadGroup` | ключи после `STREAMS`, id нет |
| `georadius … STORE key` | ключ и ключ после `STORE`; `geosearchstore` — оба ключа |
| `object encoding key`, `xInfo STREAM key`, `xGroup CREATE key` | ключ после подкоманды |
| `keys pattern` | шаблон |
| `publish`, `pubsub numsub`, `subscribe`, `psubscribe` | канал / шаблон |
| `rawCommand` | ничего |

Особенности, которые надо повторить, а не «исправить»:

- `sort` префиксует только ключ: `BY`, `GET` и `STORE` уходят как есть;
- `scan … MATCH` не префиксуется (у phpredis это отдельная опция `OPT_SCAN`), а `keys` —
  префиксуется; `hscan`/`sscan`/`zscan` префиксуют свой ключ;
- ответы не очищаются: `keys`, `scan`, `blPop`, `bzPopMin`, `xRead` возвращают имена с префиксом;
- ключ записи соединения `prefix` перекрывает `options.prefix` (`PhpRedisConnector::connect`).

## Решения

1. **Своя таблица позиций ключей по командам** (`src/Redis/KeyPrefix.php`), а не `COMMAND
   INFO` сервера: не нужен лишний запрос, а поведение phpredis — эталон, включая его
   особенности выше. Команда вне таблицы уходит без префикса, как у phpredis.
2. **Где ставится.** В одном месте на каждом пути, после разбора аргументов
   (`PhpRedisArguments::build`), до `BlockingCommands` (позиции аргументов префикс не меняет):
   - `Connection::execute()` — фасад, магические вызовы, переопределения Laravel;
   - `CommandBatch::command()` — пайплайн и транзакция;
   - `Connection::createSubscription()` — каналы и шаблоны;
   - `rawCommand` / `executeRaw` — без префикса (флаг на пути, а не имя команды: после
     `PhpRedisArguments::rawCommand` имя уже `GET`).
3. **Типизированный API фичи** (`Redis::connection()->client()`) префикс не получает: это
   объект фичи, у phpredis такого пути нет. Пишется в документацию.
4. **`_prefix($value)`** на `Connection` — как у phpredis, для кода, который собирает имя сам.
5. **Кэш-стор** ставит префикс соединения перед своим: ключ `connectionPrefix . cachePrefix .
   key`, как у `RedisStore` на phpredis с `OPT_PREFIX`; `getPrefix()` отдаёт только префикс
   кэша, как у `RedisStore`. Имя лока — так же.
6. **Проверки `Connector`.** `prefix` убирается из `OPTIONS_OFF_ONLY` и добавляется в
   `CONNECTION_KEYS`; значение — строка (не строка → `UnsupportedRedisOptionException`).
   `parameters` по-прежнему отвергается.

## Решение мейнтейнера

Стор ставит префикс соединения всегда, при любом клиенте фасада (2026-09-17): цель —
бесшовный переход с phpredis, а `RedisStore` на том же конфиге ставит его тоже.

## Код

- `src/Redis/KeyPrefix.php` — `apply(string $command, list<mixed> $arguments, string
  $prefix): list<mixed>`; таблица: «первый», «все», «первые N», «пары», «все, кроме
  последнего» (таймаут), `numkeys` со смещением, после `STREAMS`, после `STORE`, после
  подкоманды; пустой префикс — без изменений.
- `src/Redis/Connection.php` — `$prefix` в конструкторе, применение в `execute()`,
  `createSubscription()`, `_prefix()`; `executeRaw` мимо префикса.
- `src/Redis/CommandBatch.php` — префикс из соединения, применение в `command()`.
- `src/Redis/Connector.php` — чтение `prefix` (запись перекрывает опции), проверка строки,
  `REPLACEMENTS['prefix']` удаляется.
- `src/Cache/Redis/StoreFactory.php`, `Store.php` — префикс соединения.

## Тесты

- `KeyPrefixTest` — по кейсу на каждую форму таблицы и на каждую особенность phpredis
  (`sort`, `scan MATCH`, `rawCommand`, пустой префикс).
- `PhpRedisParityTest` — второй прогон тех же вызовов с `prefix` на обоих соединениях:
  совпадают ответ **и** содержимое базы (список ключей без префикса клиента, отсортированный,
  с типами). Сид пишется под именами с префиксом. Проверить мутацией: без `KeyPrefix` тест
  должен падать.
- `FacadeTest` — подписка: канал и шаблон с префиксом, что приходит в колбэк (сверить с
  phpredis отдельной пробой, phpredis не отдаёт управление из `subscribe`).
- `ConnectorTest` — `prefix` принят в опциях и в записи, запись перекрывает опции, не строка
  отвергается; тесты «prefix отвергается» удаляются.
- `SconcurRedisStoreTest` — ключи стора с префиксом соединения совпадают с ключами
  `RedisStore` на phpredis с тем же конфигом; лок — тоже.
- `QueueTest` — очередь `redis` с префиксом: push/pop/later/release работают (Lua-скрипты
  очереди берут ключи из KEYS).

## Документация (пары en/ru)

- `docs/redis.md` — `prefix` в таблице ключей соединения; строка `prefix` уходит из таблицы
  отвергаемых; в «Фасаде» — что получает префикс и что нет (`rawCommand`, `client()`,
  `scan MATCH`, `sort BY/GET/STORE`, ответы не очищаются); «Кэш-стор» — два префикса;
  «Переход с phpredis» — префикс сохраняется, данные на месте; из «Ограничений» уходит
  «Нет префикса ключей на фасаде».
- `.ai/README.md` — пункт про отказы клиента (`prefix` из списка убрать).

## Проверка

`make check`; прогон `PhpRedisParityTest` с префиксом; `make workers-restart` и демо с
`REDIS_PREFIX` (канал управления пулом задач через кэш).

## Итог

- Подписка: phpredis отдаёт в колбэк канал с префиксом (проверено форком с публикатором), так
  же сделано здесь.
- Дополнительно замечено при сверке и исправлено: phpredis отдаёт `XRANGE`/`XREVRANGE` картой
  id => поля, `XREAD`/`XREADGROUP` — стрим => id => поля, пустое чтение — `[]`; раньше клиент
  отдавал сырой список и `false`. Форма в `PhpRedisReplies`, кейсы в `PhpRedisParityTest`,
  `FacadeTest` поправлен под `[]`.
- Тесты подписки, лимитеров и очереди с префиксом собраны в отдельном `PrefixTest`, а не в
  `FacadeTest`/`QueueTest`; содержимое базы в `PhpRedisParityTest` сравнивается по списку
  ключей, без типов — имена и есть то, что проверяется.
- Мутация: без `KeyPrefix::apply` падает 173 кейса `PhpRedisParityTest`/`PrefixTest`/
  `SconcurRedisStoreTest`.
- Документация: раздел «Префикс ключей» и переписанный пошагово «Переход с phpredis» в
  `docs/redis*.md`.
