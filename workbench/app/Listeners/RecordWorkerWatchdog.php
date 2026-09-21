<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;

/**
 * Keeps what it heard, so a test can tell that a listener named in
 * config('sconcur.listeners') was registered and reached. Static because the container
 * builds a fresh instance for every dispatch.
 */
class RecordWorkerWatchdog
{
    /** @var list<WorkerWatchdogTriggered> */
    public static array $heard = [];

    public function handle(WorkerWatchdogTriggered $workerWatchdogTriggered): void
    {
        self::$heard[] = $workerWatchdogTriggered;
    }
}
