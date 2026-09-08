<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * A listener the dispatcher has to push onto a queue instead of calling. Reaching the
 * queue at all is the whole point of it: it is the dispatcher's queue resolver that is
 * under test, and a replacement dispatcher that dropped the resolver fails here.
 */
class QueuedProbeListener implements ShouldQueue
{
    public function handle(string $event): void
    {
        //
    }
}
