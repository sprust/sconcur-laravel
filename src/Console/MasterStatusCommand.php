<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

/**
 * Report status of the SConcur master supervisor. Without --group it reports every
 * pool the master supervises; with it, only the named one.
 *
 * Whether the master runs is whether a process holds its lock, not a pid in the state
 * file, so a stale file or a reused pid cannot fake it.
 */
class MasterStatusCommand extends AbstractSconcurCommand
{
    /** The exit code of a master that does not run, the one `status` of an init script uses. */
    public const int EXIT_NOT_RUNNING = 3;

    protected $signature = 'sconcur:servers:master:status
        {--group= : Report only this worker group (default: all of them)}';

    protected $description = 'Show the SConcur master supervisor status';

    public function handle(): int
    {
        $masterControl = $this->masterControl();

        if (!$masterControl->isRunning()) {
            $this->line('stopped');

            return self::EXIT_NOT_RUNNING;
        }

        $state = $masterControl->state();

        if ($state === null) {
            $this->line('running');

            return self::SUCCESS;
        }

        $this->line(
            sprintf(
                'running: pid=%d workers=%d groups=%d',
                $state->pid,
                $state->workerCount,
                count($state->groups),
            ),
        );

        $group = $this->groupOption();

        foreach ($state->groups as $reported) {
            if ($group !== '' && $reported->name !== $group) {
                continue;
            }

            $this->line(sprintf('  %s: workers=%d', $reported->name, $reported->workerCount));
        }

        return self::SUCCESS;
    }
}
