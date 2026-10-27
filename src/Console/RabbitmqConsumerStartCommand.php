<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Laravel\Queue\Rabbitmq\ConsumerRunner;
use SConcur\Laravel\Servers\MasterGroups;
use SConcur\Laravel\Servers\ServerFlags;
use SConcur\Laravel\Servers\WorkerRoleEnum;

/**
 * Run a queue-consumer pool in the foreground, outside the master: a standalone consumer
 * in development. Under the master a worker of the `rabbitmq` group runs ConsumerRunner
 * itself, without this command.
 *
 * Every flag QueueConsumer::fromArgs reads is declared below, beside three of this
 * command's own. The raw argv cannot simply be handed over: fromArgs refuses an argument
 * it does not know, and --connection, --tries and --backoff are ours rather than the
 * runtime's. So the runtime's flags are rebuilt from the parsed options and the rest is
 * kept on this side.
 *
 * With none of the runtime's flags given, they are taken from the `rabbitmq` group's own
 * `server` block instead — the values a worker of the master would have used.
 */
class RabbitmqConsumerStartCommand extends Command
{
    public const string NAME = 'sconcur:servers:rabbitmq:start';

    /** Options that belong to QueueConsumer rather than to this command. */
    protected const array RUNTIME_OPTIONS = [
        'queues',
        'prefetchCount',
        'handlerTimeoutMs',
        'requeueOnFailure',
        'maxMessages',
        'maxRuntimeSeconds',
        'maxMemoryBytes',
        'preemptionQuantumMs',
    ];

    protected $signature = self::NAME . '
        {--queues= : Queues and their weights as JSON, e.g. [{"name":"default","coroutineCount":8}]}
        {--prefetchCount= : Unacknowledged messages one consumer may hold}
        {--handlerTimeoutMs= : How long one message may spend in the handler}
        {--requeueOnFailure= : Put a failed message back (1) instead of dead-lettering it (0)}
        {--maxMessages= : Drain and stop after this many messages}
        {--maxRuntimeSeconds= : Drain and stop after this long}
        {--maxMemoryBytes= : Drain and stop once the PHP heap passes this}
        {--preemptionQuantumMs= : Preemption quantum while consuming}
        {--connection= : The config/queue.php connection to run jobs on}
        {--tries= : Attempts before a job is marked failed}
        {--backoff= : Seconds to wait before a released job is retried}';

    protected $description = 'Run a SConcur AMQP queue-consumer pool in the foreground';

    public function handle(): int
    {
        $tries   = $this->stringOption('tries');
        $backoff = $this->stringOption('backoff');

        ConsumerRunner::configured(
            consumer: QueueConsumer::fromArgs($this->consumerArgs()),
            connectionName: $this->stringOption('connection'),
            tries: $tries !== null ? (int) $tries : null,
            backoff: $backoff !== null ? (int) $backoff : null,
        )->run($this->getLaravel());

        return self::SUCCESS;
    }

    /**
     * The flags QueueConsumer understands, and only those: it throws on anything else.
     * A flag left unset is omitted rather than passed empty, so the library's own
     * default applies.
     *
     * @return list<string>
     */
    protected function consumerArgs(): array
    {
        $args = [];

        foreach (self::RUNTIME_OPTIONS as $name) {
            $value = $this->stringOption($name);

            if ($value === null) {
                continue;
            }

            $args[] = sprintf('--%s=%s', $name, $value);
        }

        return $args === [] ? $this->configuredServerArgs() : $args;
    }

    /**
     * The `server` block of the `rabbitmq` group, for a run with none of the runtime's
     * flags. Anything structured — the queue list — travels as JSON.
     *
     * @return list<string>
     */
    protected function configuredServerArgs(): array
    {
        return ServerFlags::fromBlock(MasterGroups::server(WorkerRoleEnum::Rabbitmq));
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
