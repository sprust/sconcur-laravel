<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks\Events;

/**
 * A task is about to run one pass. Raised for the application to observe; the pool needs
 * no listener of its own.
 *
 * One of these per tick, always paired with a TaskTickFinished — the pool raises the
 * second one whatever ended the tick.
 */
readonly class TaskTickStarted
{
    public function __construct(
        public string $name,
    ) {
    }
}
