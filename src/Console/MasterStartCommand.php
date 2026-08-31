<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use SConcur\Laravel\Servers\MasterRunner;

/**
 * Start the SConcur master supervisor in the foreground. Builds the master from
 * config('sconcur.master') and runs it (it spawns and supervises workers).
 */
class MasterStartCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:servers:master:start';

    protected $description = 'Start the SConcur master supervisor';

    public function handle(): int
    {
        return new MasterRunner()->start($this->masterConfig());
    }
}
