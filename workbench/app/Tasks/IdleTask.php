<?php

declare(strict_types=1);

namespace Workbench\App\Tasks;

use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;

/** Always finds nothing to do, so the `idle` pause is the one that follows it. */
class IdleTask implements TaskInterface
{
    public int $ticks = 0;

    public function name(): string
    {
        return 'idle';
    }

    public function tick(): TickResultEnum
    {
        $this->ticks++;

        return TickResultEnum::Idle;
    }
}
