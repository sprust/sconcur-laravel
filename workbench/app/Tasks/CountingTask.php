<?php

declare(strict_types=1);

namespace Workbench\App\Tasks;

use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;

/**
 * Counts its ticks in a property, so a test can assert the pool called it and how often.
 * Resolved out of the container as a singleton by the registry, which is what makes the
 * counter readable from the test.
 */
class CountingTask implements TaskInterface
{
    public int $ticks = 0;

    public function name(): string
    {
        return 'counting';
    }

    public function tick(): TickResultEnum
    {
        $this->ticks++;

        return TickResultEnum::Worked;
    }
}
