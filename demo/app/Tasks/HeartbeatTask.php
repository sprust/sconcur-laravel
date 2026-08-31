<?php

declare(strict_types=1);

namespace Demo\App\Tasks;

use Demo\App\Models\Heartbeat;
use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;

/**
 * The demo's periodic task: bumps one row so the page can show that the third runtime
 * is alive, and which process it is running in.
 *
 * It is an ordinary injectable service — the pool resolves the class named in
 * config('sconcur.tasks.list') out of the container and knows nothing else about it.
 *
 * Always `Worked`, never `Idle`: there is always something to write. A real task
 * returns `Idle` when it found no work, which is what makes the pool wait the longer
 * `idle` pause instead of polling at the `busy` one.
 */
class HeartbeatTask implements TaskInterface
{
    public function name(): string
    {
        return 'heartbeat';
    }

    public function tick(): TickResultEnum
    {
        $heartbeat = Heartbeat::query()->firstOrNew(['name' => $this->name()]);

        $heartbeat->ticks      = $heartbeat->ticks + 1;
        $heartbeat->worker_pid = getmypid() ?: 0;

        $heartbeat->save();

        return TickResultEnum::Worked;
    }
}
