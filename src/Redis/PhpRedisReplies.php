<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Features\Redis\Dto\ErrorReply;

/**
 * Turns a raw RESP2 reply into the shape phpredis answers with, command by command.
 *
 * `Redis::` is a thin layer: past the methods Laravel's PhpRedisConnection overrides, a call
 * goes straight to phpredis, and code written against the facade is code written against
 * phpredis's answers — the most common Redis client for PHP, and Laravel's default. The
 * rules here were taken by running the same calls through PhpRedisConnection and through
 * this client (tests/Feature/Redis/PhpRedisParityTest.php):
 *
 * - a status reply of a command that only acknowledges (`SET`, `MSET`, `RENAME`, …) is `true`;
 * - a nil is `false`, at any depth — Laravel's own overrides turn a few of them back into
 *   `null`, and Connection does the same;
 * - the commands that answer 0 or 1 for no or yes are `bool`;
 * - a score or a float counter is a `float`;
 * - `TYPE` is phpredis's integer constant;
 * - `HGETALL`, `CONFIG GET`, `ZPOPMIN`/`ZPOPMAX` and anything `WITHSCORES` fold into a map,
 *   and `HMGET` is keyed by the fields asked for;
 * - `INFO` is parsed into a map, numbers as numbers;
 * - `XRANGE`/`XREVRANGE` answer id => fields, `XREAD`/`XREADGROUP` stream => id => fields,
 *   and a read that found nothing is an empty array;
 * - an error inside a batch is `false`, which is what phpredis puts in its place.
 *
 * What cannot be followed is a status reply the command does not name. The feature hands
 * a status and a bulk string over alike, so `EVAL` of a script returning a status answers
 * `'OK'` where phpredis answers `true`.
 */
class PhpRedisReplies
{
    /** phpredis's Redis::REDIS_* constants, by the name TYPE answers with. */
    private const array TYPES = [
        'none'   => 0,
        'string' => 1,
        'set'    => 2,
        'list'   => 3,
        'zset'   => 4,
        'hash'   => 5,
        'stream' => 6,
    ];

    private const array ACKNOWLEDGING = [
        'SET',
        'SETEX',
        'PSETEX',
        'MSET',
        'RENAME',
        'HMSET',
        'LSET',
        'LTRIM',
        'FLUSHDB',
        'FLUSHALL',
        'RESTORE',
        'SAVE',
        'BGSAVE',
        'BGREWRITEAOF',
        'PFMERGE',
        'REPLICAOF',
        'SLAVEOF',
        'CONFIG',
        'SCRIPT',
        'XGROUP',
    ];

    private const array BOOLEAN = [
        'EXPIRE',
        'PEXPIRE',
        'EXPIREAT',
        'PEXPIREAT',
        'PERSIST',
        'RENAMENX',
        'HEXISTS',
        'SISMEMBER',
        'SMOVE',
        'MSETNX',
        'MOVE',
        'COPY',
    ];

    private const array FLOAT = [
        'INCRBYFLOAT',
        'HINCRBYFLOAT',
        'ZSCORE',
        'ZINCRBY',
        'GEODIST',
    ];

    private const array SCORED_WHEN_ASKED = [
        'ZRANGE',
        'ZREVRANGE',
        'ZRANGEBYSCORE',
        'ZREVRANGEBYSCORE',
        'ZRANDMEMBER',
        'ZUNION',
        'ZINTER',
        'ZDIFF',
    ];

    private const array SCORED_ALWAYS = [
        'ZPOPMIN',
        'ZPOPMAX',
    ];

    private const array STREAM_RANGES = [
        'XRANGE',
        'XREVRANGE',
    ];

    private const array STREAM_READS = [
        'XREAD',
        'XREADGROUP',
    ];

    /**
     * @param list<mixed> $arguments the raw arguments the command went out with
     */
    public static function shape(string $command, array $arguments, mixed $reply): mixed
    {
        $name = strtoupper($command);

        if ($reply instanceof ErrorReply) {
            return false;
        }

        $reply = self::nilToFalse($reply);

        if (($reply === 'OK') && in_array($name, self::ACKNOWLEDGING, true)) {
            return true;
        }

        if ($name === 'PING') {
            return ($arguments === []) ? true : $reply;
        }

        if ($name === 'TYPE') {
            return self::TYPES[(string) $reply] ?? 0;
        }

        if (in_array($name, self::BOOLEAN, true)) {
            return (bool) $reply;
        }

        if (in_array($name, self::FLOAT, true)) {
            return ($reply === false) ? false : (float) $reply;
        }

        if ($name === 'ZADD') {
            return self::hasFlag(arguments: $arguments, flag: 'INCR')
                ? (($reply === false) ? false : (float) $reply)
                : $reply;
        }

        if ($name === 'ZMSCORE') {
            return is_array($reply) ? self::floats($reply) : $reply;
        }

        if (($name === 'HMGET') && is_array($reply)) {
            return self::keyedBy(fields: array_slice($arguments, 1), values: $reply);
        }

        if ($name === 'HGETALL') {
            return is_array($reply) ? self::pairs($reply) : $reply;
        }

        if (($name === 'CONFIG') && is_array($reply) && self::firstIs(arguments: $arguments, value: 'GET')) {
            return self::pairs($reply);
        }

        if (in_array($name, self::SCORED_ALWAYS, true)) {
            return is_array($reply) ? self::scores($reply) : $reply;
        }

        if (in_array($name, self::SCORED_WHEN_ASKED, true) && self::hasFlag(arguments: $arguments, flag: 'WITHSCORES')) {
            return is_array($reply) ? self::scores($reply) : $reply;
        }

        if (in_array($name, self::STREAM_RANGES, true)) {
            return is_array($reply) ? self::entries($reply) : $reply;
        }

        if (in_array($name, self::STREAM_READS, true)) {
            return is_array($reply) ? self::streams($reply) : [];
        }

        if (($name === 'INFO') && is_string($reply)) {
            return self::info($reply);
        }

        return $reply;
    }

