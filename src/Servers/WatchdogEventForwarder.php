<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

use Illuminate\Contracts\Events\Dispatcher;
use SConcur\Laravel\Servers\Events\WorkerWatchdogTriggered;
use SConcur\Worker\WatchdogEvent;

/**
 * The master's watchdog handler: turns what the library reports into a Laravel event, so
 * an application reacts to a killed worker with ordinary listeners — the ones listed in
 * config('sconcur.listeners') among them.
 */
readonly class WatchdogEventForwarder
{
    public function __construct(
        protected Dispatcher $dispatcher,
    ) {
    }

    public function __invoke(WatchdogEvent $watchdogEvent): void
    {
        $this->dispatcher->dispatch(new WorkerWatchdogTriggered($watchdogEvent));
    }
}
