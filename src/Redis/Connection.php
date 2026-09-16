<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use Closure;
use Illuminate\Redis\Connections\Connection as BaseConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use SConcur\Features\Redis\Connection as RedisClient;
use SConcur\Features\Redis\Dto\Message;
use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisCallException;
use SConcur\Laravel\Redis\Limiters\ConcurrencyLimiterBuilder;
use SConcur\Laravel\Redis\Limiters\DurationLimiterBuilder;
use Throwable;

/**
 * A RedisManager connection on top of the feature — what `Redis::` answers with when
 * `database.redis.client` is `sconcur`.
 *
 * A magic call is a raw command, the way predis spells it: `Redis::set('k', 'v', 'EX', 10,
 * 'NX')` is `SET k v EX 10 NX`, `Redis::eval($script, 1, $key)` is `EVAL`. That is the
 * spelling Laravel's own consumers use — RedisLock, the concurrency and duration limiters —
 * so they work unchanged. The reply is what the server sent, in RESP2 terms: `OK` as a
 * string, integers as `int`, a nil as `null`, an array as a list.
 *
 * A blocking command (`BLPOP`, `XREAD … BLOCK`, …) gets the deadline its wait needs rather
 * than the connection's flat one, which the extension would refuse as shorter than the wait.
 * The limiters of `funnel()` and `throttle()` pause between attempts without freezing the worker.
 *
 * The typed API of the feature (`hGetAll()` folding the reply into a map, `scan()` as an
 * iterator, `blPop()` with its deadline worked out) is `Redis::connection()->client()`.
 *
 * The object holds no socket and no per-call state, so one instance serves every coroutine
 * of the process: the commands of concurrent coroutines travel down the extension's
 * multiplexed connections as one pipeline.
 */
class Connection extends BaseConnection
{
    /**
     * The feature's connection. Not the inherited `$client`: the framework types that one as
     * phpredis's, and the only methods of the base class reading it — command() and client()
     * — are both replaced here.
     */
    protected RedisClient $redisClient;

    public function __construct(RedisClient $client)
    {
        $this->redisClient = $client;
    }

    public function client(): RedisClient
    {
        return $this->redisClient;
    }

    /**
     * @param string                  $method
     * @param array<array-key, mixed> $parameters
     */
    public function command($method, array $parameters = []): mixed
    {
        UnsupportedCalls::assertSupported($method);

        $arguments = CommandArguments::flatten(
            command: $method,
            parameters: $parameters,
        );

        $startedAt = microtime(true);

        try {
            $result = $this->redisClient->command(
                name: strtoupper($method),
                arguments: $arguments,
                timeoutMs: $this->deadlineMs(
                    method: $method,
                    arguments: $arguments,
                ),
            );
        } catch (Throwable $exception) {
            $this->events?->dispatch(new CommandFailed($method, $parameters, $exception, $this));

            throw $exception;
        }

        $timeMs = round((microtime(true) - $startedAt) * 1000, 2);

        $this->events?->dispatch(new CommandExecuted($method, $parameters, $timeMs, $this));

        return $result;
    }

    /**
     * @param string $name
     */
    public function funnel($name): ConcurrencyLimiterBuilder
    {
        return new ConcurrencyLimiterBuilder($this, $name);
    }

    /**
     * @param string $name
     */
    public function throttle($name): DurationLimiterBuilder
    {
        return new DurationLimiterBuilder($this, $name);
    }

    /**
     * Commands sent as one batch: N commands, one round trip.
     *
     * @return CommandBatch|list<mixed> the replies when a callback is given, the batch otherwise
     */
    public function pipeline(?callable $callback = null): CommandBatch|array
    {
        return $this->batch(
            callback: $callback,
            atomic: false,
        );
    }

    /**
     * Commands sent inside MULTI/EXEC: the server runs them as one unit. The replies all
     * arrive after EXEC, so the callback cannot read anything as it goes.
     *
     * @return CommandBatch|list<mixed> the replies when a callback is given, the batch otherwise
     */
    public function transaction(?callable $callback = null): CommandBatch|array
    {
        return $this->batch(
            callback: $callback,
            atomic: true,
        );
    }

    /**
     * Runs the subscription loop: the callback gets `($payload, $channel)` for every
     * message, the way the predis connection calls it.
     *
     * The subscription owns a connection of its own, as the protocol requires. It ends when
     * the callback throws, when the coroutine running it ends, or with a
     * RedisConnectionException when the connection is lost; its connection is released
     * every way. A failure to close is not allowed to hide the exception that ended the loop.
     *
     * @param array<array-key, string>|string $channels
     * @param string                          $method
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        $names = array_values(array_map(strval(...), (array) $channels));

        $subscription = match ($method) {
            'subscribe'  => $this->redisClient->subscribe(channels: $names),
            'psubscribe' => $this->redisClient->subscribe(patterns: $names),
            default      => throw new UnsupportedRedisCallException(
                sprintf('"%s" is not a subscription the sconcur Redis client can open.', $method),
            ),
        };

        $failure = null;

        try {
            /** @var Message $message */
            foreach ($subscription as $message) {
                $callback($message->payload, $message->channel);
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $subscription->close();
        } catch (Throwable $exception) {
            if ($failure === null) {
                throw $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    /**
     * @return CommandBatch|list<mixed>
     */
    private function batch(?callable $callback, bool $atomic): CommandBatch|array
    {
        $commandBatch = new CommandBatch(
            pipeline: $this->redisClient->pipeline(),
            atomic: $atomic,
        );

        if ($callback === null) {
            return $commandBatch;
        }

        $commandBatch->collect($callback);

        return $commandBatch->exec();
    }

    /**
     * The deadline of one raw command: null — the connection's own — unless the command
     * waits on the server. A wait without end gets no deadline, since the extension refuses
     * one; any other wait gets the wait plus the connection's budget.
     *
     * @param list<mixed> $arguments
     */
    private function deadlineMs(string $method, array $arguments): ?int
    {
        $waitMs = BlockingCommands::waitMs(
            command: $method,
            arguments: $arguments,
        );

        if ($waitMs === null) {
            return null;
        }

        if ($waitMs === 0) {
            return 0;
        }

        return $this->redisClient->blockingDeadlineMs($waitMs / 1000);
    }
}
