<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisCallException;

/**
 * The calls the `sconcur` connection refuses by name, each with what to use instead.
 *
 * The feature refuses these commands itself, and its exception stays the last line for a
 * raw `Redis::command()`. This list is here for the message: the feature names its own
 * API as the replacement, and a Laravel application needs the facade's.
 */
class UnsupportedCalls
{
    private const string TRANSACTION = 'the commands between MULTI and EXEC would travel on a connection other'
        . ' coroutines share. Use transaction(function ($transaction) { ... }), which sends them as one unit';

    private const string SUBSCRIPTION = 'a subscription needs a connection of its own. Use subscribe($channels,'
        . ' $callback) or psubscribe($patterns, $callback)';

    private const array CALLS = [
        'multi'        => self::TRANSACTION,
        'exec'         => self::TRANSACTION,
        'discard'      => self::TRANSACTION,
        'watch'        => 'optimistic locking needs a connection pinned across round trips, which the sconcur'
            . ' client does not have',
        'unwatch'      => 'optimistic locking needs a connection pinned across round trips, which the sconcur'
            . ' client does not have',
        'select'       => 'every coroutine shares the connection it would switch. The database is the'
            . ' "database" key of the connection entry',
        'auth'         => 'every coroutine shares the connection it would log in again. The login is the'
            . ' "username" and "password" keys of the connection entry',
        'hello'        => 'every coroutine shares the connection it would renegotiate, and only RESP2 is supported',
        'subscribe'    => self::SUBSCRIPTION,
        'psubscribe'   => self::SUBSCRIPTION,
        'unsubscribe'  => 'a subscription ends when its callback throws or the coroutine running it ends',
        'punsubscribe' => 'a subscription ends when its callback throws or the coroutine running it ends',
        'ssubscribe'   => 'sharded pub/sub exists for a cluster, and neither is supported',
        'sunsubscribe' => 'sharded pub/sub exists for a cluster, and neither is supported',
    ];

    public static function assertSupported(string $method): void
    {
        $name = strtolower($method);

        $reason = self::CALLS[$name] ?? null;

        if ($reason === null) {
            return;
        }

        throw new UnsupportedRedisCallException(
            sprintf('%s is not available on the sconcur Redis client: %s.', strtoupper($name), $reason),
        );
    }
}
