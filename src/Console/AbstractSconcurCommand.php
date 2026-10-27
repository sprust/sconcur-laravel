<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use RuntimeException;
use SConcur\Worker\MasterControl;
use SConcur\Worker\WorkerMaster;

/**
 * Shared base for the SConcur master commands.
 *
 * The master configuration is taken from config('sconcur.master'). Start builds the
 * master out of it in this process; stop and status reach a running one through
 * MasterControl, by the same runtime directory and name.
 */
abstract class AbstractSconcurCommand extends Command
{
    /**
     * The master config as config('sconcur.master') holds it.
     *
     * @return array<string, mixed>
     */
    protected function masterConfigArray(): array
    {
        $config = (array) config('sconcur.master', []);

        if ($config === []) {
            // The package does not merge its config, so an unpublished one is empty
            // rather than defaulted. Saying that beats a "needs at least one group" from
            // the library, which reads like a broken config rather than a missing one.
            throw new RuntimeException(
                'sconcur: config("sconcur.master") is empty — publish the config with'
                . ' `php artisan vendor:publish --tag=sconcur-laravel`',
            );
        }

        return $config;
    }

    /** The running master as another process sees it, by its runtime directory and name. */
    protected function masterControl(): MasterControl
    {
        $config = $this->masterConfigArray();

        return new MasterControl(
            runtimeDir: (string) ($config['runtimeDir'] ?? ''),
            name: (string) ($config['name'] ?? WorkerMaster::DEFAULT_NAME),
        );
    }

    /**
     * The --group option: a group name, or '' for every group. Console options are typed
     * array|bool|string|null, and only a string names a group.
     */
    protected function groupOption(): string
    {
        $group = $this->option('group');

        return is_string($group) ? $group : '';
    }
}
