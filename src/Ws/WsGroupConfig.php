<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

/**
 * Reads the ws group out of the published master config.
 *
 * The group is found by what it runs rather than by its name, so renaming it does not
 * quietly leave a standalone server on the library's defaults — the same rule
 * HttpStartCommand follows. It lives in a class of its own because three callers need
 * the same lookup: the command building its argv, the command warning about the presence
 * store, and the provider choosing that store.
 */
class WsGroupConfig
{
    /**
     * The group's `server` block, which the master would otherwise have forwarded to the
     * worker's argv.
     *
     * @return array<string, mixed>
     */
    public static function server(string $commandName): array
    {
        return (array) (self::group($commandName)['server'] ?? []);
    }

    /** How many worker processes the group runs; one when it is not configured at all. */
    public static function workerCount(string $commandName): int
    {
        return (int) (self::group($commandName)['workerCount'] ?? 1);
    }

    /**
     * @return array<string, mixed>
     */
    private static function group(string $commandName): array
    {
        foreach ((array) config('sconcur.master.groups', []) as $group) {
            if (!is_array($group) || !in_array($commandName, (array) ($group['workerArgs'] ?? []), true)) {
                continue;
            }

            return $group;
        }

        return [];
    }
}
