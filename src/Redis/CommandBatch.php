<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Exceptions\Redis\NestedPipelineExecutionException;
use SConcur\Features\Redis\Pipeline;

/**
 * What `Redis::pipeline()` and `Redis::transaction()` hand to their callback: commands
 * called on it are collected, and nothing is sent until the batch is.
 *
 * The calls take phpredis's signatures and get the connection's key prefix, the way the
 * object phpredis hands a pipeline callback does, and the replies come back in phpredis's
 * shape (PhpRedisArguments, KeyPrefix, PhpRedisReplies).
 *
 * With a callback the batch is sent as soon as the callback returns, and the replies are
 * what the call answers with. Without one the batch itself is returned, and `exec()` sends
 * it. A command that fails while it runs takes its own place among the replies as `false`,
 * as in phpredis, and the others run — in a transaction too. A command the server refuses
 * while a transaction is being queued is different: EXEC answers EXECABORT, none of the
 * commands run, and the call throws.
 */
class CommandBatch
{
    private bool $collecting = false;

    /**
     * The command each reply answers, in order, so the replies can be put in phpredis's shape.
     *
     * @var list<array{string, list<mixed>}>
     */
    private array $commands = [];

    public function __construct(
        protected readonly Pipeline $pipeline,
        protected readonly bool $atomic,
        protected readonly string $prefix = '',
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function command(string $method, array $parameters = []): static
    {
        [$name, $arguments] = PhpRedisArguments::build(
            method: $method,
            parameters: $parameters,
        );

        UnsupportedCalls::assertSupported($name);

        if (strcasecmp($method, 'rawCommand') !== 0) {
            $arguments = KeyPrefix::apply(
                command: $name,
                arguments: $arguments,
                prefix: $this->prefix,
            );
        }

        $this->pipeline->command(
            name: $name,
            arguments: $arguments,
        );

        $this->commands[] = [
            $name,
            $arguments,
        ];

        return $this;
    }

    /**
     * Runs the callback that fills the batch. An exec() inside it would send the commands
     * gathered so far on their own — outside the transaction the caller asked for — so it
     * is refused for as long as the callback runs.
     */
    public function collect(callable $callback): void
    {
        $this->collecting = true;

        try {
            $callback($this);
        } finally {
            $this->collecting = false;
        }
    }

    /**
     * Sends the batch. An empty one answers with no replies rather than a round trip.
     *
     * @return list<mixed>
     */
    public function exec(): array
    {
        if ($this->collecting) {
            throw new NestedPipelineExecutionException(
                message: 'Calling exec() inside the pipeline or transaction callback would send those'
                    . ' commands on their own. Add the commands and let the call send them.',
            );
        }

        if ($this->pipeline->count() === 0) {
            return [];
        }

        $commands = $this->commands;

        $replies = $this->pipeline->execute(atomic: $this->atomic);

        $this->commands = [];

        $shaped = [];

        foreach ($replies as $position => $reply) {
            [$name, $arguments] = $commands[$position] ?? ['', []];

            $shaped[] = PhpRedisReplies::shape(
                command: $name,
                arguments: $arguments,
                reply: $reply,
            );
        }

        return $shaped;
    }

    /**
     * predis spells it execute().
     *
     * @return list<mixed>
     */
    public function execute(): array
    {
        return $this->exec();
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function __call(string $method, array $parameters): static
    {
        return $this->command(
            method: $method,
            parameters: $parameters,
        );
    }
}
