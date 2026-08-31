<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\Control\ControlCommandDto;

/**
 * Stops the pool, or one of its tasks, from any process that shares the application's
 * cache.
 *
 * Stopping one task lasts as long as the process does: the pool acts on commands posted
 * after it started, so a restart brings the task back. Turning a task off for good is a
 * matter of config, not of a command.
 */
class TasksStopCommand extends Command
{
    public const string NAME = 'sconcur:tasks:stop';

    protected $signature = self::NAME . '
        {--task= : Stop only this task; default stops the pool}';

    protected $description = 'Ask the SConcur task pool to stop';

    public function handle(ControlChannel $channel): int
    {
        $task = $this->option('task');

        $command = $channel->send(
            action: ControlActionEnum::Stop,
            target: is_string($task) && $task !== '' ? $task : ControlCommandDto::ALL,
        );

        $this->components->info('Sent: ' . $command->describe());

        return self::SUCCESS;
    }
}
