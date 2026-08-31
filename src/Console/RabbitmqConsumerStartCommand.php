<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Queue\WorkerOptions;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Laravel\Queue\Rabbitmq\ConsumerRunner;

/**
 * Run a queue-consumer pool in the foreground: the worker script of a master group
 * (workerScript = artisan, workerArgs = [this command]), and a standalone consumer in
 * development.
 *
 * The consumer settings come from argv, which is how the master configures its
 * workers: a group's `server` block is forwarded to their argv verbatim. Symfony
 * Console rejects flags a command does not declare, so every one of them is declared
 * below even though QueueConsumer::fromArgs is what actually reads them.
 *
 * The two sets are not the same, though, and the raw argv cannot simply be handed
 * over: fromArgs refuses an argument it does not know, and --connection, --tries and
 * --backoff are ours rather than the runtime's. So the runtime's flags are rebuilt
 * from the parsed options and the rest is kept on this side.
 *
 * Run standalone there is no master to forward anything, so the flags are taken from the
 * group's own `server` block instead — the same values, by the same path the master
 * would have used.
 */
class RabbitmqConsumerStartCommand extends Command
{
    /** Artisan name, used by the master when it spawns this group's workers. */
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
        'masterPid',
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
        {--backoff= : Seconds to wait before a released job is retried}
        {--masterPid= : Master pid, injected by the supervisor for orphan self-termination}';

    protected $description = 'Run a SConcur AMQP queue-consumer pool in the foreground';

    public function handle(): int
    {
        $connection = $this->stringOption('connection')
            ?? (string) config('sconcur.queue.rabbitmq.connection', 'sconcur_rabbitmq');

        new ConsumerRunner(
            consumer: QueueConsumer::fromArgs($this->consumerArgs()),
            connectionName: $connection,
            options: $this->workerOptions(),
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
     * The `server` block of the group this command is the worker script of, for a run
     * with no master to forward it.
     *
     * The group is found by what it runs rather than by name, so renaming it in the
     * config does not quietly leave a standalone consumer on library defaults. Anything
     * structured — the queue list — travels as JSON, exactly as the master encodes it.
     *
     * @return list<string>
     */
    protected function configuredServerArgs(): array
    {
        $args = [];

        foreach ((array) config('sconcur.master.groups', []) as $group) {
            if (!is_array($group) || !in_array(self::NAME, (array) ($group['workerArgs'] ?? []), true)) {
                continue;
            }

            foreach ((array) ($group['server'] ?? []) as $key => $value) {
                $args[] = sprintf('--%s=%s', $key, static::flagValue($value));
            }

            break;
        }

        return $args;
    }

    protected static function flagValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * What Worker::process() reads. `timeout` is deliberately left at zero: the deadline
     * on a job belongs to the consumer runtime's handlerTimeoutMs, which unwinds the
     * coroutine, rather than to the worker's SIGALRM, which would take the process down
     * with every other handler running in it.
     */
    protected function workerOptions(): WorkerOptions
    {
        $config = (array) config('sconcur.queue.rabbitmq', []);

        return new WorkerOptions(
            backoff: (int) ($this->stringOption('backoff') ?? $config['backoff'] ?? 0),
            memory: (int) ($config['memory_mb'] ?? 128),
            timeout: 0,
            maxTries: (int) ($this->stringOption('tries') ?? $config['tries'] ?? 1),
        );
    }

    protected function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
