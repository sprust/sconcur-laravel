<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Exceptions\Amqp\ChannelException;
use SConcur\Exceptions\Amqp\QueueException;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\ClassifyingBus;
use SConcur\Laravel\Ws\WsBusOptions;

/**
 * Which failures the subscriber reads as "the queue was simply quiet".
 *
 * The distinction decides what happens next: idleness loops straight back to reopen the
 * consumer, while anything else pauses, drops the connection and rebuilds it. Reading a
 * real failure as idleness sends the loop round with no pause and a dead handle, which is
 * exactly what matching the bare word "timeout" used to do — the library says "command
 * timeout exceeded" and "wait timeout exceeded" for deadlines that are not idleness at
 * all.
 */
class IdleTimeoutTest extends BaseTestCase
{
    private ClassifyingBus $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new ClassifyingBus(options: new WsBusOptions());
    }

    #[Test]
    public function aSilentQueueIsIdleness(): void
    {
        self::assertTrue($this->bus->classify(new QueueException(message: 'Consumer timeout exceed')));
    }

    #[Test]
    public function aCommandDeadlineIsNot(): void
    {
        self::assertFalse($this->bus->classify(new QueueException(message: 'command timeout exceeded')));
    }

    #[Test]
    public function aWaitDeadlineIsNot(): void
    {
        self::assertFalse($this->bus->classify(new QueueException(message: 'wait timeout exceeded')));
    }

    #[Test]
    public function aDeletedQueueIsNot(): void
    {
        self::assertFalse($this->bus->classify(
            new ChannelException(message: "Server channel error: 404, message: NOT_FOUND - no queue 'x'"),
        ));
    }
}
