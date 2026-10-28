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
- [Events](#events)
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

## Events

Every tick raises a pair of events, so the application observes the pool without wrapping
each of its tasks in the same try/catch.

| Event | When | Fields |
| --- | --- | --- |
| `SConcur\Laravel\Tasks\Events\TaskTickStarted` | before the tick | `name` |
| `SConcur\Laravel\Tasks\Events\TaskTickFinished` | after it, whatever ended it | `name`, `result`, `exception` |

`exception` is `null` when the tick returned on its own. When it threw, it holds what was
thrown and `result` is `TickResultEnum::Failed`. The finished event is raised for a tick
the scheduler unwound as well — a stop or a blown deadline — before the unwind carries on.

```php
use Illuminate\Support\Facades\Event;
use SConcur\Laravel\Tasks\Events\TaskTickFinished;

Event::listen(function (TaskTickFinished $event): void {
    if ($event->exception !== null) {
        Metrics::increment('task.failed', ['task' => $event->name]);
    }
});
```

A listener cannot stop the pool: an exception out of a listener is reported through
`ExceptionHandler`, written to the log, and the loop carries on — the same treatment a
failed tick gets, and for the same reason.

## Configuration

```php
'tasks' => [
    'control_key'              => 'sconcur:tasks:control',
    'lock_path'                => storage_path('sconcur/runtime/tasks.lock'),
    'memory_mb'                => 256,
    'sleep_chunk_ms'           => 250,
    'preemption_quantum_ms'    => 0,
    'report_ticks'             => true,
    'shutdown_timeout_seconds' => 20,

    'list' => [
        ['name' => 'cleanup', 'task' => CleanupTask::class, 'idle' => 1, 'busy' => 0, 'backoff' => 3],
    ],
],
```

Tasks are resolved from the container by class name, so they are ordinary services with
dependency injection, and the config caches like any other.

The full list of ENV variables is in
[configuration.md](configuration.md#the-task-pool).

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
two pools with different `--only=` work side by side quite happily,
`sconcur:tasks:start --only=cron` in one terminal and `--only=monitor` in another, while a
second copy of the cron does not start.

`flock` rather than a cache lock, for the same reason the library's master uses it: the
kernel releases the lock when the process dies, `SIGKILL` included, so there is no stale
lock and no TTL to refresh.

## Stopping

Every reason leads down one path: the controller takes the tasks off the tick, waits for
the running ticks to finish, and exits. The reasons are `SIGTERM`/`SIGINT`, the
`sconcur:tasks:stop` command, and going past `memory_mb`.

`memory_mb` is compared against the larger of two figures: the PHP heap
(`memory_get_usage(true)`) and the resident set size of the process (`VmRSS` from
`/proc/self/status`). The heap alone does not see what the extension allocates — its
runtime, the drivers, their buffers — and a leak there would grow the process until the
container runs out of memory without ever reaching the limit. The RSS includes the
baseline of the process as well: about 60 MiB for the demo application's pool with the
extension loaded, so the default 256 leaves roughly 190 MiB for the tasks. Where `/proc` is
not available the heap decides alone. The log line names the figure that crossed the limit:
`memory limit reached (rss 312 MiB) — stopping`.

The exit code tells them apart, and that is not a detail. `sconcur:tasks:stop`, a signal
to a pool running without a master and the master going away exit with zero; `memory_mb`
and a signal under a master exit with `TaskPool::EXIT_RESTART` (75). The master's own
stop sends `SIGTERM` as well, but the master reads no exit code of a worker it drains, so
there the 75 changes nothing. Where it is read, the
signal is the master's watchdog or an operator's `kill`, and both want the pool back. The
`tasks` group declares `restartPolicy: on-failure`, so the master brings a new process up
after a 75 and leaves things alone after a zero. Under a master, then, `kill` restarts the
pool; `sconcur:tasks:stop` is what stops it. With the master's inherited `always` any exit
would mean a new pool within the second, and `sconcur:tasks:stop` would stop the pool
exactly until the next tick — that is, not stop it at all.

The controller is a member of the group like any other, only without useful work: it wakes
every `sleep_chunk_ms` and looks at the signal and at the control channel. This is not
decoration. The signals are recorded by the extension, the way the library's servers watch
theirs, with no PHP running inside the handler — and somebody has to come and take them:
a process whose coroutines are all parked waiting on the extension executes nothing, so
`SIGTERM` would sit there unread. Hence the pauses are cut into quanta: the library's own
servers poll at the same interval and for the same reason. Neither `ext-pcntl` nor
`ext-posix` is needed.

If somebody has not returned within `shutdown_timeout_seconds`, the controller names the
stuck tasks in the log and unwinds the group through `WaitGroup::stop()`. The whole group
unwinds at once — there is nothing to take a single coroutine down with: `WaitGroup` keeps
their fiber ids in a protected property, and `Scheduler::detach()` removes a coroutine from
scheduling without unwinding it, so the `finally` blocks never run. That is why a stop of
one task has no deadline at all: the task parks once its running tick returns, and the
other tasks keep working.

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
    'name'              => 'tasks',
    'role'              => 'tasks',
    'workerCount'       => 1,
    'restartPolicy'     => 'on-failure',
    'shutdownTimeoutMs' => 30000,
],
```

A worker of the group is a fork of the master: it runs `TaskPool` itself, without the
`sconcur:tasks:start` command, and a non-zero exit code of the pool leaves as the worker's.
Once its loop runs, the pool tells the master it is up (`Scheduler::markReady()`), so a
pool that dies at startup is told apart from one that works. A pool that exits before
that — a task lock held by a pool started with `sconcur:tasks:start`, for instance — is a
failed start: when it happens as the master starts, the group's single slot has failed
before any worker came up, and the master stops every group and exits with code `70`.

`workerCount` is exactly 1, and that is not a formality: `0` means to the master not "none"
but "one worker per core", and two pools side by side would mean two crons ticking the same
minute. `flock` guards against a second pool too, but the config should not lean on that.

The worker knows the master's pid from the library (`WorkerContext::masterPid`). The pool
uses it the way the library's servers do: on its tick the controller compares the parent
pid the extension reports with that pid and stops normally if the master has died and the kernel
has reassigned the parent. The comparison is on the parent rather than on "is such a pid
alive", because a pid can be reused.

**The telemetry is the pool's own, in PHP.** The panel is filled by snapshots the
extension's server and consumer runtimes send once a second; the pool starts neither of
them, so `TaskPoolTelemetry` samples and sends them itself. The channel is an open
contract: the unix socket from `SCONCUR_TELEMETRY_SOCKET`, a 4-byte big-endian length
prefix, the body `{"t":"snapshot","s":<snapshot>}`. The master puts the worker's name into
`SCONCUR_SERVER_NAME` as `<group>:<index>`, and the group is taken from it by the last
colon.

What PHP can measure: memory — the resident set size from `/proc/self/status`, its
proportional share and the same share of swap (`Pss`, `SwapPss`) from
`/proc/self/smaps_rollup` — and CPU from the delta of `/proc/self/stat`: the same sources
and fields the extension reads. Memory is read every five seconds, as the extension reads
it; the snapshots in between repeat the last reading. What it cannot: the number of live
tasks in the extension's runtime (`runtimeTasks`) simply does not exist for such a worker
and goes out as zero.

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

The pool sends its snapshots from PHP, so a frozen PHP thread shows as `hung` here, unlike
in the runtimes whose snapshots come from the extension's own thread. `hung` only marks
the worker; what acts on a frozen thread is the master's watchdog. Every return of the PHP
thread to the scheduler marks the worker alive, and the pool returns there all the time —
the controller's pauses go through `Sleeper`, and with `preemption_quantum_ms` above zero
preemption parks a tick that computes — so an idle pool is not taken for a stuck one. A
tick that holds the thread in a native call — `usleep()`, a PDO query, `curl` — marks
nothing, and neither does a computing tick with preemption off. Past `watchdogTimeoutMs`
(60 s by default, `SCONCUR_HTTP_WATCHDOG_TIMEOUT_MS`) the master sends the process
`SIGTERM`; the pool waits for the running ticks, exits with `EXIT_RESTART` as after any
signal under a master (see [Stopping](#stopping)), and the master brings a new one up. A
task that legitimately blocks that long needs the threshold raised past it, or `0` for the
`tasks` group.

A group the panel says nothing about — with the pool stopped, for instance — is drawn in
with zeros by the demo application's dashboard so that it does not look non-existent
(`demo/app/Telemetry/SconcurStatClient.php`).

## Preemption

`preemption_quantum_ms` turns on automatic coroutine switching: the extension interrupts
the VM on a timer and parks the current coroutine, so a tick that went into pure
computation cannot freeze its neighbours. It is `0` by default, that is off, as in the
library.

With it on, another task can run between any two statements of a tick, and some PHP state
belongs to the process rather than to a coroutine: `json_last_error()`, `preg_last_error()`,
`error_get_last()`, output buffers, error handlers. A neighbour that runs in between
overwrites it, and the tick reads another task's result. The engine checks the timer right
after an internal call returns, so a switch between `json_decode()` and `json_last_error()`
is the likely case, not a rare one. The full list, with the safe form of each, is in
`vendor/sconcur/sconcur/docs/coroutine-switching.md` (Process-wide state).

The price of `0`: a tick that computes without waiting holds every other coroutine of the
pool until it returns. That includes the controller, whose tick takes the signal the
extension recorded and polls the control channel, so
such a tick delays the pool's stop by as long as it computes. A tick that waits through
SConcur — `sconcur_mysql`, `Sleeper`, the feature's HTTP client — suspends its coroutine and
holds nobody.

A quantum above zero is for a pool whose tasks and their libraries read none of that state.
A coarse one is enough there: a few coroutines, most of the time asleep, nobody waiting for a
reply. With 1000 ms rather than the 5 ms the library recommends for a server, the worst case
reaction to `SIGTERM` is the quantum plus the sleep quantum, about 1.25 s against a 20 second
stop deadline.

Preemption does not evict a native blocking call — only PHP userland code. For that there
is the hard stop deadline.

With preemption on, a task holding a MySQL transaction on the shared `mysql` connection is
exposed too: there the PDO object is one per process, and preemption lets a neighbouring
task get inside somebody else's transaction. On `sconcur_mysql` there is no such thing — see
[database.md](database.md).
