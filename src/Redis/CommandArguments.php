<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

use SConcur\Exceptions\Redis\InvalidRedisArgumentException;

/**
 * Lays the arguments of a call out as the flat list a Redis command is — for the commands
 * whose phpredis method takes them in order; PhpRedisArguments reads the rest.
 *
 * One level of array is spread in place, and how is decided by the command rather than by
 * the shape of the array. PHP cannot tell `['0' => 'a', '1' => 'b']` from `['a', 'b']` —
 * both have the integer keys 0 and 1 — so guessing from the shape would send `MSET a b`
 * for a map of two numeric keys and write `b` into the key `a`.
 *
 * - The commands that take field/value pairs (`MSET`, `MSETNX`, `HSET`, `HMSET`) spread
 *   every array as its keys followed by its values.
 * - Every other command takes a list, spread element by element. A map is refused there:
 *   which of its keys and values the command wants is not something to guess.
 *
 * Anything deeper than one level is passed on, and the feature refuses it together with
 * `bool` and `null`.
 */
class CommandArguments
{
    private const array PAIR_COMMANDS = [
        'MSET',
        'MSETNX',
        'HSET',
        'HMSET',
    ];

    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return list<mixed>
     */
    public static function flatten(string $command, array $parameters): array
    {
        $name = strtoupper($command);

        $arguments = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                $arguments[] = $parameter;

                continue;
            }

            if (in_array($name, self::PAIR_COMMANDS, true)) {
                foreach ($parameter as $key => $value) {
                    $arguments[] = (string) $key;
                    $arguments[] = $value;
                }

                continue;
            }

            if (!array_is_list($parameter)) {
                throw new InvalidRedisArgumentException(
                    message: sprintf(
                        '%s was given a map, and only MSET, MSETNX, HSET, HMSET, ZADD and the options of'
                        . ' the phpredis signatures read one;'
                        . ' pass the arguments as a list in the order the command takes them.',
                        $name,
                    ),
                );
            }

            array_push($arguments, ...$parameter);
        }

        return $arguments;
    }
}
