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
 * It answers the way Laravel's PhpRedisConnection does, because that is what code written
 * against the facade was written against: phpredis is Laravel's default client and the most
 * common one. The methods PhpRedisConnection overrides are overridden here with the same
 * signatures and the same results; every other call is read with phpredis's signature
 * (PhpRedisArguments) and answered in phpredis's shape (PhpRedisReplies). The parity is
 * checked by running the same calls through both (tests/Feature/Redis/PhpRedisParityTest.php).
 *
 * Where it deliberately differs:
 *
 * - a command the server refuses throws RedisCommandException, where phpredis answers
 *   `false` and leaves the reason in getLastError();
 * - `scan()`, `hscan()`, `sscan()` and `zscan()` answer `[cursor, items]` for any cursor —
 *   phpredis moves the cursor through a reference, which a facade call cannot carry;
 * - a status reply `EVAL` returns from a script is the string `'OK'`, not `true`.
 *
 * A blocking command (`BLPOP`, `XREAD … BLOCK`, …) gets the deadline its wait needs rather
 * than the connection's flat one, which the extension would refuse as shorter than the wait.
 * The limiters of `funnel()` and `throttle()` pause between attempts without freezing the
 * worker. The feature's own typed API is `client()`.
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
     * @param string $key
     */
    public function get($key): mixed
    {
        $result = $this->execute(
            method: 'get',
            arguments: [$key],
        );

        return ($result !== false) ? $result : null;
    }

    /**
     * @param array<array-key, string> $keys
     *
     * @return list<mixed>
     */
    public function mget(array $keys): array
    {
        $result = $this->execute(
            method: 'mget',
            arguments: array_values($keys),
        );

        return array_values(array_map(
            static fn(mixed $value): mixed => ($value !== false) ? $value : null,
            (array) $result,
        ));
    }

    /**
     * Laravel's signature: `set($key, $value, 'EX', 10, 'NX')`.
     *
     * @param string          $key
     * @param string|null     $expireResolution
     * @param int|string|null $expireTTL
     * @param string|null     $flag
     */
    public function set($key, mixed $value, $expireResolution = null, $expireTTL = null, $flag = null): mixed
    {
        $arguments = [
            $key,
            $value,
        ];

        if ($expireResolution) {
            if ($flag !== null) {
                $arguments[] = $flag;
            }

            $arguments[] = $expireResolution;

            if ($expireTTL !== null) {
                $arguments[] = $expireTTL;
            }
        }

        return $this->execute(
            method: 'set',
            arguments: $arguments,
        );
    }

    /**
     * @param string $key
     */
    public function setnx($key, mixed $value): int
    {
        return (int) $this->execute(
            method: 'setnx',
            arguments: [
                $key,
                $value,
            ],
        );
    }

    /**
     * @param string $key
     *
     * @return list<mixed>
     */
    public function hmget($key, mixed ...$dictionary): array
    {
        if (count($dictionary) === 1) {
            $dictionary = (array) $dictionary[0];
        }

        return array_values((array) $this->execute(
            method: 'hmget',
            arguments: [
                $key,
                ...array_values($dictionary),
            ],
        ));
    }

    /**
     * @param string $key
     * @param mixed  ...$dictionary a map, or field, value, field, value
     */
    public function hmset($key, mixed ...$dictionary): mixed
    {
        $arguments = [$key];

        if (count($dictionary) === 1) {
            foreach ((array) $dictionary[0] as $field => $value) {
                array_push($arguments, (string) $field, $value);
            }
        } else {
            array_push($arguments, ...array_values($dictionary));
        }

        return $this->execute(
            method: 'hmset',
            arguments: $arguments,
        );
    }

    /**
     * @param string $hash
     * @param string $key
     */
    public function hsetnx($hash, $key, mixed $value): int
    {
        return (int) $this->execute(
            method: 'hsetnx',
            arguments: [
                $hash,
                $key,
                $value,
            ],
        );
    }

    /**
     * Laravel's order: count before value, the way the command takes them.
     *
     * @param string $key
     * @param int    $count
     */
    public function lrem($key, $count, mixed $value): mixed
    {
        return $this->execute(
            method: 'lrem',
            arguments: [
                $key,
                $count,
                $value,
            ],
        );
    }

    public function blpop(mixed ...$arguments): mixed
    {
        $result = $this->execute(
            method: 'blpop',
            arguments: CommandArguments::flatten(
                command: 'blpop',
                parameters: $arguments,
            ),
        );

        return empty($result) ? null : $result;
    }

    public function brpop(mixed ...$arguments): mixed
    {
        $result = $this->execute(
            method: 'brpop',
            arguments: CommandArguments::flatten(
                command: 'brpop',
                parameters: $arguments,
            ),
        );

        return empty($result) ? null : $result;
    }

    /**
     * One member without a count, a list of them with one — the way phpredis tells them
     * apart by whether the count was passed.
     *
     * @param string $key
     * @param int    $count
     */
    public function spop($key, $count = 1): mixed
    {
        return $this->execute(
            method: 'spop',
            arguments: func_get_args(),
        );
    }

    /**
     * @param string $key
     */
    public function zadd($key, mixed ...$dictionary): mixed
    {
        return $this->command('zadd', [$key, ...$dictionary]);
    }

    /**
     * @param string               $key
     * @param array<string, mixed> $options
     */
    public function zrangebyscore($key, mixed $min, mixed $max, $options = []): mixed
    {
        return $this->command('zrangebyscore', [$key, $min, $max, $options]);
    }

    /**
     * @param string               $key
     * @param array<string, mixed> $options
     */
    public function zrevrangebyscore($key, mixed $min, mixed $max, $options = []): mixed
    {
        return $this->command('zrevrangebyscore', [$key, $min, $max, $options]);
    }

    /**
     * @param string               $output
     * @param array<int, string>   $keys
     * @param array<string, mixed> $options
     */
    public function zinterstore($output, $keys, $options = []): mixed
    {
        return $this->command('zinterstore', [
            $output,
            $keys,
            $options['weights'] ?? null,
            $options['aggregate'] ?? 'sum',
        ]);
    }

    /**
     * @param string               $output
     * @param array<int, string>   $keys
     * @param array<string, mixed> $options
     */
    public function zunionstore($output, $keys, $options = []): mixed
    {
        return $this->command('zunionstore', [
            $output,
            $keys,
            $options['weights'] ?? null,
            $options['aggregate'] ?? 'sum',
        ]);
    }

    /**
     * @param int|string           $cursor
     * @param array<string, mixed> $options `match` and `count`
     *
     * @return array{string, array<array-key, mixed>}
     */
    public function scan($cursor, $options = []): array
    {
        return $this->cursor(
            method: 'scan',
            head: [],
            cursor: $cursor,
            options: $options,
        );
    }

    /**
     * @param string               $key
     * @param int|string           $cursor
     * @param array<string, mixed> $options
     *
     * @return array{string, array<array-key, float>}
     */
    public function zscan($key, $cursor, $options = []): array
    {
        [$next, $items] = $this->cursor(
            method: 'zscan',
            head: [$key],
            cursor: $cursor,
            options: $options,
        );

        return [
            $next,
            PhpRedisReplies::scores($items),
        ];
    }

    /**
     * @param string               $key
     * @param int|string           $cursor
     * @param array<string, mixed> $options
     *
     * @return array{string, array<array-key, mixed>}
     */
    public function hscan($key, $cursor, $options = []): array
    {
        [$next, $items] = $this->cursor(
            method: 'hscan',
            head: [$key],
            cursor: $cursor,
            options: $options,
        );

        return [
            $next,
            PhpRedisReplies::pairs($items),
        ];
    }

    /**
     * @param string               $key
     * @param int|string           $cursor
     * @param array<string, mixed> $options
     *
     * @return array{string, array<array-key, mixed>}
     */
    public function sscan($key, $cursor, $options = []): array
    {
        return $this->cursor(
            method: 'sscan',
            head: [$key],
            cursor: $cursor,
            options: $options,
        );
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
     * Laravel's signature, and Laravel's way of doing it: the script is loaded and then run
     * by its hash.
     *
     * @param string $script
     * @param int    $numkeys
     */
    public function evalsha($script, $numkeys, mixed ...$arguments): mixed
    {
        $hash = $this->redisClient->scriptLoad($script);

        return $this->execute(
            method: 'evalsha',
            arguments: [
                $hash,
                $numkeys,
                ...array_values($arguments),
            ],
        );
    }

    /**
     * @param string $script
     * @param int    $numberOfKeys
     */
    public function eval($script, $numberOfKeys, mixed ...$arguments): mixed
    {
        return $this->execute(
            method: 'eval',
            arguments: [
                $script,
                $numberOfKeys,
                ...array_values($arguments),
            ],
        );
    }

    public function flushdb(): mixed
    {
        return $this->command('flushdb', func_get_args());
    }

    /**
     * @param array<array-key, mixed> $parameters the command name, then its arguments
     */
    public function executeRaw(array $parameters): mixed
    {
        return $this->command('rawCommand', $parameters);
    }

    /**
     * A call with phpredis's signature, answered in phpredis's shape.
     *
     * @param string                  $method
     * @param array<array-key, mixed> $parameters
     */
    public function command($method, array $parameters = []): mixed
    {
        [$name, $arguments] = PhpRedisArguments::build(
            method: $method,
            parameters: $parameters,
        );

        return $this->execute(
            method: $name,
            arguments: $arguments,
            eventMethod: $method,
            eventParameters: $parameters,
        );
    }

    /**
     * Runs the subscription loop: the callback gets `($payload, $channel)` for every
     * message, the way the framework's connections call it.
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
     * The one way a command goes out: refused if the feature has no path for it, given the
     * deadline its wait needs, reported to the events, and answered in phpredis's shape.
     *
     * @param list<mixed>                  $arguments       the raw arguments
     * @param array<array-key, mixed>|null $eventParameters what the caller passed, for the events
     */
    private function execute(
        string $method,
        array $arguments,
        ?string $eventMethod = null,
        ?array $eventParameters = null,
    ): mixed {
        UnsupportedCalls::assertSupported($method);

        $eventMethod ??= strtolower($method);
        $eventParameters ??= $arguments;

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
            $this->events?->dispatch(new CommandFailed($eventMethod, $eventParameters, $exception, $this));

            throw $exception;
        }

        $timeMs = round((microtime(true) - $startedAt) * 1000, 2);

        $this->events?->dispatch(new CommandExecuted($eventMethod, $eventParameters, $timeMs, $this));

        return PhpRedisReplies::shape(
            command: $method,
            arguments: $arguments,
            reply: $result,
        );
    }

    /**
     * @param list<mixed>          $head
     * @param array<string, mixed> $options
     *
     * @return array{string, array<array-key, mixed>}
     */
    private function cursor(string $method, array $head, int|string $cursor, array $options): array
    {
        $reply = $this->execute(
            method: $method,
            arguments: [
                ...$head,
                (string) $cursor,
                'MATCH',
                $options['match'] ?? '*',
                'COUNT',
                $options['count'] ?? 10,
            ],
        );

        $reply = is_array($reply) ? array_values($reply) : [];

        return [
            (string) ($reply[0] ?? '0'),
            is_array($reply[1] ?? null) ? $reply[1] : [],
        ];
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

    /**
     * @param string       $method
     * @param array<mixed> $parameters
     */
    public function __call($method, $parameters): mixed
    {
        return parent::__call(strtolower($method), $parameters);
    }
}
