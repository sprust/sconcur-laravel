<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Exceptions\Redis\NestedPipelineExecutionException;
use SConcur\Features\Redis\Pipeline;

/**
 * What `Redis::pipeline()` and `Redis::transaction()` hand to their callback: commands
 * called on it the predis way are collected, and nothing is sent until the batch is.
 *
 * With a callback the batch is sent as soon as the callback returns, and the replies are
 * what the call answers with. Without one the batch itself is returned, and `exec()` sends
 * it. A command that fails while it runs takes its own place among the replies as a
 * `SConcur\Features\Redis\Dto\ErrorReply`, and the others run — in a transaction too. A
 * command the server refuses while a transaction is being queued is different: EXEC answers
 * EXECABORT, none of the commands run, and the call throws.
 */
class CommandBatch
{
    private bool $collecting = false;

    public function __construct(
        protected readonly Pipeline $pipeline,
        protected readonly bool $atomic,
    ) {
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    public function command(string $method, array $parameters = []): static
    {
        UnsupportedCalls::assertSupported($method);

        $this->pipeline->command(
            name: strtoupper($method),
            arguments: CommandArguments::flatten(
                command: $method,
                parameters: $parameters,
            ),
        );

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

        return $this->pipeline->execute(atomic: $this->atomic);
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
