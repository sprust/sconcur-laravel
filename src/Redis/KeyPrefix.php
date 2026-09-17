<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

/**
 * Puts the connection's `prefix` on the keys of a raw command, where phpredis puts its
 * `Redis::OPT_PREFIX`.
 *
 * phpredis does not guess which arguments are keys: every method of its own knows where its
 * keys stand, and `rawCommand` gets no prefix at all. This table is that knowledge, taken by
 * watching what phpredis 6.3.0 sends (`MONITOR`) and pinned by running the same calls through
 * both clients (tests/Feature/Redis/PhpRedisParityTest.php). A command missing from it goes
 * out as it is, the way a command phpredis has no method for does.
 *
 * phpredis's quirks are kept rather than corrected, since keys written by it have to be found
 * here under the same names:
 *
 * - `SORT` prefixes its key only — `BY`, `GET` and `STORE` go out as they are;
 * - `SCAN … MATCH` is not prefixed, while the pattern of `KEYS` is;
 * - `PUBSUB NUMSUB` prefixes its channels, `PUBSUB CHANNELS` does not prefix its pattern;
 * - replies are not touched: `KEYS`, `SCAN` and `BLPOP` answer the names with the prefix.
 */
class KeyPrefix
{
    /** The first argument is the key. */
    private const array FIRST = [
        'GET',
        'SET',
        'SETNX',
        'SETEX',
        'PSETEX',
        'GETSET',
        'GETDEL',
        'GETEX',
        'APPEND',
        'STRLEN',
        'INCR',
        'INCRBY',
        'INCRBYFLOAT',
        'DECR',
        'DECRBY',
        'GETRANGE',
        'SETRANGE',
        'SUBSTR',
        'SETBIT',
        'GETBIT',
        'BITCOUNT',
        'BITPOS',
        'BITFIELD',
        'BITFIELD_RO',
        'EXPIRE',
        'PEXPIRE',
        'EXPIREAT',
        'PEXPIREAT',
        'EXPIRETIME',
        'PEXPIRETIME',
        'PERSIST',
        'TTL',
        'PTTL',
        'TYPE',
        'DUMP',
        'RESTORE',
        'MOVE',
        'KEYS',
        'PUBLISH',
        'HSET',
        'HSETNX',
        'HGET',
        'HMSET',
        'HMGET',
        'HGETALL',
        'HDEL',
        'HEXISTS',
        'HINCRBY',
        'HINCRBYFLOAT',
        'HKEYS',
        'HVALS',
        'HLEN',
        'HSTRLEN',
        'HRANDFIELD',
        'HSCAN',
        'HEXPIRE',
        'HPEXPIRE',
        'HEXPIREAT',
        'HPEXPIREAT',
        'HEXPIRETIME',
        'HPEXPIRETIME',
        'HTTL',
        'HPTTL',
        'HPERSIST',
        'HGETDEL',
        'HGETEX',
        'HSETEX',
        'LPUSH',
        'RPUSH',
        'LPUSHX',
        'RPUSHX',
        'LPOP',
        'RPOP',
        'LLEN',
        'LINDEX',
        'LINSERT',
        'LRANGE',
        'LREM',
        'LSET',
        'LTRIM',
        'LPOS',
        'SADD',
        'SREM',
        'SMEMBERS',
        'SISMEMBER',
        'SMISMEMBER',
        'SCARD',
        'SPOP',
        'SRANDMEMBER',
        'SSCAN',
        'ZADD',
        'ZREM',
        'ZSCORE',
        'ZMSCORE',
        'ZINCRBY',
        'ZCARD',
        'ZCOUNT',
        'ZLEXCOUNT',
        'ZRANGE',
        'ZREVRANGE',
        'ZRANGEBYSCORE',
        'ZREVRANGEBYSCORE',
        'ZRANGEBYLEX',
        'ZREVRANGEBYLEX',
        'ZRANK',
        'ZREVRANK',
        'ZREMRANGEBYRANK',
        'ZREMRANGEBYSCORE',
        'ZREMRANGEBYLEX',
        'ZPOPMIN',
        'ZPOPMAX',
        'ZRANDMEMBER',
        'ZSCAN',
        'PFADD',
        'GEOADD',
        'GEODIST',
        'GEOHASH',
        'GEOPOS',
        'GEOSEARCH',
        'XADD',
        'XLEN',
        'XRANGE',
        'XREVRANGE',
        'XTRIM',
        'XDEL',
        'XACK',
        'XPENDING',
        'XCLAIM',
        'XAUTOCLAIM',
        'XSETID',
        'SORT',
        'SORT_RO',
    ];

