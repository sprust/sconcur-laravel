<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers\Events;

use SConcur\Worker\WorkerContext;

/**
 * A worker of the master has just been forked, and its role is about to build the
 * group's server, consumer or task pool: the place for what has to be done once per
 * worker rather than once per master — reseeding, opening a connection of its own,
 * tagging the logs with the worker's slot.
 *
 * Raised in the worker, not in the master, so a listener may make asynchronous calls. It
 * runs before the worker says it is up: an exception it lets out ends the worker, and a
 * group whose every worker ends that way stops the master with code 70.
 */
readonly class WorkerForked
{
    public function __construct(
        public WorkerContext $workerContext,
    ) {
    }
}
