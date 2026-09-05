<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Bus;

use Closure;

/**
 * How a broadcast crosses the process boundary.
 *
 * It has to cross one: an event is raised in an http worker, a queue consumer or a task,
 * and the connections live in the ws workers. Connection::write is routed by id inside
 * one process and goes no further.
 */
interface BroadcastBusInterface
{
    public function publish(BroadcastMessageDto $message): void;

    /**
     * Delivers messages to $handler until $shouldContinue answers false.
     *
     * A bus that has a loop of its own blocks here for as long as that condition holds; a
     * bus that has not (the local one) registers the handler and returns at once.
     *
     * @param Closure(BroadcastMessageDto): void $handler
     * @param Closure(): bool                    $shouldContinue
     */
    public function subscribe(Closure $handler, Closure $shouldContinue): void;

    /**
     * Whether subscribe() blocks, and therefore needs a coroutine of its own rather than
     * a call on the way into serve().
     */
    public function needsCoroutine(): bool;
}
