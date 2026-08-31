<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Queue;

use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The command that must run before the first publish and before the pool starts.
 * Nothing else declares a queue: the driver declares nothing on publish, the consumer
 * runtime declares nothing at all.
 *
 * These cover the refusals — reaching a broker is the integration test's business.
 */
class RabbitmqDeclareCommandTest extends BaseTestCase
{
    #[Test]
    public function itRefusesAnEmptyQueueList(): void
    {
        config()->set('sconcur.queue.rabbitmq.queues', []);

        $command = $this->artisan('sconcur:rabbitmq:declare');

        assert($command instanceof PendingCommand);

        $command->expectsOutputToContain('No queues configured')->assertFailed();
    }

    /**
     * Declaring on a connection of another driver would either do nothing or declare
     * somebody else's topology, so the command checks what it was handed.
     */
    #[Test]
    public function itRefusesAConnectionThatIsNotASconcurQueue(): void
    {
        config()->set('sconcur.queue.rabbitmq.queues', ['tests']);

        $command = $this->artisan('sconcur:rabbitmq:declare', ['--connection' => 'sync']);

        assert($command instanceof PendingCommand);

        $command->expectsOutputToContain('is not a SConcur AMQP queue')->assertFailed();
    }
}
