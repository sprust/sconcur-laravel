<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;

/**
 * Turns a call spelled the way phpredis's own method takes it into the raw command.
 *
 * A facade call Laravel does not override goes to phpredis's method of that name, and so
 * does `Redis::command()` and every call on the object a pipeline callback receives. Most of
 * those methods take the command's arguments in order, and CommandArguments lays those out.
 * The ones here take an options array or an order of their own, and are read the way
 * phpredis reads them:
 *
 * - `zRange(key, start, end, bool|['withscores', 'byscore', 'bylex', 'rev', 'limit'])`,
 *   `zRevRange(key, start, end, bool|['withscores'])`,
 *   `zRangeByScore`/`zRevRangeByScore(key, min, max, ['withscores', 'limit'])`;
 * - `zInterStore`/`zUnionStore(destination, keys, weights, aggregate)`;
 * - `zAdd(key, [options], score, member, …)`;
 * - `set(key, value, ttl|['nx', 'xx', 'get', 'keepttl', 'ex' => …, 'px' => …])`;
 * - `lRem(key, value, count)`;
 * - `eval`/`evalSha(script, arguments, numberOfKeys)`;
 * - `sort(key, ['by', 'limit', 'get', 'sort', 'alpha', 'store'])`;
 * - `xAdd(key, id, fields, maxLength, approximate)`, `xRead(streams, count, blockMs)`;
 * - `lPos(key, element, ['rank', 'count', 'maxlen'])`, `getEx(key, ['EX' => …, 'PERSIST'])`,
 *   `copy(source, destination, ['db', 'replace'])`;
 * - `flushDb(async)`/`flushAll(async)`;
 * - `rawCommand(command, …arguments)`.
 */
