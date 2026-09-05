English | [Русский](queue.ru.md)

# Queue (`sconcur_rabbitmq`)

A Laravel queue driver over SConcur's AMQP feature, plus a consumer pool that reads queues
as coroutines in one process instead of one blocking `queue:work` per worker. The win is
on the consumer side: both `ext-amqp` and `php-amqplib` hold the PHP thread on reading the
queue, whereas here only the coroutine itself is suspended — so one process carries
several queues, and a slow job costs one message rather than a worker.

## Declaring the queues is mandatory

A queue appears by itself on neither side: the driver declares nothing when publishing,
and neither does `QueueConsumer`. Topology belongs to its owner, and a consumer that
re-declared somebody else's queue with its own flags would drop the channel with a `406`
instead of reading. So `sconcur:rabbitmq:declare` has to run before the first publish and
before the pool starts, and it belongs on every install and deploy path rather than being
run by hand once. In this repository it is the `make queues-declare` target, called by
`make setup`; in an application its place is on every install and deploy path.

What happens if it is skipped:

- a publish goes to the default exchange with a routing key equal to the queue name, and
  the broker silently discards a message whose routing key nothing is bound to. There is
  no error — the jobs are simply lost. There is one exception: a delayed publish always
  goes through `publishConfirmed`, so it throws `UnroutableMessageException`;
- the pool doing `basic.consume` on a queue that does not exist gets
  `SConcur\Exceptions\Amqp\QueueException` reading
  `Server channel error: 404, message: NOT_FOUND - no queue 'default' in vhost '/'`.
  The worker exits `1`, the master brings up a replacement, and round it goes with growing
  backoff: the pool reads nothing, and in the telemetry panel its group stands with no
  workers.

