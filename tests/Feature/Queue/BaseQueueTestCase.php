<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Queue;

use Illuminate\Support\Facades\Queue as QueueFacade;
use SConcur\Laravel\Queue\Rabbitmq\Queue;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * Integration tests against the live broker the compose file raises.
 *
 * Each test gets a queue of its own, declared in setUp and deleted in tearDown, so a run
 * cannot read or disturb the demo's queue in the same vhost — and so a failed test
 * leaves nothing behind for the next one to trip over.
 */
abstract class BaseQueueTestCase extends BaseTestCase
{
    protected string $queueName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queueName = 'sconcur-test-' . bin2hex(random_bytes(6));

        $this->queue()->getConnection()->channel()
            ->queue($this->queueName)
            ->declare(durable: true, exclusive: false, autoDelete: false);
    }

    protected function tearDown(): void
    {
        $this->queue()->getConnection()->channel()->queue($this->queueName)->delete();

        parent::tearDown();
    }

    protected function queue(): Queue
    {
        $queue = QueueFacade::connection('sconcur_rabbitmq');

        assert($queue instanceof Queue);

        return $queue;
    }
}
