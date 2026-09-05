<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * One periodic task of a TaskPool.
 *
 * A task owns the work of a single pass and nothing else: the loop, the pauses, the
 * error handling and the stop belong to the pool. That is what keeps `stop()` off this
 * interface — PHP cannot interrupt another fiber, so a stop method could only raise a
 * flag the task's own loop had to remember to check. With no loop of its own there is
 * nothing to check: the pool simply stops calling tick().
 *
 * Two rules, both of which the pool cannot enforce for you:
 *
 * 1. A tick must return on its own. Nothing can interrupt one, so a tick that blocks
 *    forever pins its coroutine until the pool's hard shutdown deadline unwinds the
 *    whole group.
 * 2. A tick must not mutate process-global state — config()->set, Auth, Request, static
 *    properties. Ticks of different tasks interleave, and with preemption on they
 *    interleave at any opcode boundary. A transaction is not in that category on the
 *    sconcur_mysql connection: its nesting level is kept per coroutine and the extension
 *    pins it to a physical connection of its own, so a neighbouring task cannot join it.
 *    On a PDO connection it still is — there the handle is one per process.
 */
interface TaskInterface
{
    /** Stable name; addresses this task in the control commands and in the log. */
    public function name(): string;

    /** One pass of work. Returns what happened, which decides the pause that follows. */
    public function tick(): TickResultEnum;
}