The command declares what is listed in `sconcur.queue.rabbitmq.queues`, with the flags
`durable`, not `exclusive`, not `autoDelete` and no arguments — the same ones
`vladimir-yuldashev/laravel-queue-rabbitmq` uses (see [Compatibility](#compatibility)). Running it again
is harmless: declaring an existing queue with the same flags changes nothing, which is why
it is kept on the deploy path without checking whether it has run before.

The wait queues are none of its business: they are created by the delayed publish that
needs them — see [The connection](#the-connection).

## Compatibility

The wire format is not ours: the body, the message properties and the attempts header are
exactly what `vladimir-yuldashev/laravel-queue-rabbitmq` writes. A job sent by either
driver is read and executed by the other, in both directions.

Three things hold that together, and none of them can be changed unilaterally:

- the attempt counter lives in the `laravel.attempts` header rather than in `x-death`;
  `Worker::process()` builds `maxTries` and the `failed_jobs` record on it;
- the queue is declared with the same flags — `durable`, not `exclusive`, not
  `autoDelete`, no arguments; a mismatch gives a `406`, which closes the channel;
- a publish goes to the default exchange with a routing key equal to the queue name.

## The connection

```php
// config/queue.php
'sconcur_rabbitmq' => [
    'driver'    => 'sconcur_rabbitmq',
    'queue'     => env('RABBITMQ_QUEUE', 'default'),
    'dsn'       => env('SCONCUR_RABBITMQ_DSN'),   // amqp://user:pass@host:5672/%2f
],
```

AMQP has no delayed publish: `later()` and `release()` go through a queue nobody reads,
which sends the message back on TTL. A queue per delay rather than one queue with
per-message TTL, because a classic queue only expires from its head: the TTL is on the
queue, so every message inside it has one deadline and the head holds nobody up.

The wait queue is created by the very publish that needs it and is named after the exact
delay — `<queue>.wait.<ms>`. `vladimir-yuldashev/laravel-queue-rabbitmq` is built the same
way. That is what gives the precision: a ladder of fixed steps declared up front serves
only the delays built into it and rounds every other one to them, whereas a queue for an
exact delay holds exactly that. There is nothing to clean up either: `x-expires` tells the
broker to drop a wait queue that has gone unused for twice its delay, and the
re-declaration on every retry is what keeps alive the one still needed.

A delayed publish always goes through `publishConfirmed`, whatever the connection settings
say: an ordinary publish on a routing key nothing is bound to is silently discarded by the
broker — while `publishConfirmed` is mandatory by default and throws
`UnroutableMessageException`.

## The consumer

The pool is a group of the master, so it lives under the same supervisor as HTTP and
reports into the same telemetry panel (the `consumers` section).

```
php artisan sconcur:rabbitmq:declare
php artisan sconcur:servers:rabbitmq:start --queues='[{"name":"default","coroutineCount":8}]' --prefetchCount=1
```

Handling goes through `Illuminate\Queue\Worker::process()` — the job events, `maxTries`,
`backoff` and `failed_jobs` come ready-made. `Worker::daemon()` is not used: it is a
strictly sequential loop, one job at a time, and its `sleep()` blocks the process.

Writing to `failed_jobs` is done not by `Worker` but by the `queue:work` command the pool
replaces — so `ConsumerRunner` attaches the same `JobFailed` listener itself.

| ENV | Default | What it does |
|---|---|---|
| `SCONCUR_RABBITMQ_WORKER_COUNT` | `0` | processes in the pool; below `1` the group does not reach the master config at all |
| `SCONCUR_RABBITMQ_QUEUE` | `default` | the queue the pool reads |
| `SCONCUR_RABBITMQ_QUEUE_CONSUMERS` | `1` | that queue's weight — how many consumers it gets |
| `SCONCUR_RABBITMQ_PREFETCH_COUNT` | `1` | unacknowledged messages per consumer |
| `SCONCUR_RABBITMQ_HANDLER_TIMEOUT_MS` | `0` | deadline for one message in the handler; `0` — none |
| `SCONCUR_RABBITMQ_REQUEUE_ON_FAILURE` | `false` | requeue a failed message instead of dead-lettering it |
| `SCONCUR_RABBITMQ_MAX_MESSAGES` | `0` | drain and exit after N messages |
| `SCONCUR_RABBITMQ_MAX_RUNTIME_SECONDS` | `0` | drain and exit after N seconds |
| `SCONCUR_RABBITMQ_MAX_MEMORY_BYTES` | `0` | drain and exit on heap size |
| `SCONCUR_RABBITMQ_CONNECTION` | `sconcur_rabbitmq` | the `config/queue.php` connection the jobs run on |
| `SCONCUR_RABBITMQ_MEMORY_MB` | `128` | worker memory limit, MiB |
| `SCONCUR_RABBITMQ_TRIES` | `1` | attempts before `failed_jobs` |
| `SCONCUR_RABBITMQ_BACKOFF` | `0` | delay before a retry, seconds |

A zero in `SCONCUR_RABBITMQ_WORKER_COUNT` does not mean "no workers": to the master
`workerCount: 0` is one worker per CPU (`WorkerGroup`, `Cpu::count()`). So the pool is
turned off not by a zero in the group but by the group not being in the config.

The skeleton describes one queue because that is all it can know: the queue list and the
weights are what the application sets in the published file, where `queues` may be a list
of any length. It is also what `sconcur:rabbitmq:declare` declares, reading
`sconcur.queue.rabbitmq.queues`.

A queue's weight is the analogue of the number of `queue:work` processes on it: how many
consumers it gets, each on its own channel. The handler still runs in its own coroutine
per message.

`handlerTimeoutMs` is zero by default because a deadline does not slow down the job it
catches, it refuses it — and that is for the application, which knows its jobs, to decide.

`handlerTimeoutMs` unwinds a hung handler and refuses its message; the worker takes the
next one. `WorkerOptions::$timeout` is deliberately zero next to it: the Laravel worker's
`SIGALRM` would kill the process along with every handler running beside it.
