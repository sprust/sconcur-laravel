English | [Русский](database.ru.md)

# Database (`sconcur_mysql`)

A Laravel connection with SConcur's SQL feature behind it instead of PDO. A statement goes
into the extension while the calling coroutine is suspended, so concurrent handlers in
one process do not wait for each other on a shared blocking handle. Outside a coroutine
the same calls work synchronously.

`Connection` extends `Illuminate\Database\MySqlConnection`, so the grammars, the schema,
the post-processor and `instanceof MySqlConnection` all stay in place; only the methods
that would need a PDO object are replaced. They all still go through `Connection::run()`
— timing, `QueryExecuted`, the query log and the wrapping into `QueryException` work as
usual.

## Table of contents

- [Configuration](#configuration)
- [Which connection the application uses](#which-connection-the-application-uses)
- [Transactions](#transactions)
- [Differences from PDO](#differences-from-pdo)
- [Delay and confirms (`config/queue.php`)](#delay-and-confirms-configqueuephp)
- [Transactions on the PDO connection (important)](#transactions-on-the-pdo-connection-important)

## Configuration

```php
// config/database.php
'sconcur_mysql' => [
    'driver'   => 'sconcur_mysql',
    'host'     => env('DB_HOST'),
    'port'     => env('DB_PORT'),
    'database' => env('DB_DATABASE'),
    'username' => env('DB_USERNAME'),
    'password' => env('DB_PASSWORD'),
    'charset'  => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'strict'   => true,
    'max_open_conns' => 20,
],
```

`charset`, `collation`, `timezone` and `strict`/`modes` travel in the DSN rather than as
separate `SET` statements after connecting. The first three are connect options the
extension knows by name; `sql_mode` — and anything under `dsn_params` — is what this DSN
format says an unrecognised parameter is: a session system variable, issued as one `SET`
on every connection the pool opens, after the driver's own setup and therefore winning
over it. `unix_socket` is honoured too, as `unix(/path/to.sock)`.

`parseTime` is not sent, and would do nothing if it were: the extension accepts it and
ignores it, and `DATE`/`DATETIME`/`TIMESTAMP` always arrive RFC3339.

Four more keys of the same connection array tune the pool. They are read by `Connector`
and passed to the extension; where the array has none, the extension's own default stands.

| Key | Default | What it does |
|---|---|---|
| `timeout_ms` | `30000` (the extension's) | deadline for one statement; for a cursor, for its whole life |
| `max_open_conns` | `20` (the package's) | the pool size in the extension; `0` is not "no limit", it is the extension's own 32 |
| `max_idle_conns` | — | accepted and not applied: the pool keeps every idle connection up to the cap. It still keys the pool, so two values mean two pools |
| `conn_max_lifetime_ms` | — | connection lifetime; absent means no limit |

`max_open_conns` is the one default the package overrides, and a bounded pool is not
caution: every concurrent statement takes a connection of its own, so an unbounded pool
walks a fan-out straight into the server's `max_connections` (MySQL error 1040).

## Which connection the application uses

There is no runtime choice here, deliberately. The SQL feature works synchronously too —
outside a coroutine the same calls simply suspend nothing — so the connection is chosen
where any Laravel application chooses it: `DB_CONNECTION` and `database.default`.
Migrations, `Schema::create` with indexes, `hasTable` and `getColumnListing` all work
through this driver.

Keep the PDO-backed `mysql` connection in the config for whatever needs a real PDO object:
`schema:dump` calls `mysqldump` past the connection, and the `database` queue driver asks
PDO for the driver name and version.

Two more places in `config/queue.php` — `batching` and `failed` — must name the connection
as `null` rather than through `env('DB_CONNECTION')`: `null` follows `database.default`,
and then `failed_jobs` does not drift away from what everything else uses.

## Transactions

The nesting level lives not in a property of the connection (one object serves every
coroutine) but in `TransactionStore`: inside a coroutine that is the coroutine context,
outside one it is an array of the store's own rather than the root context. The difference
matters: the root context is never released and is read through by every coroutine, so a
transaction opened before the first fiber appeared would be inherited by every request,
message and task at once. The first level is a real `BEGIN` of the feature, the ones above
it are savepoints, the same as on PDO.

- Siblings do not see each other: concurrent requests and jobs are neighbours in the
  context tree, not ancestors, so one's transaction is invisible to another.
- A child coroutine inherits the transaction, and its queries go into it. Otherwise a
  `WaitGroup` of five `UPDATE`s inside `DB::transaction()` would quietly leave as five
  autocommits past it.
- Whoever opened a transaction closes it. A root-level `commit()`/`rollBack()` from
  another coroutine throws: it could commit the shared object, but the owner record in
  the context would stay put, pointing at a dead transaction.
- A nested level is opened and closed by the child coroutine itself. Savepoint names come
  from a counter shared per transaction rather than from the depth: by depth, two sibling
  coroutines would produce one name, and MySQL drops the previous savepoint on a repeated
  `SAVEPOINT`.

What remains: an unread cursor (`cursor()` interrupted before the end) holds the
transaction's connection, and the next command of any coroutine inside it will wait for
that. For a fan-out inside a transaction use `select()`/`get()`, which read the result set
whole.

`afterCommit` and `dispatchAfterCommit` are correct under concurrency: `db.transactions`
is a `CoroutineTransactionsManager`, which keeps one framework manager per coroutine and
only routes to the right one. It is registered always rather than by process type: outside
a coroutine it has one manager of its own and behaves indistinguishably from the stock
one. Without it, a single per-process singleton would key its records by connection name
rather than by whoever opened the transaction, and one coroutine's commit would run a
neighbour's `afterCommit` while that neighbour's transaction was still open.
`Model::saveOrFail()` is a transaction, so creating a model the ordinary way already lands
there.

## Differences from PDO

- Types. PDO with emulation returns everything as strings; the extension normalizes:
  integers → `int`, `FLOAT`/`DOUBLE` → `float`, `DECIMAL` → string, `NULL` → `null`.
  Eloquent hides that; a strict `===` against a string in application code does not.
  `TINYINT(1)` is an `int` rather than a `bool`, and an unsigned `BIGINT` above
  `PHP_INT_MAX` arrives as a decimal string, because it does not fit a signed 64-bit int.
- Dates. `DATE`/`DATETIME`/`TIMESTAMP` arrive as RFC3339 (`2026-09-05T10:17:40Z`), not as
  the `Y-m-d H:i:s` string PDO gives. Eloquent is unaffected — `Model::getDateFormat()`
  does not match, so `asDateTime()` falls through to `Date::parse()`, which reads it — but
  a raw `select()` value handed straight to something expecting the PDO spelling is not.
- An `UPDATE` answers with the rows it **matched**, not the rows it changed. The driver
  negotiates `CLIENT_FOUND_ROWS` and PDO does not, so `DB::update()` on a row already
  holding the new value returns 1 here and 0 there. Code reading that count as "did
  anything actually change" is reading something else. There is no switch: the flag is
  negotiated in the handshake, `clientFoundRows=false` in the DSN is refused rather than
  applied, and `ROW_COUNT()` answers the same number. The two connections can only be
  made to agree from the other side: `'options' => [PDO::MYSQL_ATTR_FOUND_ROWS => true]`
  on the `mysql` entry puts PDO on the same flag. Otherwise ask the question in the
  statement — exclude the rows that would not change, and matched is changed:

  ```php
  DB::table('notes')
      ->where('id', $id)
      ->whereRaw('NOT (title <=> ?)', [$title])
      ->update(['title' => $title]);
  ```

  `<=>` is the null-safe comparison, so a `NULL` column is compared rather than dropped
  the way `<>` would drop it.
- `getPdo()`/`getReadPdo()` throw: there is no PDO object. Code that needs PDO works
  through the `mysql` connection.
- `selectResultSets()` is not supported: the feature returns one result set per query.
- Rows always arrive as `stdClass` — that is Laravel's default `fetchMode`, and the
  connection does not let it be changed anyway.
- **Column order within a row is not guaranteed.** PDO returns them in `SELECT` order;
  here a row crosses the PHP↔extension boundary as a msgpack map, and a map does not
  preserve key order — so two identical queries in a row give different field orders.
  Reading by name
  works as usual, so the ORM, `->id`, `->toArray()` and `where` never notice. What does
  notice is whatever relies on the order: `array_values($row)`, destructuring
  `[$a, $b] = array_values(...)`, `fputcsv` of a row as-is, comparing two rows with `==`
  on arrays, and `json_encode` of an API response — the JSON keys will shuffle from one
  request to the next. If the order matters, set it yourself: list the fields when
  building the response rather than handing over the whole row.
- Read/write splitting (`read`/`write`/`sticky`) is not supported — the feature has one
  DSN.
- `pretend()` keeps its flag in the shared object: it must not be used in a coroutine
  runtime.
- `schema:dump` calls `mysqldump` past the connection — that is not about the driver.
  Migrations do work through it: `Schema` goes through the same `select`/`statement`.
- The `database` queue driver (`Illuminate\Queue\DatabaseQueue`) does not work on this
  connection: it asks PDO for the driver name and version. An application keeping its
  queue in a table has to name a connection for it explicitly.

Anything a connection is not named for follows `database.default`: `Auth` through the
eloquent provider, models without a `protected $connection`, the framework's own tables.
A model that needs different storage names its connection explicitly.

## Delay and confirms (`config/queue.php`)

`confirm_publishes` turns on a confirm for every publish and `confirm_timeout_seconds` is
how long to wait for it; a delayed publish is confirmed always, regardless of both.

## Transactions on the PDO connection (important)

This is about the ordinary `mysql` connection. `sconcur_mysql` has no such limit — see
the sections above.

While a coroutine holds a transaction on blocking PDO, control must not go to another
coroutine: PDO is shared per process, and the neighbour reaching the same physical
connection either lands inside your transaction or closes it. Isolating the transaction
counter does not fix that, which is why no separate DB methods were added.

The problem is not `await` as such but any coroutine switch. These cause one:

- any call going into the extension — Mongo, the SQL feature, the HTTP client, AMQP,
  `Sleeper`;
- `WaitGroup` — both when starting child coroutines and when waiting for them;
- preemptive switching by quantum (`preemption_quantum_ms` of the task pool): it switches
  even pure PHP code that calls nothing outside itself;
- `Fiber::suspend()` in somebody else's code — inside a package you called, say.

In practice, the only transaction that is safe on PDO under concurrency is one that does
nothing but SQL against that same PDO, and only with preemption off. Everything else goes
before `beginTransaction`, after `commit`, or into a queue.
