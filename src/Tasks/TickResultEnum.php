<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * What one tick did, which is all the pool needs to know to pick the pause before
 * the next one. A task says what happened; how long to wait for it is configuration.
 */
enum TickResultEnum
{
    /** There was work and it was done: the pool takes the next tick after `busy`. */
    case Worked;

    /** Nothing to do: the pool waits `idle` before asking again. */
    case Idle;

    /** The tick threw; the pool reported it and waits `backoff`. */
    case Failed;
}
