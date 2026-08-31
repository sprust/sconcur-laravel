<?php

declare(strict_types=1);

namespace Demo\App\Tasks;

use Demo\App\Support\ScalingSettings;
use Illuminate\Support\Facades\Artisan;
use SConcur\Laravel\Tasks\TaskInterface;
use SConcur\Laravel\Tasks\TickResultEnum;
use Throwable;

/**
 * Applies the worker and coroutine counts the demo page asks for.
 *
 * It runs here rather than in the request that asked, or in a queued job, because both
 * of those live in a pool the roll would take down. Reload waits for the roll to finish;
 * an HTTP worker calling it would be waiting for the master while the master waits for
 * it to drain, and a consumer calling it would be rolled out from under its own message.
 * The task pool is the one pool never rolled by this — its group is not among the ones
 * the page can change — so it is the one process that can watch a roll from outside.
 *
 * Reload is driven through the artisan command rather than through MasterRunner: the
 * command is what writes the config to disk for the master to re-read, and going around
 * it would mean copying that.
 */
class ScalingTask implements TaskInterface
{
    public function name(): string
    {
        return 'scaling';
    }

    public function tick(): TickResultEnum
    {
        $groups = ScalingSettings::takeReloadRequest();

        if ($groups === []) {
            return TickResultEnum::Idle;
        }

        // The pool is a long-lived process: its config('sconcur') was built when it
        // started, from the numbers in force then. Reload hands the master whatever this
        // process has, so without re-reading the file first it would faithfully roll the
        // groups onto the old values and report success.
        config()->set('sconcur', require config_path('sconcur.php'));

        foreach ($groups as $group) {
            try {
                Artisan::call('sconcur:servers:master:reload', ['--group' => $group]);
            } catch (Throwable $exception) {
                // One group failing to roll must not cost the others theirs, and the
                // request is already taken — reporting it and carrying on beats a tick
                // that throws and replays nothing.
                report($exception);
            }
        }

        return TickResultEnum::Worked;
    }
}
