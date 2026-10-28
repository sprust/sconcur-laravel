<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

/**
 * Stop the running SConcur master supervisor: removes its state file, which the master
 * answers by draining its workers and leaving, and waits for it to be gone.
 *
 * Under supervisor this is also how new code is taken up: the master holds the application
 * it loaded, so a fresh master is the only way to a fresh one, and supervisor starts it.
 */
class MasterStopCommand extends AbstractSconcurCommand
{
    /**
     * How much longer than the slowest group's drain the command waits: the master kills
     * what outlives shutdownTimeoutMs, then clears its state and releases its lock.
     */
    protected const int STOP_MARGIN_MS = 5_000;

    protected $signature = 'sconcur:servers:master:stop';

    protected $description = 'Stop the SConcur master supervisor';

    public function handle(): int
    {
        $masterControl = $this->masterControl();

        if (!$masterControl->isRunning()) {
            $this->line('not running');

            return self::SUCCESS;
        }

        if (!$masterControl->stop($this->stopTimeoutMs())) {
            $this->components->error('stop timeout; master still running');

            return self::FAILURE;
        }

        $this->line('stopped');

        return self::SUCCESS;
    }

    /** The longest drain any group is given, plus the margin. */
    protected function stopTimeoutMs(): int
    {
        $config = $this->masterConfigArray();

        $shutdownTimeoutMs = (int) ($config['shutdownTimeoutMs'] ?? 10_000);

        foreach ((array) ($config['groups'] ?? []) as $group) {
            $shutdownTimeoutMs = max($shutdownTimeoutMs, (int) (((array) $group)['shutdownTimeoutMs'] ?? 0));
        }

        return $shutdownTimeoutMs + self::STOP_MARGIN_MS;
    }
}