    /** Every argument is a key. */
    private const array ALL = [
        'MGET',
        'DEL',
        'EXISTS',
        'UNLINK',
        'TOUCH',
        'WATCH',
        'SDIFF',
        'SINTER',
        'SUNION',
        'SDIFFSTORE',
        'SINTERSTORE',
        'SUNIONSTORE',
        'PFCOUNT',
        'PFMERGE',
    ];

    /** The first two arguments are keys. */
    private const array FIRST_TWO = [
        'RENAME',
        'RENAMENX',
        'COPY',
        'LMOVE',
        'BLMOVE',
        'RPOPLPUSH',
        'SMOVE',
        'LCS',
        'ZRANGESTORE',
        'GEOSEARCHSTORE',
    ];

    /** Every argument but the last, which is the timeout. */
    private const array ALL_BUT_LAST = [
        'BLPOP',
        'BRPOP',
        'BZPOPMIN',
        'BZPOPMAX',
        'BRPOPLPUSH',
    ];

    /** Key and value, key and value. */
    private const array PAIRS = [
        'MSET',
        'MSETNX',
    ];

    /** Every argument after the first, which is the operation. */
    private const array AFTER_FIRST = [
        'BITOP',
    ];

    /** The position of `numkeys`; that many keys follow it. */
    private const array NUMKEYS_AT = [
        'LMPOP'      => 0,
        'SINTERCARD' => 0,
        'ZINTER'     => 0,
        'ZUNION'     => 0,
        'ZDIFF'      => 0,
        'ZMPOP'      => 0,
        'BLMPOP'     => 1,
        'BZMPOP'     => 1,
        'EVAL'       => 1,
        'EVALSHA'    => 1,
        'EVAL_RO'    => 1,
        'EVALSHA_RO' => 1,
        'FCALL'      => 1,
        'FCALL_RO'   => 1,
    ];

    /** A destination key, then `numkeys` and that many keys. */
    private const array DESTINATION_AND_NUMKEYS = [
        'ZINTERSTORE',
        'ZUNIONSTORE',
        'ZDIFFSTORE',
    ];

    /** A subcommand, then the key. */
    private const array AFTER_SUBCOMMAND = [
        'OBJECT',
        'XINFO',
        'XGROUP',
    ];

    /** The keys follow `STREAMS`, and the ids take the second half of what is left. */
    private const array STREAM_READS = [
        'XREAD',
        'XREADGROUP',
    ];

    /** The key, and the key after `STORE` or `STOREDIST`. */
    private const array GEO_RADIUS = [
        'GEORADIUS',
        'GEORADIUSBYMEMBER',
    ];

