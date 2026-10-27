<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Tasks\TaskPool;

/**
 * Runs the task pool in the foreground, outside the master: one process, a coroutine per
 * task, supervised from outside by whatever started it. Under the master a worker of the
 * `tasks` group runs TaskPool itself, without this command.
 */
class TasksStartCommand extends Command
{
    public const string NAME = 'sconcur:tasks:start';

    protected $signature = self::NAME . '
        {--only=* : Run only these tasks, by name; repeatable. Default is every configured task}';

    protected $description = 'Run the SConcur periodic task pool in the foreground';

    public function handle(TaskPool $pool): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only'), is_string(...)));

        return $pool->run($only);
    }
}
