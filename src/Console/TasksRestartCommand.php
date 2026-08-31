<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\Control\ControlCommandDto;

/**
 * Rebuilds a task's instance without touching the rest of the pool: it keeps ticking,
 * with whatever state it had accumulated dropped. The process is not restarted.
 */
class TasksRestartCommand extends Command
{
    public const string NAME = 'sconcur:tasks:restart';

    protected $signature = self::NAME . '
        {--task= : Restart only this task; default restarts every task of the pool}';

    protected $description = 'Ask the SConcur task pool to rebuild a task';

    public function handle(ControlChannel $channel): int
    {
        $task = $this->option('task');

        $command = $channel->send(
            action: ControlActionEnum::Restart,
            target: is_string($task) && $task !== '' ? $task : ControlCommandDto::ALL,
        );

        $this->components->info('Sent: ' . $command->describe());

        return self::SUCCESS;
    }
}