    /**
     * A flat `field, value, field, value` reply as a map.
     *
     * @param array<array-key, mixed> $reply
     *
     * @return array<array-key, mixed>
     */
    public static function pairs(array $reply): array
    {
        $values = array_values($reply);

        $map = [];

        for ($index = 0, $count = count($values); ($index + 1) < $count; $index += 2) {
            $map[(string) $values[$index]] = $values[$index + 1];
        }

        return $map;
    }

    /**
     * A flat `member, score, member, score` reply as member => float.
     *
     * @param array<array-key, mixed> $reply
     *
     * @return array<array-key, float>
     */
    public static function scores(array $reply): array
    {
        return array_map(
            static fn(mixed $score): float => (float) $score,
            self::pairs($reply),
        );
    }

    /**
     * Stream entries — `[id, [field, value, …]]` each — as id => field => value.
     *
     * @param array<array-key, mixed> $reply
     *
     * @return array<array-key, mixed>
     */
    private static function entries(array $reply): array
    {
        $entries = [];

        foreach ($reply as $entry) {
            if (!is_array($entry) || !array_key_exists(0, $entry)) {
                continue;
            }

            $entries[(string) $entry[0]] = is_array($entry[1] ?? null) ? self::pairs($entry[1]) : [];
        }

        return $entries;
    }

    /**
     * A stream read — `[stream, entries]` each — as stream => id => field => value.
     *
     * @param array<array-key, mixed> $reply
     *
     * @return array<array-key, mixed>
     */
    private static function streams(array $reply): array
    {
        $streams = [];

        foreach ($reply as $stream) {
            if (!is_array($stream) || !array_key_exists(0, $stream)) {
                continue;
            }

            $streams[(string) $stream[0]] = is_array($stream[1] ?? null) ? self::entries($stream[1]) : [];
        }

        return $streams;
    }

    /**
     * HMGET's answers keyed by the fields asked for, the way phpredis returns them.
     *
     * @param list<mixed>             $fields
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private static function keyedBy(array $fields, array $values): array
    {
        $values = array_values($values);

        $keyed = [];

        foreach ($fields as $position => $field) {
            $keyed[(string) $field] = $values[$position] ?? false;
        }

        return $keyed;
    }

    private static function nilToFalse(mixed $reply): mixed
    {
        if ($reply === null) {
            return false;
        }

        if (!is_array($reply)) {
            return $reply;
        }

        return array_map(self::nilToFalse(...), $reply);
    }

    /**
     * @param array<array-key, mixed> $reply
     *
     * @return array<array-key, float|false>
     */
    private static function floats(array $reply): array
    {
        return array_map(
            static fn(mixed $value): float|false => ($value === false) ? false : (float) $value,
            $reply,
        );
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function hasFlag(array $arguments, string $flag): bool
    {
        foreach ($arguments as $argument) {
            if (is_string($argument) && (strcasecmp($argument, $flag) === 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<mixed> $arguments
     */
    private static function firstIs(array $arguments, string $value): bool
    {
        $first = $arguments[0] ?? null;

        return is_string($first) && (strcasecmp($first, $value) === 0);
    }

    /**
     * INFO's `name:value` lines as a map, the way phpredis parses them: an integer or a
     * float value as a number, anything else as the string; section headers dropped.
     *
     * @return array<string, int|float|string>
     */
    private static function info(string $reply): array
    {
        $info = [];

        foreach (preg_split('/\r?\n/', $reply) ?: [] as $line) {
            if (($line === '') || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $value = substr($line, $separator + 1);

            $info[substr($line, 0, $separator)] = match (true) {
                preg_match('/^-?\d+$/', $value) === 1  => (int) $value,
                is_numeric($value)                     => (float) $value,
                default                                => $value,
            };
        }

        return $info;
    }
}
