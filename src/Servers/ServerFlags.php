<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

/**
 * A group's `server` block as the `--name=value` flags the library's fromArgs() reads:
 * HttpServer, WsServer and QueueConsumer each build themselves from such a list.
 */
class ServerFlags
{
    /**
     * Booleans as the literal 1/0, anything structured — the consumer's queue list — as
     * JSON.
     *
     * @param array<array-key, mixed> $server
     *
     * @return list<string>
     */
    public static function fromBlock(array $server): array
    {
        $flags = [];

        foreach ($server as $key => $value) {
            $flags[] = sprintf('--%s=%s', $key, static::value($value));
        }

        return $flags;
    }

    protected static function value(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
