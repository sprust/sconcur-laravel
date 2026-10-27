<?php

declare(strict_types=1);

namespace Workbench\App\Listeners;

use SConcur\Laravel\Servers\Events\WorkerForked;

/**
 * Writes down every worker it hears from, one line each, to a file: the event is raised in
 * a forked worker of a master running in another process, so memory is not where a test
 * could find it.
 */
class RecordWorkerForked
{
    public static function path(): string
    {
        return storage_path('sconcur/worker-forked.log');
    }

    public function handle(WorkerForked $workerForked): void
    {
        $workerContext = $workerForked->workerContext;

        file_put_contents(
            self::path(),
            sprintf(
                '%s#%d pid=%d master=%d env=%s' . PHP_EOL,
                $workerContext->group,
                $workerContext->slot,
                getmypid(),
                $workerContext->masterPid,
                // What the group's `env` put into the worker before the event was raised.
                (string) getenv('WORKBENCH_WORKER_ENV'),
            ),
            FILE_APPEND | LOCK_EX,
        );
    }
}
