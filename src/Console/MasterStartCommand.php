<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use SConcur\Laravel\Servers\MasterRunner;
use SConcur\Laravel\Servers\WatchdogEventForwarder;

/**
 * Start the SConcur master supervisor in the foreground. Builds the master from
 * config('sconcur.master') and runs it (it spawns and supervises workers).
 *
 * The watchdog's reports go out as the WorkerWatchdogTriggered event whether anyone
 * listens or not: with no listener the dispatch costs nothing, and the master journals
 * and counts the kill on its own either way.
 */
class MasterStartCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:servers:master:start';

    protected $description = 'Start the SConcur master supervisor';

    public function handle(WatchdogEventForwarder $watchdogEventForwarder): int
    {
        return new MasterRunner(
            onWatchdogEvent: $watchdogEventForwarder(...),
        )->start($this->masterConfig());
    }
}
