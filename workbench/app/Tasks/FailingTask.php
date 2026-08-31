<?php

declare(strict_types=1);

namespace Workbench\App\Tasks;

use RuntimeException;
use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;

/** Throws on every tick, so the pool's `backoff` path is reachable from a test. */
class FailingTask implements TaskInterface
{
    public int $ticks = 0;

    public function name(): string
    {
        return 'failing';
    }

    public function tick(): TickResultEnum
    {
        $this->ticks++;

        throw new RuntimeException('the failing task always throws');
    }
}
