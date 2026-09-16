<?php

declare(strict_types=1);

namespace SConcur\Laravel\Redis;

/**
 * Lays the arguments of a predis-style call out as the flat list a Redis command is.
 *
 * `Redis::mget(['a', 'b'])`, `Redis::del(['a', 'b'])` and `Redis::mset(['a' => 1])` are how
 * Laravel code passes several values, so one level of array is spread in place: a list
 * element by element, a map as its key followed by its value. Anything deeper is passed on
 * as it is, and the feature refuses it together with `bool` and `null` rather than guess a
 * spelling for it.
 */
class CommandArguments
{
    /**
     * @param array<array-key, mixed> $parameters
     *
     * @return list<mixed>
     */
    public static function flatten(array $parameters): array
    {
        $arguments = [];

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                $arguments[] = $parameter;

                continue;
            }

            if (array_is_list($parameter)) {
                array_push($arguments, ...$parameter);

                continue;
            }

            foreach ($parameter as $key => $value) {
                $arguments[] = (string) $key;
                $arguments[] = $value;
            }
        }

        return $arguments;
    }
}
