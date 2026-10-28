<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

/**
 * Reads a group out of the published master config by the role it runs.
 *
 * The group is found by its role rather than by its name, so renaming it does not quietly
 * leave a standalone server on the library's defaults. Several callers need the same
 * lookup: the start commands building their flags for a run with no master, the ws runner
 * warning about the presence store, and the provider choosing that store.
 */
class MasterGroups
{
    /**
     * The group's `server` block: the settings of the server or consumer its workers build.
     *
     * @return array<string, mixed>
     */
    public static function server(WorkerRoleEnum $role): array
    {
        return (array) (self::group($role)['server'] ?? []);
    }

    /** How many worker processes the group runs; one when it is not configured at all. */
    public static function workerCount(WorkerRoleEnum $role): int
    {
        return (int) (self::group($role)['workerCount'] ?? 1);
    }

    /**
     * @return array<string, mixed>
     */
    public static function group(WorkerRoleEnum $role): array
    {
        foreach ((array) config('sconcur.master.groups', []) as $group) {
            if (!is_array($group) || ($group['role'] ?? null) !== $role->value) {
                continue;
            }

            return $group;
        }

        return [];
    }
}
