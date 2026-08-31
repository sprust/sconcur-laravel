<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use SConcur\Laravel\Servers\MasterRunner;

/**
 * Trigger a rolling restart of the master's workers.
 *
 * The master re-reads the config from disk when it rolls, so this writes the
 * current Laravel config out first and hands over that path. Without --group
 * every pool rolls; with it, only the named one.
 */
class MasterReloadCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:servers:master:reload
        {--group= : Reload only this worker group (default: all of them)}';

    protected $description = 'Reload the SConcur master supervisor';

    public function handle(): int
    {
        return new MasterRunner()->reload(
            config: $this->masterConfig(),
            configPath: $this->masterConfigPath(),
            group: $this->groupOption(),
        );
    }
}
