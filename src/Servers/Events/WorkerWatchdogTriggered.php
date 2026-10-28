<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers\Events;

use SConcur\Worker\WatchdogEvent;

/**
 * The master's watchdog acted on a worker whose PHP thread stopped coming back to the
 * scheduler: `HeartbeatLost` when it sent SIGTERM, `KillEscalated` when it had to follow
 * with SIGKILL, `KillSurvived` when even that did not free the slot.
 *
 * Raised in the master process, inside its supervision tick, so a listener has to be
 * short: the master supervises nothing while it runs. It must make no asynchronous call
 * either — no sconcur MySQL, Redis or HTTP client: the master's process refuses one, since
 * a process running the extension's runtime cannot fork its workers. Whatever a listener
 * throws is written to the master's journal and dropped.
 */
readonly class WorkerWatchdogTriggered
{
    public function __construct(
        public WatchdogEvent $watchdogEvent,
    ) {
    }
}
