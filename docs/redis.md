English | [Русский](redis.ru.md)

# Redis (`sconcur` client and `sconcur_redis` cache store)

SConcur's Redis feature, wired into Laravel in two places:

- the `sconcur` client of `RedisManager` — `Redis::`, `Redis::connection()`,
  `Redis::throttle()`, `Redis::funnel()`, and with them Laravel's `redis` queue driver;
- the `sconcur_redis` cache store — `Cache::`, locks and tags.

A command goes into the extension while the calling coroutine is suspended. Commands that
many coroutines issue at the same time travel down the extension's multiplexed connections
as one pipeline and come back in one round trip. Outside a coroutine the same calls work
synchronously. What the feature itself does and does not do is in the library's
`vendor/sconcur/sconcur/docs/redis.md`.

## Table of contents

- [Connections](#connections)
- [What the client refuses](#what-the-client-refuses)
- [The facade](#the-facade)
- [The key prefix](#the-key-prefix)
- [Pipelines and transactions](#pipelines-and-transactions)
- [Pub/Sub](#pubsub)
- [The cache store](#the-cache-store)
- [Locks](#locks)
- [The queue](#the-queue)
- [Moving from phpredis](#moving-from-phpredis)
- [Limits](#limits)

## Connections

Connections are described where Laravel keeps them, in the `redis` section of
`config/database.php`, as separate fields:

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'sconcur'),

    'options' => [
        'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel'), '_') . '_database_'),
    ],

    'default' => [
        'url'        => env('REDIS_URL'),
        'host'       => env('REDIS_HOST', '127.0.0.1'),
        'port'       => env('REDIS_PORT', '6379'),
        'username'   => env('REDIS_USERNAME'),
        'password'   => env('REDIS_PASSWORD'),
        'database'   => env('REDIS_DB', '0'),
        'timeout_ms' => 5000,
    ],

    'cache' => [
        'url'        => env('REDIS_URL'),
        'host'       => env('REDIS_HOST', '127.0.0.1'),
        'port'       => env('REDIS_PORT', '6379'),
        'username'   => env('REDIS_USERNAME'),
        'password'   => env('REDIS_PASSWORD'),
        'database'   => env('REDIS_CACHE_DB', '1'),
        'timeout_ms' => 5000,
    ],
],
```

Write the section out whole. Laravel merges only `connections` of `config/database.php`
with its own file, so an application's `redis` section replaces the framework's rather
than adding to it — which is what is needed here: the framework's entries carry
`max_retries` and `backoff_*`, and the client refuses them.

| Key | Default | What it does |
|---|---|---|
| `scheme` | `tcp` | `tcp`, `tls` or `unix` |
| `host` | `127.0.0.1` | server host; an IPv6 address is accepted as it is |
| `port` | `6379` | server port |
| `path` | — | the socket, for `scheme => unix` |
| `username` | — | ACL user |
| `password` | — | password |
| `database` | `0` | database number |
| `url` | — | `redis://`, `rediss://` or `tls://` URL; merged into the fields the way `RedisManager` does it, and wins over them |
| `timeout_ms` | `30000` (the extension's) | deadline for one command; `0` means no deadline |
| `pool_size` | `4` (the extension's) | multiplexed connections per process for this server; at most 64 |
| `conn_max_lifetime_ms` | no limit | how long a pooled connection is kept before it is replaced |
| `prefix` | `redis.options.prefix` | put in front of the keys, as phpredis does; see [the key prefix](#the-key-prefix) |

`SConcur\Laravel\Redis\Dsn` builds the feature's DSN out of the fields: `redis://` for
`tcp`, `rediss://` for `tls`, `unix://path?db=&user=&pass=` for a socket. The username and
the password are percent-encoded, so any character is safe in them.

Nothing is opened when a connection is built. The feature's pool is raised in the extension
by the first command, and an unused pool is closed there five minutes after its last one.

## What the client refuses

A setting the feature does not read is refused when the connection is built, not ignored:
a setting read by nothing means the connection does not behave the way the configuration
says it does. Every one of these exceptions extends `LogicException`, and its message names
the replacement where there is one.

| Exception | When |
|---|---|
| `RedisClusterNotSupportedException` | an entry of `redis.clusters` is asked for |
| `UnsupportedRedisOptionException` | a connection entry carries a key outside the table above, set to something; `redis.options` carries a key the client does not read, set to something — it reads `prefix` and `cluster`, and takes `parameters` only empty; a value the feature would read differently from what it says — see below |
| `UnsupportedRedisCallException` | a call listed in [the facade](#the-facade) section |

All three live in `SConcur\Laravel\Redis\Exceptions\`.

"Set to something" means a value other than `null`, `false`, `''`, `0`, `'0'` and `[]`:
Laravel's skeleton carries `persistent => false` and `username => null`, and a setting
that is switched off asks for nothing. `redis.options.cluster` is accepted with any value:
it only says how the entries of `redis.clusters` are sharded, and asking for one of those
is refused on its own.

A value is refused too where the feature would take it without complaint and use it
differently from what it says:

| Value | Why |
|---|---|
| `database`, `port`, `timeout_ms`, `conn_max_lifetime_ms` that are not whole numbers, or a negative one | `'abc'` would become `0`, and `0` means something of its own for most of them; a number as a string, the way `env()` gives it, is accepted |
| `port` outside 1…65535 | no such port |
| `pool_size` outside 1…64 | the extension cuts a larger pool to 64 and reads 0 as its default |
| `scheme` other than `tcp`, `tls` or `unix` | no such transport |
| `host` with `://` in it | put the scheme in `scheme` |
| `host` starting with `/` | a socket is `scheme => unix` with `path` |
| `path` with `tcp` or `tls`; `host` or `port` with `unix`; `unix` without `path` | the key is not read under that scheme |
| `username` without `password` | the driver logs in only when there is a password, so the connection would run as the default user |
| `prefix` that is not a string | it is put in front of a key as it is |

The keys applications carry over most often, and why each is refused:

| Key | Instead |
|---|---|
| `max_retries`, `backoff_*` | the extension re-establishes a dropped connection by itself, and never retries a command: the server may have run it and lost only the answer |
| `timeout`, `read_timeout` | `timeout_ms` |
| `persistent` | connections always outlive the request — they are pooled in the extension |
| `name` | none: `CLIENT SETNAME` would rename a connection other coroutines share |
| `serializer`, `compression` | none on the client; the cache store serializes by itself |
| `protocol` | none: RESP3 changes the reply shape of several commands, and only RESP2 is supported |

## The facade

`'client' => 'sconcur'` puts `Redis::` on `SConcur\Laravel\Redis\Connection`, and it answers
the way Laravel's `PhpRedisConnection` does. `Redis::` is a thin layer rather than an
abstraction of its own: past the methods Laravel overrides, a call goes straight to the
client, so code written against the facade is code written against phpredis — Laravel's
default client and the most common one. `tests/Feature/Redis/PhpRedisParityTest.php` runs the
same calls through `PhpRedisConnection` and through this connection against one server and
requires the same answers.

```php
Redis::set('greeting', 'hello', 'EX', 60, 'NX');   // true, or false when the key exists
Redis::get('missing');                             // null
Redis::hget('hash', 'missing');                    // false
Redis::hgetall('hash');                            // ['field' => 'value']
Redis::zrange('ranking', 0, -1, true);             // ['member' => 1.0]
Redis::expire('greeting', 10);                     // true
Redis::type('greeting');                           // 1, Redis::REDIS_STRING
```

- The methods `PhpRedisConnection` overrides are overridden with the same signatures and
  results: `get`, `mget`, `set`, `setnx`, `hmget`, `hmset`, `hsetnx`, `lrem`, `blpop`,
  `brpop`, `spop`, `zadd`, `zrangebyscore`, `zrevrangebyscore`, `zinterstore`,
  `zunionstore`, `eval`, `evalsha`, `flushdb`, `executeRaw`, `pipeline`, `transaction`,
  `subscribe`, `psubscribe`.
- Any other call is read with phpredis's signature, and so is every call through
  `Redis::command()` and on the batch of a pipeline or a transaction. Where that signature
  takes an options array or an order of its own, `SConcur\Laravel\Redis\PhpRedisArguments`
  reads it: `zRange`/`zRevRange` with `true` or `['withscores', 'byscore', 'bylex', 'rev',
  'limit']`, `zRangeByScore`/`zRevRangeByScore` with `['withscores', 'limit']`, `zAdd` with
  its flags before the pairs, `zInterStore`/`zUnionStore`, `set` with a TTL or
  `['nx', 'ex' => 10]`, `lRem(key, value, count)`, `eval`/`evalSha(script, arguments,
  numberOfKeys)`, `sort`, `xAdd`, `xRead`, `lPos`, `getEx`, `copy`, `flushDb`/`flushAll`
  with `async`, `rawCommand`. The other methods take the command's arguments in order.
- On the facade itself the overrides win, as they do on `PhpRedisConnection`:
  `Redis::set()`, `Redis::lrem()` and `Redis::eval()` take Laravel's order, and phpredis's
  order of the same commands works through `Redis::command()` and inside a batch.
- The reply is put in phpredis's shape by `SConcur\Laravel\Redis\PhpRedisReplies`: a status
  of a command that only acknowledges is `true`; a nil is `false` (Laravel's overrides turn
  `get`, `mget`, `blpop` and `brpop` back to `null`); yes/no commands such as `expire`,
  `sismember` and `hexists` are `bool`; scores and float counters are `float`; `type` is
  phpredis's integer constant; `hgetall`, `config get`, `zpopmin`/`zpopmax` and anything
  `WITHSCORES` are maps; `hmget` inside a batch is keyed by field; `info` is parsed into a map;
  `xrange`/`xrevrange` answer id => fields, `xread`/`xreadgroup` stream => id => fields, and a
  read that found nothing is `[]`.
- One level of array in the arguments is spread in place, and how depends on the command:
  PHP stores `['0' => 'a', '1' => 'b']` exactly as it stores `['a', 'b']`, so the shape
  cannot tell a map from a list. `mset`, `msetnx`, `hset` and `hmset` spread every array as
  its keys followed by its values; `zadd` takes a trailing `member => score` map; the phpredis
  signatures above read their options arrays; every other command spreads a list element by
  element and refuses a map with `InvalidRedisArgumentException`. Anything deeper, and `bool`
  or `null` anywhere, is refused with the same exception.
- A blocking command gets the deadline its wait needs: its own wait plus `timeout_ms`, or no
  deadline for a wait without end. The wait is read from the arguments the way the extension
  reads it — `BLPOP`, `BRPOP`, `BZPOPMIN`, `BZPOPMAX`, `BLMOVE`, `BRPOPLPUSH`, `BLMPOP`,
  `BZMPOP`, `WAIT`, `WAITAOF`, and `XREAD`/`XREADGROUP` with `BLOCK`. A flat `timeout_ms`
  would be refused by the extension as shorter than the wait.
- `Redis::funnel()` and `Redis::throttle()` return the framework's limiters with one change:
  inside a coroutine the pause between attempts suspends the caller rather than freezing the
  worker, the same as [locks](#locks).
- `CommandExecuted` and `CommandFailed` are dispatched the same way the framework's
  connections dispatch them, once `Redis::enableEvents()` is on.
- The feature's own typed API is `Redis::connection()->client()`, a
  `SConcur\Features\Redis\Connection`: `scan()` as an iterator, `blPop()` with its deadline
  worked out, and the rest of `vendor/sconcur/sconcur/docs/redis.md`.

Where it differs from phpredis on purpose:

| phpredis | Here | Why |
|---|---|---|
| a refused command answers `false` and keeps the reason in `getLastError()` | `RedisCommandException` | `false` from `incr` or `hget` would read as an answer, and a `WRONGTYPE` as a missing key |
| `scan($cursor)` moves the cursor through a reference | `scan()`, `hscan()`, `sscan()` and `zscan()` answer `[cursor, items]`, items folded into a map for `hscan`/`zscan` | a facade call cannot carry a reference |
| a status reply a Lua script returns is `true` | the string `'OK'` | the feature hands a status and a bulk string over alike |

One connection object serves every coroutine of the process: it holds no socket and no
state of its own.

These calls are refused with `UnsupportedRedisCallException`, both as a magic call and
through `Redis::command()`:

| Call | Instead |
|---|---|
| `multi`, `exec`, `discard` | `Redis::transaction(function ($transaction) { ... })` |
| `watch`, `unwatch` | none: optimistic locking needs a connection pinned across round trips |
| `select` | the `database` key of the connection entry |
| `auth` | the `username` and `password` keys |
| `hello` | none: only RESP2 is supported |
| `subscribe`, `psubscribe` as a command | `Redis::subscribe($channels, $callback)` |
| `unsubscribe`, `punsubscribe` | none: a subscription ends when its callback throws or its coroutine ends |
| `ssubscribe`, `sunsubscribe` | none: sharded pub/sub exists for a cluster |

The feature refuses more than that on its own — `QUIT`, `CLIENT REPLY`, `MONITOR`,
`SWAPDB` and the rest of the list in its documentation — with its own
`UnsupportedRedisCommandException`.

## The key prefix

`prefix` in `redis.options`, or in a connection entry, where it wins over the options — the
way Laravel's `PhpRedisConnector` reads it. The connection puts it where phpredis puts its
`Redis::OPT_PREFIX`, so a key written by phpredis is found here under the same name.
`tests/Feature/Redis/PhpRedisParityTest.php` runs every call with a prefix on both clients
and requires the same answer and the same keys in the database.

```php
// redis.options.prefix = 'laravel_database_'
Redis::set('user:1', 'Ann');                          // SET laravel_database_user:1 Ann
Redis::eval($script, 1, 'user:1', 'arg');             // KEYS[1] is prefixed, ARGV[1] is not
Redis::executeRaw(['GET', 'user:1']);                 // GET user:1 — no prefix
Redis::keys('user:*');                                // ['laravel_database_user:1']
Redis::_prefix('user:*');                             // 'laravel_database_user:*'
```

The prefix goes on:

- every key of a command, wherever the command keeps it: `set`, `mget`, `mset`, `rename`,
  `blpop` (not the timeout), `eval`/`evalsha` (the keys, not the arguments), `zinterstore`,
  `xread` (the streams, not the ids), `bitop`, and the rest of the table in
  `SConcur\Laravel\Redis\KeyPrefix`;
- the channels of `publish` and `subscribe`, the patterns of `psubscribe`, the pattern of
  `keys`;
- the same calls inside `pipeline()` and `transaction()`.

It does not go on, again as with phpredis:

- `executeRaw()` and `rawCommand()`;
- a command missing from the table, such as a module's;
- the `MATCH` pattern of `scan()`, `hscan()`, `sscan()` and `zscan()`: write it with the
  prefix, `Redis::scan($cursor, ['match' => Redis::_prefix('user:*')])`;
- `BY`, `GET` and `STORE` of `sort`;
- a key name a Lua script builds from its arguments;
- `Redis::connection()->client()`, the feature's own object.

Replies keep the names the server holds, prefix included: `keys()` and `scan()` answer them
with it, and a subscription callback gets the channel with it.

## Pipelines and transactions

```php
$replies = Redis::pipeline(function ($pipe): void {
    $pipe->set('a', 1);
    $pipe->incr('a');
    $pipe->get('missing');
});
// [true, 2, false]

$replies = Redis::transaction(function ($transaction): void {
    $transaction->incrby('counter', 2);
    $transaction->lpush('events', 'inc');
});
```

The callback gets a `SConcur\Laravel\Redis\CommandBatch`. It takes the calls the object
phpredis hands a callback takes, with phpredis's signatures, and sends nothing until the
callback returns. Then the batch goes out in one round trip, and the call answers with the
replies in order, in phpredis's shape. `transaction()` wraps it in `MULTI`/`EXEC`, and the
server runs it as one unit.

- A command that fails while it runs takes its own place among the replies as `false`, as in
  phpredis, and the others still run — in a pipeline and in a transaction alike.
- A command the server refuses while a transaction is being queued (a wrong number of
  arguments, an unknown command) is different: `EXEC` answers `EXECABORT`, none of the
  commands run, and the call throws `RedisCommandException` where phpredis answers `false`.
- A batch with no commands answers `[]` without a round trip.
- Without a callback the batch itself is returned, and `exec()` sends it.
- `exec()` inside the callback throws `NestedPipelineExecutionException`: it would send the
  commands gathered so far on their own, outside the transaction.
- The callback cannot read anything as it goes — the replies all arrive at the end.

## Pub/Sub

```php
Redis::subscribe(['news'], function (string $payload, string $channel): void {
    // ...
});

Redis::psubscribe(['user:*'], function (string $payload, string $channel): void {
    // ...
});
```

The callback gets the payload and the channel, the way the framework's connections call
it. With a [key prefix](#the-key-prefix) the subscription is on the prefixed channels and
patterns, and the callback gets the channel with the prefix, as with phpredis. A subscription owns a connection of its own, because the protocol puts the
connection itself into subscriber mode. The loop ends when the callback throws, when the
coroutine running it ends, or with `RedisConnectionException` when the connection is lost;
the connection is released every way. A failure to close it does not replace the exception
that ended the loop.

`Redis::publish()` is an ordinary command and answers how many subscribers received the
message.

## The cache store

```php
// config/cache.php
'stores' => [
    'sconcur_redis' => [
        'driver'          => 'sconcur_redis',
        'connection'      => env('REDIS_CACHE_CONNECTION', 'cache'),
        'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
    ],
],
```

```dotenv
CACHE_STORE=sconcur_redis
```

| Key | Default | What it does |
|---|---|---|
| `connection` | `default` | the `database.redis` connection the values live on |
| `lock_connection` | `connection` | the connection the locks live on |
| `prefix` | `cache.prefix` | the store's prefix, after the connection's, in front of every key and lock name |
| `events` | `true` | cache events, as for any store |

The store builds its connections from `database.redis` itself, with the same
`SConcur\Laravel\Redis\Connector` the facade uses, rather than through `RedisManager`. So
it runs on the feature whatever `database.redis.client` says, and refuses the connection
entries the client refuses. `redis.options` belong to the facade's client: the store checks
them only when that client is `sconcur`, and otherwise leaves them to phpredis or predis.

The connection's `prefix` is read whichever client the facade is on. A key is the
connection's prefix, then the store's, then the key — the name the framework's `RedisStore`
writes on phpredis with the same configuration. The same goes for a lock, on the lock
connection's prefix. So the two stores share values and locks, which
`SconcurRedisStoreTest` checks against `RedisStore` on phpredis. Tagged values are the
exception, see below.

It does not reuse the framework's `RedisStore`: that one opens a transaction with
`multi()` and closes it with `exec()` as two separate calls, which on a connection shared
by every coroutine would put a neighbour's commands inside it. `SConcur\Laravel\Cache\Redis\Store`
is written on the feature's typed API instead.

| Method | Command |
|---|---|
| `get` | `GET` |
| `many` | `MGET` |
| `put` | `SET key value EX seconds`, at least 1 second |
| `putMany` | one `MULTI`/`EXEC` of `SET … EX` |
| `add` | `SET key value EX seconds NX` — atomic, no script |
| `increment`, `decrement` | `INCRBY`, `DECRBY` |
| `forever` | `SET` with no expiry |
| `forget` | `DEL` |
| `flush` | `FLUSHDB` |

- The storage format is the framework's `RedisStore` format, so the two read each other's
  keys. A finite number is stored as it is, which is what lets `INCRBY` work on it, and is
  read back as the numeric string Redis holds. Anything else goes through `serialize()`,
  and is read back with `cache.serializable_classes` as the allowed classes.
- `flush()` empties the whole database the connection points at, other keys included —
  the same as `RedisStore`. Give the cache a database of its own.
- Tags work through the framework's `TaggableStore`, which keeps them in the store itself.
  That is not the scheme of `RedisStore`, whose `RedisTaggedCache` keeps a sorted set per tag,
  so a value put with tags by one store is not found with tags by the other, and a tag
  flushed through one is not flushed for the other.

## Locks

`Cache::lock()` on this store takes the lock with `SET NX` (with `EX` when the lock has
seconds) and releases and refreshes it with the framework's own Lua scripts, so only the
owner can do either. `restoreLock()`, `forceRelease()`, `refresh()` and
`isOwnedByCurrentProcess()` work as on the framework's Redis store.

`block()` differs in one place, the pause between attempts. The framework waits with
`usleep()`, which inside a coroutine freezes the whole process — every other request of the
worker — for each pause. Inside a coroutine this store waits through the feature's
`Sleeper`, which suspends only the caller. Outside a coroutine it keeps the framework's
`Sleep`, so `Sleep::fake()` still works in tests. The pause is
`SConcur\Laravel\Support\CooperativeSleep`, and the limiters of the facade use it too.

The task pool's control channel is one such caller: it takes its key with `block()`.

## The queue

Laravel's own `redis` queue driver runs on this client unchanged: `RedisQueue` works through
Lua scripts, `BLPOP` and a few plain commands, and all of them go through the facade.

```php
// config/queue.php
'redis' => [
    'driver'      => 'redis',
    'connection'  => 'default',   // an entry of database.redis
    'queue'       => 'default',
    'retry_after' => 90,
    'block_for'   => 5,
],
```

```dotenv
REDIS_CLIENT=sconcur
QUEUE_CONNECTION=redis
```

Pushed, delayed and bulk jobs, retries and `block_for` are covered by
`tests/Feature/Redis/QueueTest.php`; the queue's keys under a prefix by
`tests/Feature/Redis/PrefixTest.php`.

- The client is chosen by `database.redis.client` for the whole application, so the queue and
  `Redis::` are on the same client.
- `queue:work` still runs one job at a time per process: the calls into Redis do not block
  the process, but the framework's worker loop is sequential.
- `bulk()` is not atomic. The framework hands the jobs to `pipeline()` and `transaction()`,
  but its callback pushes through the connection rather than through the batch it is given,
  so the jobs go out one command at a time.

## Moving from phpredis

The client is written so that an application on phpredis moves over with the same Redis,
the same keys and no data to migrate: the cache stays warm, queued jobs stay queued, and a
lock taken before the switch is still held. During a rolling deploy the processes still on
phpredis and those already on `sconcur` work side by side on the same data. The one thing
that does not carry over is a value cached with tags: the two stores keep tags differently
(see [the cache store](#the-cache-store)), so tagged values are computed again after the
switch, and a tag flushed by a process on one store does not reach the other.

1. Switch the client and the cache store in `.env`:

   ```dotenv
   REDIS_CLIENT=sconcur
   CACHE_STORE=sconcur_redis
   ```

   `QUEUE_CONNECTION=redis` stays as it is.

2. Write the `redis` section of `config/database.php` out whole: take the framework's and
   drop what the client refuses. For Laravel's own skeleton that is `max_retries` and
   `backoff_*` — the extension re-establishes a dropped connection by itself.

   ```php
   'redis' => [
       'client' => env('REDIS_CLIENT', 'sconcur'),

       'options' => [
           'cluster' => env('REDIS_CLUSTER', 'redis'),
           'prefix'  => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel'), '_') . '_database_'),
       ],

       'default' => [
           'url'      => env('REDIS_URL'),
           'host'     => env('REDIS_HOST', '127.0.0.1'),
           'username' => env('REDIS_USERNAME'),
           'password' => env('REDIS_PASSWORD'),
           'port'     => env('REDIS_PORT', '6379'),
           'database' => env('REDIS_DB', '0'),
       ],

       'cache' => [
           'url'      => env('REDIS_URL'),
           'host'     => env('REDIS_HOST', '127.0.0.1'),
           'username' => env('REDIS_USERNAME'),
           'password' => env('REDIS_PASSWORD'),
           'port'     => env('REDIS_PORT', '6379'),
           'database' => env('REDIS_CACHE_DB', '1'),
       ],
   ],
   ```

   Keep `prefix` exactly as it was: it is what keeps the keys where phpredis put them. If
   the application set `serializer` or `compression`, stop here — the client refuses both,
   and the values phpredis wrote with them could not be read.

3. Add the store to `config/cache.php`, on the same connections the `redis` store used:

   ```php
   'sconcur_redis' => [
       'driver'          => 'sconcur_redis',
       'connection'      => env('REDIS_CACHE_CONNECTION', 'cache'),
       'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
   ],
   ```

   The framework's `redis` store cannot stay the cache store: its `putMany()` calls `multi()`.
   With the same connections and the same `cache.prefix` the new store reads what the old one
   wrote — see [the cache store](#the-cache-store).

   Keep the `redis` entry itself when `SESSION_DRIVER=redis`: the session handler is built on
   that store by name. The session calls do not reach `putMany()`, but this package does not
   test the `redis` session driver on this client.

4. Look through the application's own Redis code for what differs on purpose:
   - a refused command throws `RedisCommandException` rather than answering `false`;
   - `scan()` answers `[cursor, items]` rather than moving a cursor passed by reference, and
     its `MATCH` pattern needs the prefix written in;
   - a status a Lua script returns is `'OK'`, not `true`;
   - `Redis::connection()->client()` is the feature's object, not `\Redis`, and puts no
     prefix on anything;
   - `multi()`/`exec()` as separate calls become `Redis::transaction(function ($transaction) { ... })`,
     and `watch()` has no replacement.

5. Check the packages that use Redis directly. A package requiring `ext-redis` in its
   `composer.json` keeps the extension; Horizon is not checked against this client.

6. Deploy, and check that the data is where it was:

   ```bash
   php artisan tinker --execute="dump(Redis::keys('*'))"   # the names phpredis wrote, prefix included
   php artisan tinker --execute="dump(Cache::get('some-key'))"
   php artisan queue:work --once                            # a job queued before the switch runs
   ```

   Once no process runs on phpredis, `ext-redis` can be removed.

## Limits

- No cluster, no sentinel, no sharded pub/sub.
- No `WATCH`, and no `MULTI`/`EXEC` as separate calls — only `transaction()` with a callback.
- RESP2 only.
- The framework's own `redis` cache store does not work on this client: its `putMany()`
  calls `multi()`, which is refused. Use `sconcur_redis`.
- This package does not test Laravel's `redis` session driver on this client.