class PhpRedisArguments
{
    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return array{string, list<mixed>} the command name and its raw arguments
     */
    public static function build(string $method, array $parameters): array
    {
        $name = strtoupper($method);

        $parameters = array_values($parameters);

        return match ($name) {
            'RAWCOMMAND'                        => self::rawCommand($parameters),
            'ZRANGE'                            => [$name, self::zRange($parameters)],
            'ZREVRANGE'                         => [$name, self::zRevRange($parameters)],
            'ZRANGEBYSCORE', 'ZREVRANGEBYSCORE' => [$name, self::rangeByScore($parameters)],
            'ZINTERSTORE', 'ZUNIONSTORE'        => [$name, self::zStore($parameters)],
            'ZADD'                              => [$name, self::zAdd($parameters)],
            'SET'                               => [$name, self::set($parameters)],
            'LREM'                              => [$name, self::lRem($parameters)],
            'EVAL', 'EVALSHA'                   => [$name, self::evaluate($parameters)],
            'SORT'                              => [$name, self::sort($parameters)],
            'XADD'                              => [$name, self::xAdd($parameters)],
            'XREAD'                             => [$name, self::xRead($parameters)],
            'LPOS'                              => [$name, self::lPos($parameters)],
            'GETEX'                             => [$name, self::getEx($parameters)],
            'COPY'                              => [$name, self::copy($parameters)],
            'FLUSHDB', 'FLUSHALL'               => [$name, self::flush($parameters)],
            default                             => [
                $name,
                CommandArguments::flatten(
                    command: $name,
                    parameters: $parameters,
                ),
            ],
        };
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return array{string, list<mixed>}
     */
    private static function rawCommand(array $parameters): array
    {
        $command = array_shift($parameters);

        if (!is_string($command) || ($command === '')) {
            throw new InvalidRedisArgumentException(
                message: 'rawCommand() needs the command name as its first argument.',
            );
        }

        return [
            strtoupper($command),
            CommandArguments::flatten(
                command: $command,
                parameters: $parameters,
            ),
        ];
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function zRange(array $parameters): array
    {
        [$key, $start, $end] = self::head(parameters: $parameters, count: 3);

        $options = $parameters[3] ?? null;

        $arguments = [
            $key,
            $start,
            $end,
        ];

        if ($options === true) {
            $arguments[] = 'WITHSCORES';

            return $arguments;
        }

        if (!is_array($options)) {
            return $arguments;
        }

        $options = array_change_key_case($options);

        foreach (['byscore' => 'BYSCORE', 'bylex' => 'BYLEX', 'rev' => 'REV'] as $option => $flag) {
            if (!empty($options[$option])) {
                $arguments[] = $flag;
            }
        }

        array_push($arguments, ...self::limit($options));

        if (!empty($options['withscores'])) {
            $arguments[] = 'WITHSCORES';
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function zRevRange(array $parameters): array
    {
        [$key, $start, $end] = self::head(parameters: $parameters, count: 3);

        $scores = $parameters[3] ?? null;

        $arguments = [
            $key,
            $start,
            $end,
        ];

        if (($scores === true) || (is_array($scores) && !empty(array_change_key_case($scores)['withscores']))) {
            $arguments[] = 'WITHSCORES';
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function rangeByScore(array $parameters): array
    {
        [$key, $minimum, $maximum] = self::head(parameters: $parameters, count: 3);

        $options = array_change_key_case((array) ($parameters[3] ?? []));

        $arguments = [
            $key,
            $minimum,
            $maximum,
        ];

        if (!empty($options['withscores'])) {
            $arguments[] = 'WITHSCORES';
        }

        array_push($arguments, ...self::limit($options));

        return $arguments;
    }

    /**
     * `limit` as `[offset, count]` or, the way Laravel's own overrides also accept it,
     * `['offset' => …, 'count' => …]`.
     *
     * @param array<array-key, mixed> $options
     *
     * @return list<mixed>
     */
    private static function limit(array $options): array
    {
        $limit = $options['limit'] ?? null;

        if (!is_array($limit)) {
            return [];
        }

        if (!array_is_list($limit)) {
            $limit = [
                $limit['offset'] ?? 0,
                $limit['count'] ?? -1,
            ];
        }

        return [
            'LIMIT',
            $limit[0] ?? 0,
            $limit[1] ?? -1,
        ];
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function zStore(array $parameters): array
    {
        $destination = $parameters[0] ?? null;
        $keys        = array_values((array) ($parameters[1] ?? []));
        $weights     = $parameters[2] ?? null;
        $aggregate   = $parameters[3] ?? null;

        $arguments = [
            $destination,
            count($keys),
            ...$keys,
        ];

        if (is_array($weights) && ($weights !== [])) {
            array_push($arguments, 'WEIGHTS', ...array_values($weights));
        }

        if (is_string($aggregate) && ($aggregate !== '')) {
            array_push($arguments, 'AGGREGATE', strtoupper($aggregate));
        }

        return $arguments;
    }

    /**
     * phpredis's order, and Laravel's override on top of it: options first as a list or as
     * bare flags, then score/member pairs, or a trailing `member => score` map.
     *
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function zAdd(array $parameters): array
    {
        $key = array_shift($parameters);

        if (is_array(end($parameters)) && !array_is_list(end($parameters))) {
            foreach (array_pop($parameters) as $member => $score) {
                $parameters[] = $score;
                $parameters[] = (string) $member;
            }
        }

        $arguments = [$key];

        while ($parameters !== []) {
            $first = $parameters[0];

            if (is_array($first)) {
                array_push($arguments, ...array_map(strtoupper(...), array_map(strval(...), $first)));

                array_shift($parameters);

                continue;
            }

            if (is_string($first) && in_array(strtoupper($first), ['NX', 'XX', 'CH', 'INCR', 'GT', 'LT'], true)) {
                $arguments[] = strtoupper($first);

                array_shift($parameters);

                continue;
            }

            break;
        }

        array_push($arguments, ...$parameters);

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function set(array $parameters): array
    {
        $arguments = [
            $parameters[0] ?? null,
            $parameters[1] ?? null,
        ];

        $options = $parameters[2] ?? null;

        if (is_int($options)) {
            array_push($arguments, 'EX', $options);

            return $arguments;
        }

        if (!is_array($options)) {
            // Predis spelling: the options follow as separate arguments.
            array_push($arguments, ...array_slice($parameters, 2));

            return $arguments;
        }

        foreach ($options as $option => $value) {
            if (is_int($option)) {
                $arguments[] = strtoupper((string) $value);

                continue;
            }

            array_push($arguments, strtoupper($option), $value);
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function lRem(array $parameters): array
    {
        return [
            $parameters[0] ?? null,
            $parameters[2] ?? 0,
            $parameters[1] ?? null,
        ];
    }

    /**
     * phpredis's `(script, arguments, numberOfKeys)`; anything else is taken as the command
     * spelled out already.
     *
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function evaluate(array $parameters): array
    {
        if (!is_array($parameters[1] ?? null)) {
            return CommandArguments::flatten(
                command: 'EVAL',
                parameters: $parameters,
            );
        }

        return [
            $parameters[0],
            (int) ($parameters[2] ?? 0),
            ...array_values($parameters[1]),
        ];
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function sort(array $parameters): array
    {
        $arguments = [$parameters[0] ?? null];

        $options = array_change_key_case((array) ($parameters[1] ?? []));

        if (isset($options['by'])) {
            array_push($arguments, 'BY', $options['by']);
        }

        array_push($arguments, ...self::limit($options));

        foreach ((array) ($options['get'] ?? []) as $pattern) {
            array_push($arguments, 'GET', $pattern);
        }

        if (isset($options['sort'])) {
            $arguments[] = strtoupper((string) $options['sort']);
        }

        if (!empty($options['alpha'])) {
            $arguments[] = 'ALPHA';
        }

        if (isset($options['store'])) {
            array_push($arguments, 'STORE', $options['store']);
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function xAdd(array $parameters): array
    {
        $arguments = [$parameters[0] ?? null];

        $maximumLength = (int) ($parameters[3] ?? 0);

        if ($maximumLength > 0) {
            $arguments[] = 'MAXLEN';

            if (!empty($parameters[4])) {
                $arguments[] = '~';
            }

            $arguments[] = $maximumLength;
        }

        $arguments[] = $parameters[1] ?? '*';

        foreach ((array) ($parameters[2] ?? []) as $field => $value) {
            array_push($arguments, (string) $field, $value);
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function xRead(array $parameters): array
    {
        $streams = (array) ($parameters[0] ?? []);
        $count   = (int) ($parameters[1] ?? -1);
        $blockMs = (int) ($parameters[2] ?? -1);

        $arguments = [];

        if ($count > 0) {
            array_push($arguments, 'COUNT', $count);
        }

        if ($blockMs >= 0) {
            array_push($arguments, 'BLOCK', $blockMs);
        }

        array_push($arguments, 'STREAMS', ...array_map(strval(...), array_keys($streams)), ...array_values($streams));

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function lPos(array $parameters): array
    {
        $arguments = [
            $parameters[0] ?? null,
            $parameters[1] ?? null,
        ];

        foreach (array_change_key_case((array) ($parameters[2] ?? [])) as $option => $value) {
            array_push($arguments, strtoupper((string) $option), $value);
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function getEx(array $parameters): array
    {
        $arguments = [$parameters[0] ?? null];

        foreach ((array) ($parameters[1] ?? []) as $option => $value) {
            if (is_int($option)) {
                $arguments[] = strtoupper((string) $value);

                continue;
            }

            array_push($arguments, strtoupper($option), $value);
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function copy(array $parameters): array
    {
        $arguments = [
            $parameters[0] ?? null,
            $parameters[1] ?? null,
        ];

        $options = array_change_key_case((array) ($parameters[2] ?? []));

        if (isset($options['db'])) {
            array_push($arguments, 'DB', $options['db']);
        }

        if (!empty($options['replace'])) {
            $arguments[] = 'REPLACE';
        }

        return $arguments;
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function flush(array $parameters): array
    {
        $async = $parameters[0] ?? null;

        if (($async === true) || (is_string($async) && (strcasecmp($async, 'ASYNC') === 0))) {
            return ['ASYNC'];
        }

        if (is_string($async) && (strcasecmp($async, 'SYNC') === 0)) {
            return ['SYNC'];
        }

        return [];
    }

    /**
     * @param list<mixed> $parameters
     *
     * @return list<mixed>
     */
    private static function head(array $parameters, int $count): array
    {
        return array_pad(array_slice($parameters, 0, $count), $count, null);
    }
}
