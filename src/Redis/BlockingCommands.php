<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

/**
 * How long a raw command waits on the server, read out of its arguments the way the
 * extension reads it (ext/src/features/redis/commands.rs, `blocking_timeout_ms`).
 *
 * The extension refuses a blocking command whose deadline does not exceed its wait, and one
 * that waits forever under any deadline at all. The typed `blPop()` of the feature works
 * its deadline out; a raw `Redis::blpop('queue', 5)` has to have it worked out here, or a
 * connection with `timeout_ms => 5000` refuses it.
 */
class BlockingCommands
{
    /** The commands whose wait is in seconds, and the argument position it is at; -1 is the last. */
    private const array SECONDS_AT = [
        'BLPOP'      => -1,
        'BRPOP'      => -1,
        'BZPOPMIN'   => -1,
        'BZPOPMAX'   => -1,
        'BLMOVE'     => 4,
        'BRPOPLPUSH' => 2,
        'BLMPOP'     => 0,
        'BZMPOP'     => 0,
    ];

    /** The commands whose wait is in milliseconds, and the argument position it is at. */
    private const array MILLISECONDS_AT = [
        'WAIT'    => 1,
        'WAITAOF' => 2,
    ];

    private const array STREAM_READS = [
        'XREAD',
        'XREADGROUP',
    ];

    /**
     * The wait in milliseconds, `0` for a wait without end, or null when the command does
     * not wait or its wait cannot be located — then the connection's own deadline stands.
     *
     * @param list<mixed> $arguments
     */
    public static function waitMs(string $command, array $arguments): ?int
    {
        $name = strtoupper($command);

        if (array_key_exists($name, self::SECONDS_AT)) {
            $position = self::SECONDS_AT[$name];

            if ($position < 0) {
                $position += count($arguments);
            }

            $seconds = self::number($arguments[$position] ?? null);

            return $seconds === null ? null : (int) ($seconds * 1000);
        }

        if (array_key_exists($name, self::MILLISECONDS_AT)) {
            $milliseconds = self::number($arguments[self::MILLISECONDS_AT[$name]] ?? null);

            return $milliseconds === null ? null : (int) $milliseconds;
        }

        if (in_array($name, self::STREAM_READS, true)) {
            return self::blockOptionMs($arguments);
        }

        return null;
    }

    /**
     * The BLOCK option of a stream read. Only before STREAMS — after it the arguments are
     * keys and ids, where `block` is an ordinary name.
     *
     * @param list<mixed> $arguments
     */
    private static function blockOptionMs(array $arguments): ?int
    {
        foreach ($arguments as $position => $argument) {
            if (!is_string($argument)) {
                continue;
            }

            if (strcasecmp($argument, 'STREAMS') === 0) {
                return null;
            }

            if (strcasecmp($argument, 'BLOCK') !== 0) {
                continue;
            }

            $milliseconds = self::number($arguments[$position + 1] ?? null);

            if ($milliseconds !== null) {
                return (int) $milliseconds;
            }
        }

        return null;
    }

    private static function number(mixed $value): ?float
    {
        if ((is_int($value) || is_float($value) || is_string($value)) && is_numeric($value)) {
            return (float) $value;
        }

        return null;
    }
}
