<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use SConcur\Laravel\Servers\MasterRunner;

/**
 * Stop the running SConcur master supervisor.
 */
class MasterStopCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:servers:master:stop';

    protected $description = 'Stop the SConcur master supervisor';

    public function handle(): int
    {
        return new MasterRunner()->stop($this->masterConfig());
    }
}
