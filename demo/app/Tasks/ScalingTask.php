<?php

declare(strict_types=1);

namespace Demo\App\Tasks;

use Demo\App\Support\ScalingSettings;
use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;
use SConcur\Worker\MasterControl;
use SConcur\Worker\WorkerMaster;

/**
 * Applies the worker and coroutine counts the demo page asks for, by restarting the master.
 *
 * A master holds the config it was built from, and there is no reload: new numbers are a
 * new master. This asks the running one to stop and does not wait — supervisor starts a
 * fresh master, which reads the stored numbers while it builds its config. Not waiting is
 * the point: this pool is one of the master's own workers, and the master drains it among
 * the others.
 *
 * It runs here rather than in the request that asked, because that request is served by
 * an HTTP worker of the same master and would be stopping it from inside a request the
 * master is draining.
 */
class ScalingTask implements TaskInterface
{
    public function name(): string
    {
        return 'scaling';
    }

    public function tick(): TickResultEnum
    {
        if (ScalingSettings::takeRestartRequest() === []) {
            return TickResultEnum::Idle;
        }

        $masterControl = new MasterControl(
            runtimeDir: (string) config('sconcur.master.runtimeDir'),
            name: (string) config('sconcur.master.name', WorkerMaster::DEFAULT_NAME),
        );

        // A zero deadline: remove the state file the master watches, and return.
        $masterControl->stop(timeoutMs: 0);

        return TickResultEnum::Worked;
    }
}
