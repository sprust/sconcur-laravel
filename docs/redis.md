English | [Русский](redis.ru.md)

# Redis (`sconcur` client and `sconcur_redis` cache store)

SConcur's Redis feature, wired into Laravel in two places:

- the `sconcur` client of `RedisManager` — `Redis::`, `Redis::connection()`,
  `Redis::throttle()` and `Redis::funnel()`;
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
- [Pipelines and transactions](#pipelines-and-transactions)
- [Pub/Sub](#pubsub)
- [The cache store](#the-cache-store)
- [Locks](#locks)
- [Limits](#limits)

## Connections

Connections are described where Laravel keeps them, in the `redis` section of
`config/database.php`, as separate fields:

```php
// config/database.php
'redis' => [
    'client' => env('REDIS_CLIENT', 'sconcur'),

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
than adding to it — which is what is needed here: the framework's entries carry `prefix`,
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
| `UnsupportedRedisOptionException` | a connection entry carries a key outside the table above, set to something; `redis.options` carries `prefix` or `parameters` that are not empty, or any other key set to something; `scheme` is not `tcp`, `tls` or `unix`; `scheme => unix` has no `path` |
| `UnsupportedRedisCallException` | a call listed in [the facade](#the-facade) section |

All three live in `SConcur\Laravel\Redis\Exceptions\`.

"Set to something" means a value other than `null`, `false`, `''`, `0`, `'0'` and `[]`:
Laravel's skeleton carries `persistent => false` and `username => null`, and a setting
that is switched off asks for nothing. `redis.options.cluster` is accepted with any value:
it only says how the entries of `redis.clusters` are sharded, and asking for one of those
is refused on its own.

The keys applications carry over most often, and why each is refused:

| Key | Instead |
|---|---|
| `prefix` | none on the client: a raw command does not say which of its arguments are keys, so there is nothing to put a prefix on. The cache store has a `prefix` of its own |
| `max_retries`, `backoff_*` | the extension re-establishes a dropped connection by itself, and never retries a command: the server may have run it and lost only the answer |
| `timeout`, `read_timeout` | `timeout_ms` |
| `persistent` | connections always outlive the request — they are pooled in the extension |
| `name` | none: `CLIENT SETNAME` would rename a connection other coroutines share |
| `serializer`, `compression` | none on the client; the cache store serializes by itself |

## The facade

`'client' => 'sconcur'` puts `Redis::` on `SConcur\Laravel\Redis\Connection`. A magic call
is a raw command, spelled the way predis spells it:

```php
Redis::set('greeting', 'hello', 'EX', 60, 'NX');   // SET greeting hello EX 60 NX
Redis::get('greeting');
Redis::mget(['first', 'second']);                  // MGET first second
Redis::mset(['first' => 1, 'second' => 2]);        // MSET first 1 second 2
Redis::eval($script, 1, $key, $argument);          // EVAL script 1 key argument
Redis::command('lrange', ['list', 0, -1]);
```

That is the spelling Laravel's own code uses for Redis — `RedisLock`, `Redis::throttle()`
and `Redis::funnel()` — so they work on this client unchanged.

- The method name is the command name. One level of array is spread in place: a list
  element by element, a map as its key followed by its value. Anything deeper, and `bool` or
  `null` anywhere, is refused by the feature with `InvalidRedisArgumentException`.
- The reply is the one the server sent, in RESP2 terms: `OK` as the string `'OK'`, an
  integer as `int`, a nil as `null`, an array as a list. Nothing is reshaped: `hgetall`
  answers a flat list, not a map.
- `CommandExecuted` and `CommandFailed` are dispatched the same way the framework's
  connections dispatch them, once `Redis::enableEvents()` is on.
- The feature's typed API is `Redis::connection()->client()`, a
  `SConcur\Features\Redis\Connection`: `hGetAll()` folding the reply into a map, `scan()`
  as an iterator, `blPop()` with its deadline worked out.

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

## Pipelines and transactions

```php
$replies = Redis::pipeline(function (CommandBatch $pipe): void {
    $pipe->set('a', 1);
    $pipe->incr('a');
});
// ['OK', 2]

$replies = Redis::transaction(function (CommandBatch $transaction): void {
    $transaction->incrby('counter', 2);
    $transaction->lpush('events', 'inc');
});
```

The callback gets a `SConcur\Laravel\Redis\CommandBatch`, which takes the same calls as the
connection and sends nothing until the callback returns. Then the batch goes out in one
round trip, and the call answers with the replies in order. `transaction()` wraps it in
`MULTI`/`EXEC`, and the server runs it as one unit.

- A failed command in a pipeline takes its own place among the replies as a
  `SConcur\Features\Redis\Dto\ErrorReply`; the others still run. In a transaction the
  server aborts the whole of it instead.
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

The callback gets the payload and the channel, the way the framework's predis connection
calls it. A subscription owns a connection of its own, because the protocol puts the
connection itself into subscriber mode. The loop ends when the callback throws or the
coroutine running it ends, and the connection is released either way.

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
| `prefix` | `cache.prefix` | a prefix in front of every key and lock name |
| `events` | `true` | cache events, as for any store |

The store builds its connections from `database.redis` itself, with the same
`SConcur\Laravel\Redis\Connector` the facade uses, rather than through `RedisManager`. So
it runs on the feature whatever `database.redis.client` says, and refuses the same
settings the client refuses.

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

## Locks

`Cache::lock()` on this store takes the lock with `SET NX` (with `EX` when the lock has
seconds) and releases and refreshes it with the framework's own Lua scripts, so only the
owner can do either. `restoreLock()`, `forceRelease()`, `refresh()` and
`isOwnedByCurrentProcess()` work as on the framework's Redis store.

`block()` differs in one place, the pause between attempts. The framework waits with
`usleep()`, which inside a coroutine freezes the whole process — every other request of the
worker — for each pause. Inside a coroutine this store waits through the feature's
`Sleeper`, which suspends only the caller. Outside a coroutine it keeps the framework's
`Sleep`, so `Sleep::fake()` still works in tests.

The task pool's control channel is one such caller: it takes its key with `block()`.

## Limits

- No cluster, no sentinel, no sharded pub/sub.
- No key prefix on the facade.
- No `WATCH`, and no `MULTI`/`EXEC` as separate calls — only `transaction()` with a callback.
- RESP2 only.
- Replies are not reshaped on the facade; the typed API is `Redis::connection()->client()`.
- The framework's own `redis` cache store does not work on this client: its `putMany()`
  calls `multi()`, which is refused. Use `sconcur_redis`.
- This package does not test Laravel's `redis` queue and session drivers on this client.