    /**
     * @param list<mixed> $arguments the raw arguments, without the command name
     *
     * @return list<mixed>
     */
    public static function apply(string $command, array $arguments, string $prefix): array
    {
        if ($prefix === '' || $arguments === []) {
            return $arguments;
        }

        $name  = strtoupper($command);
        $count = count($arguments);

        $positions = match (true) {
            in_array($name, self::FIRST, true)                   => [0],
            in_array($name, self::ALL, true)                     => range(0, $count - 1),
            in_array($name, self::FIRST_TWO, true)               => [
                0,
                1,
            ],
            in_array($name, self::ALL_BUT_LAST, true)            => ($count > 1) ? range(0, $count - 2) : [],
            in_array($name, self::PAIRS, true)                   => range(0, $count - 1, 2),
            in_array($name, self::AFTER_FIRST, true)             => ($count > 1) ? range(1, $count - 1) : [],
            array_key_exists($name, self::NUMKEYS_AT)            => self::numkeys(
                arguments: $arguments,
                at: self::NUMKEYS_AT[$name],
            ),
            in_array($name, self::DESTINATION_AND_NUMKEYS, true) => [
                0,
                ...self::numkeys(
                    arguments: $arguments,
                    at: 1,
                ),
            ],
            in_array($name, self::AFTER_SUBCOMMAND, true)        => [1],
            in_array($name, self::STREAM_READS, true)            => self::streams($arguments),
            in_array($name, self::GEO_RADIUS, true)              => [
                0,
                ...self::afterKeywords(
                    arguments: $arguments,
                    keywords: [
                        'STORE',
                        'STOREDIST',
                    ],
                ),
            ],
            $name === 'PUBSUB'                                   => self::pubsub($arguments),
            $name === 'MIGRATE'                                  => self::migrate($arguments),
            default                                              => [],
        };

        foreach ($positions as $position) {
            if (array_key_exists($position, $arguments) && is_scalar($arguments[$position])) {
                $arguments[$position] = $prefix . $arguments[$position];
            }
        }

        return $arguments;
    }

    /**
     * The channels or patterns of a subscription, which phpredis prefixes as it prefixes keys.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function names(array $names, string $prefix): array
    {
        return array_map(static fn(string $name): string => $prefix . $name, $names);
    }

    /**
     * @param list<mixed> $arguments
     *
     * @return list<int>
     */
    private static function numkeys(array $arguments, int $at): array
    {
        $numberOfKeys = $arguments[$at] ?? null;

        if (!is_numeric($numberOfKeys) || (int) $numberOfKeys < 1) {
            return [];
        }

        return range($at + 1, $at + (int) $numberOfKeys);
    }

    /**
     * @param list<mixed> $arguments
     *
     * @return list<int>
     */
    private static function streams(array $arguments): array
    {
        foreach ($arguments as $position => $argument) {
            if (is_string($argument) && strcasecmp($argument, 'STREAMS') === 0) {
                $numberOfKeys = intdiv(count($arguments) - $position - 1, 2);

                return ($numberOfKeys > 0) ? range($position + 1, $position + $numberOfKeys) : [];
            }
        }

        return [];
    }

    /**
     * @param list<mixed>  $arguments
     * @param list<string> $keywords
     *
     * @return list<int>
     */
    private static function afterKeywords(array $arguments, array $keywords): array
    {
        $positions = [];

        foreach ($arguments as $position => $argument) {
            if (is_string($argument) && in_array(strtoupper($argument), $keywords, true)) {
                $positions[] = $position + 1;
            }
        }

        return $positions;
    }

    /**
     * `PUBSUB NUMSUB` names channels; `CHANNELS` takes a pattern and `NUMPAT` nothing.
     *
     * @param list<mixed> $arguments
     *
     * @return list<int>
     */
    private static function pubsub(array $arguments): array
    {
        $subcommand = $arguments[0] ?? null;

        if (!is_string($subcommand) || strcasecmp($subcommand, 'NUMSUB') !== 0 || count($arguments) < 2) {
            return [];
        }

        return range(1, count($arguments) - 1);
    }

    /**
     * `MIGRATE host port key db timeout`, or an empty key and `KEYS key …` at the end.
     *
     * @param list<mixed> $arguments
     *
     * @return list<int>
     */
    private static function migrate(array $arguments): array
    {
        $positions = [];

        if (($arguments[2] ?? '') !== '') {
            $positions[] = 2;
        }

        foreach ($arguments as $position => $argument) {
            if (is_string($argument) && (strcasecmp($argument, 'KEYS') === 0) && ($position > 4)) {
                if ($position + 1 >= count($arguments)) {
                    return $positions;
                }

                return [
                    ...$positions,
                    ...range($position + 1, count($arguments) - 1),
                ];
            }
        }

        return $positions;
    }
}
