<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Adapters;

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Events\AsyncDispatcher;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use Workbench\App\Listeners\QueuedProbeListener;

/**
 * The dispatcher replaces the one Illuminate\Events\EventServiceProvider binds, so it has
 * to arrive with everything that one is given — not only with a per-coroutine defer().
 */
class AsyncDispatcherTest extends BaseTestCase
{
    #[Test]
    public function itQueuesAListenerThatShouldBeQueued(): void
    {
        Queue::fake();

        self::assertInstanceOf(AsyncDispatcher::class, $this->getApp()->make('events'));

        Event::listen('sconcur.probe', QueuedProbeListener::class);
        Event::dispatch('sconcur.probe');

        Queue::assertPushed(CallQueuedListener::class);
    }
}
