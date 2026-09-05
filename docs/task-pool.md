English | [Русский](task-pool.ru.md)

# The periodic task pool

The package's third runtime, beside the HTTP server (`src/Http`) and the queue consumer
pool (`src/Queue/Rabbitmq`). One process holds several periodic tasks, a coroutine each,
with a shared loop, shared pauses and one stop for all of them.

The reason: a typical background job in Laravel is a command with an eternal `while`
inside, a `sleep()` of its own and a home-made way to stop. Every such command occupies a
process with a bootstrap of its own, and its `sleep()` freezes that process entirely. In
the pool a sleep parks only its own coroutine, so one task can wait on Mongo while another
works, and the loop and the stop are written once.

## Table of contents

- [A task](#a-task)
- [The tick result and the pauses](#the-tick-result-and-the-pauses)
- [Configuration](#configuration)
- [Commands](#commands)
- [One copy of a task](#one-copy-of-a-task)
- [Stopping](#stopping)
- [Under the master](#under-the-master)
- [Preemption](#preemption)

## A task

```php
use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;

class CleanupTask implements TaskInterface
{
    public function name(): string
    {
        return 'cleanup';
    }

    public function tick(): TickResultEnum
    {
        $deleted = $this->action->handle();

        return $deleted > 0 ? TickResultEnum::Worked : TickResultEnum::Idle;
    }
}
```

A task describes one portion of work and nothing else. The loop, the pauses, the exception
handling, the rebuilding and the stop belong to the pool.

There is deliberately no `stop()` in the interface. PHP cannot step into somebody else's
fiber, so such a method could only raise a flag the task's loop would have to remember to
check. Since the task has no loop of its own, there is nothing to check: the pool simply
stops calling `tick()`.

Two rules the pool will not check for you:

1. **A tick has to return by itself.** There is nothing to interrupt it with. A tick that
   hangs forever holds its coroutine until the hard stop deadline, and that one unwinds the
   whole group.
2. **A tick does not touch process-global state** — `config()->set`, `Auth`, `Request`,
   static properties. Ticks of different tasks interleave. A transaction is not on that
   list if the connection is `sconcur_mysql`: its nesting level is kept per coroutine, and
   the extension pins it to a physical connection of its own, so a neighbouring task cannot
   enter it. On a PDO connection it is on that list: there the PDO object is one per
   process.

## The tick result and the pauses

`TickResultEnum` is the only thing a task tells the pool, and the pause follows from it:

| Result | When to return it | The pause |
| --- | --- | --- |
| `Worked` | there was work | `busy`, usually 0 — work the backlog down |
| `Idle` | no work was found | `idle` |
| `Failed` | set by the pool itself when it catches an exception | `backoff` |

An exception out of a tick is reported by the pool through `ExceptionHandler`, written to
the log and turned into `Failed`. It does not escape: `WaitGroup::iterate()` throws the
first exception of any member and stops the group in `finally`, so one task's escaping
exception would put out all the others.

## Configuration

```php
'tasks' => [
    'control_key'              => 'sconcur:tasks:control',
    'lock_path'                => storage_path('sconcur/runtime/tasks.lock'),
    'memory_mb'                => 256,
    'sleep_chunk_ms'           => 250,
    'preemption_quantum_ms'    => 1000,
    'report_ticks'             => true,
    'shutdown_timeout_seconds' => 20,

    'list' => [
        ['name' => 'cleanup', 'task' => CleanupTask::class, 'idle' => 1, 'busy' => 0, 'backoff' => 3],
    ],
],
```

Tasks are resolved from the container by class name, so they are ordinary services with
dependency injection, and the config caches like any other.

The full list of ENV variables is in [configuration.md](configuration.md).

## Commands

```sh
php artisan sconcur:tasks:start                          # the whole pool in the foreground
php artisan sconcur:tasks:start --only=cleanup           # only these tasks
php artisan sconcur:tasks:stop                           # stop the pool
php artisan sconcur:tasks:stop --task=cleanup            # take one task off the tick
php artisan sconcur:tasks:restart --task=cleanup         # rebuild the task instance
```

`stop` and `restart` put the command into the cache rather than sending a signal, which is
what lets the pool be driven from another container without knowing its pid.

Commands accumulate as a list rather than overwriting each other: the pool collects them on
its own tick, and two commands sent inside one such window (`stop --task=cron`, then
`stop --task=indexes`) would otherwise leave only the second. Both the append and the
collection run under a cache lock, because each is a read and a write with a network
round trip in between: two artisan processes started at once would both read an empty key
and both write a one-element list. If the lock is not taken within three seconds the work
is done without it — a reader that gave up would make the pool deaf, and a writer would
drop a stop command in a deploy script.

When collecting, the pool takes only the commands newer than its own start and clears the
key: otherwise a `stop` left in the cache would stop the replacement process the supervisor
brings up as well, and so on without end.

Stopping a single task lasts as long as the process. Turning a task off for good is a
config change, not a command.

`restart` means exactly one thing: throw the task instance away and build a new one. A task
accumulates state between ticks (the last minute processed, the timer of the next cleanup),
and resetting that is all there is to restart here.

## One copy of a task

At start the pool takes a `flock` on each task separately — `lock_path` plus the task's
name — and does not come up if even one of them is unavailable. The lock is on the task
rather than on the process: what must not run twice is the cron, not the pool as such. So
two entry points with `--only=` work side by side quite happily, `cron:start` in one
terminal and a monitor in another, while a second cron starts neither in the pool nor as a
separate command.

`flock` rather than a cache lock, for the same reason the library's master uses it: the
kernel releases the lock when the process dies, `SIGKILL` included, so there is no stale
lock and no TTL to refresh.

## Stopping

Every reason leads down one path: the controller takes the tasks off the tick, waits for
the running ticks to finish, and exits. The reasons are `SIGTERM`/`SIGINT`/`SIGQUIT`, the
`sconcur:tasks:stop` command, and going past `memory_mb`.

The exit code tells them apart, and that is not a detail. A stop on request exits with
zero, an exit on `memory_mb` with `TaskPool::EXIT_RESTART` (75). The `tasks` group declares
`restartPolicy: on-failure`, so the master brings a new process up after the second and
leaves things alone after the first. With the master's inherited `always` any exit would
mean a new pool within the second, and `sconcur:tasks:stop` would stop the pool exactly
until the next tick — that is, not stop it at all.

The controller is a member of the group like any other, only without useful work: it wakes
every `sleep_chunk_ms` and looks at the signal and at the control channel. This is not
decoration. A `pcntl` handler runs at an opcode boundary, that is only while PHP code is
executing, and a process whose coroutines are all parked waiting on the extension executes
nothing — so `SIGTERM` sits undelivered. Hence the pauses are cut into quanta: the
library's own servers poll at the same interval and for the same reason.

If somebody has not returned within `shutdown_timeout_seconds`, the controller names the
stuck tasks in the log and unwinds the group through `WaitGroup::stop()`. The whole group
unwinds at once — there is nothing to take a single coroutine down with: `WaitGroup` keeps
their fiber ids in a protected property, and `Scheduler::detach()` removes a coroutine from
scheduling without unwinding it, so the `finally` blocks never run. That is why the
deadline of a selective stop of one task unwinds nothing: it only writes a warning, since
the other tasks keep working.

The deadline has to be smaller than the one given by whoever supervises the pool, otherwise
the process is always finished off with `SIGKILL` and the graceful path never runs. Under
the master the chain grows outwards and has to be kept in this order:

```
the pool's shutdown_timeout_seconds (20 s)
  < the tasks group's shutdownTimeoutMs (30 000 ms)
    < stopwaitsecs of the master's program in supervisor (40 s)
```

## Under the master

The pool is designed to be a group of the master, and that is its normal mode:

```php
[
    'name'          => 'tasks',
    'workerScript'  => base_path('artisan'),
    'workerCount'   => 1,
    'workerArgs'    => ['sconcur:tasks:start'],
    'restartPolicy' => 'on-failure',
    'shutdownTimeoutMs' => 30000,
],
```

`workerCount` is exactly 1, and that is not a formality: `0` means to the master not "none"
but "one worker per core", and two pools side by side would mean two crons ticking the same
minute. `flock` guards against a second pool too, but the config should not lean on that.

The master appends `--masterPid` to the argv of any of its workers, so the command declares
that flag — otherwise Symfony Console rejects an unknown argument. The pool uses it the way
the library's servers do: on its tick the controller compares `posix_getppid()` with that
pid and stops normally if the master has died and the kernel has reassigned the parent. The
comparison is on the parent rather than on "is such a pid alive", because a pid can be
reused.

**The telemetry is the pool's own, in PHP.** The panel is filled by snapshots the
extension's server and consumer runtimes send once a second; the pool starts neither of
them, so `TaskPoolTelemetry` samples and sends them itself. The channel is an open
contract: the unix socket from `SCONCUR_TELEMETRY_SOCKET`, a 4-byte big-endian length
prefix, the body `{"t":"snapshot","s":<snapshot>}`. The master puts the worker's name into
`SCONCUR_SERVER_NAME` as `<group>:<index>`, and the group is taken from it by the last
colon.

What PHP can measure: RSS from `/proc/self/status` and CPU from the delta of
`/proc/self/stat` — the same sources the extension reads. What it cannot: the extension
runtime's memory and its number of live tasks simply do not exist for such a worker and go
out as zeros.

Of the three load sections, `requests` and `connections` are not sent — they belong to the
server and to the socket server — and a section nobody sent is not put into the reply by
the collector at all. The third, `consumers`, the pool does send: a tick is to a task what
a delivery is to a consumer, and the counters map onto it one to one, so the panel's
"In process / Finished / Refused" columns fill up with no change on its side.

| Field of the section | What it means for the pool |
| --- | --- |
| `coroutines` | how many tasks the pool holds — a coroutine each, that is its capacity |
| `delivered` | ticks that had work |
| `acked` | those of them that finished normally — with `refused` they make the Finished column |
| `refused` | those of them that threw — the Refused column, and part of Finished |
| `timed` / `avgMs` | how many ticks were measured and their average duration |
| `inFlight` + the age buckets | the ticks running right now, and how long they have been |

An idle tick (`TickResultEnum::Idle`) is counted nowhere. A task that polls once a second
for work that is not there is a consumer's empty wait rather than a delivery: counting them
would make Finished measure the polling interval and `avgMs` the cost of an empty poll
rather than the cost of the work.

The master sums this section across every worker, so its totals for deliveries per second
and average duration count the pool's ticks together with the AMQP pool's real deliveries.
The per-group numbers stay clean either way. It is turned off with `report_ticks`.

Sending is best-effort and at-most-once: the socket is non-blocking, a collector that has
stopped reading does not hold the pool up at all, and a lost frame costs a second of
freshness and is not repeated. A partly written frame is another matter: the stream is
length-prefixed, and half a frame makes the collector read the next header as the tail of
this body and lose alignment on everything after it. So a short write closes the
connection, the next send reconnects, and only that one snapshot is lost. A closed
connection means to the master that the worker has gone. The master marks a worker `hung`
when there has been no snapshot for longer than 15 seconds.

A group the panel says nothing about — with the pool stopped, for instance — is drawn in
with zeros by the application's dashboard so that it does not look non-existent
(`SconcurStatClient`).

## Preemption

`preemption_quantum_ms` turns on automatic coroutine switching: the extension interrupts
the VM on a timer and parks the current coroutine, so a tick that went into pure
computation cannot freeze its neighbours. It is on, with a quantum of 1000 ms.

The one that must not be frozen is the controller above all: its tick is what delivers the
signal (a `pcntl` handler runs only while PHP code is executing) and polls the control
channel. Without preemption a long non-preemptible stretch inside somebody else's `tick()`
delays both, which means it hits the pool's stop.

The quantum is deliberately coarser than the library's default of 5 ms. Those 5 ms are
meant for a server where dozens of handlers share the thread and a neighbouring request's
latency is counted in milliseconds; here there are a few coroutines, most of the time is
sleep, and nobody is waiting for a reply. The worst case reaction to `SIGTERM` is the
preemption quantum plus the sleep quantum, about 1.25 s against a 20 second stop deadline.

Preemption does not evict a native blocking call — only PHP userland code. For that there
is the hard stop deadline.

`0` turns it off. That is what a task holding a MySQL transaction on the shared `mysql`
connection needs: there the PDO object is one per process, and preemption lets a
neighbouring task get inside somebody else's transaction. On `sconcur_mysql` there is no
such thing — see [database.md](database.md).
