<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Queue\QueueManager;
use SConcur\Laravel\Queue\Rabbitmq\Queue;

/**
 * Declare the queues the consumer pool reads. Nothing else declares them: the consumer
 * runtime declares nothing at all, so a pool started before its topology exists would
 * spin on 404.
 *
 * Wait queues are not declared here. They are created by the delayed publish that needs
 * one, named after its exact delay, and the broker removes each again once it has gone
 * unused — so there is no ladder to keep in step with the delays the application asks
 * for. See Queue::declareWaitQueue().
 *
 * The flags match what vladimir-yuldashev/laravel-queue-rabbitmq declares with —
 * durable, not exclusive, not auto-delete, no arguments — because a queue re-declared
 * with different flags answers 406 and takes the channel down with it. Anything that
 * changes here has to change there too, or the two stop being able to share a queue.
 */
class RabbitmqDeclareCommand extends Command
{
    protected $signature = 'sconcur:rabbitmq:declare
        {--connection= : The config/queue.php connection to declare on}';

    protected $description = 'Declare the SConcur AMQP queues the consumer pool reads';

    public function handle(QueueManager $manager): int
    {
        $config = (array) config('sconcur.queue.rabbitmq', []);

        $option = $this->option('connection');

        $connectionName = is_string($option) && $option !== ''
            ? $option
            : (string) ($config['connection'] ?? 'sconcur_rabbitmq');

        $queueNames = array_values(array_unique(array_map(
            strval(...),
            (array) ($config['queues'] ?? []),
        )));

        if ($queueNames === []) {
            $this->error('No queues configured in sconcur.queue.rabbitmq.queues.');

            return self::FAILURE;
        }

        $queue = $manager->connection($connectionName);

        if (!$queue instanceof Queue) {
            $this->error(sprintf('Connection [%s] is not a SConcur AMQP queue.', $connectionName));

            return self::FAILURE;
        }

        $channel = $queue->getConnection()->channel();

        foreach ($queueNames as $name) {
            $channel->queue($name)->declare(durable: true, exclusive: false, autoDelete: false);

            $this->info(sprintf('Declared [%s].', $name));
        }

        return self::SUCCESS;
    }
}
