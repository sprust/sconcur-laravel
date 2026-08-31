<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Tasks\TaskPool;

/**
 * Runs the task pool in the foreground: one process, a coroutine per task, supervised
 * from outside by whatever started it.
 *
 * `--masterPid` is declared because the master appends it to every worker it spawns and
 * Symfony Console rejects a flag its command does not declare. The pool uses it the way
 * the library's servers do — to notice that the master is gone and stand down.
 */
class TasksStartCommand extends Command
{
    public const string NAME = 'sconcur:tasks:start';

    protected $signature = self::NAME . '
        {--only=* : Run only these tasks, by name; repeatable. Default is every configured task}
        {--masterPid= : Master pid, injected by the supervisor for orphan self-termination}';

    protected $description = 'Run the SConcur periodic task pool in the foreground';

    public function handle(TaskPool $pool): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('only'), is_string(...)));

        return $pool->run($only, (int) $this->option('masterPid'));
    }
}
