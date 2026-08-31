<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use SConcur\Laravel\Servers\MasterRunner;

/**
 * Report status of the SConcur master supervisor. Without --group it reports every
 * pool the master supervises; with it, only the named one.
 */
class MasterStatusCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:servers:master:status
        {--group= : Report only this worker group (default: all of them)}';

    protected $description = 'Show the SConcur master supervisor status';

    public function handle(): int
    {
        return new MasterRunner()->status(
            config: $this->masterConfig(),
            group: $this->groupOption(),
        );
    }
}
