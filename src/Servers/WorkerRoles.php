<?php

declare(strict_types=1);

namespace SConcur\Laravel\Servers;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Laravel\Http\HttpServerRunner;
use SConcur\Laravel\Queue\Rabbitmq\ConsumerRunner;
use SConcur\Laravel\Servers\Events\WorkerForked;
use SConcur\Laravel\Tasks\TaskPool;
use SConcur\Laravel\Ws\WsServerRunner;
use SConcur\Scheduler\Scheduler;
use SConcur\Worker\WorkerContext;

/**
 * What a worker of each group runs: the closure the master's forked worker calls with the
 * application already in memory. It raises WorkerForked, so the application can do its
 * own per-worker setup, then builds the runner of the group's role and runs it; the
 * worker leaves when it returns.
 *
 * The runners are built inside the closure, not before it: the master's process must not
 * start the extension's runtime, and a server or a consumer built there would be one
 * object for every worker rather than one each.
 */
readonly class WorkerRoles
{
    public function __construct(
        protected Application $app,
    ) {
    }

    /**
     * @param array<string, mixed> $group the group's entry of config('sconcur.master.groups')
     *
     * @return Closure(WorkerContext): void
     */
    public function for(WorkerRoleEnum $role, array $group): Closure
    {
        $app    = $this->app;
        $runner = $this->runner(
            role: $role,
            group: $group,
        );

        return static function (WorkerContext $context) use ($app, $runner): void {
            /** @var Dispatcher $dispatcher */
            $dispatcher = $app->make(Dispatcher::class);

            $dispatcher->dispatch(new WorkerForked($context));

            $runner($context);
        };
    }

    /**
     * @param array<string, mixed> $group
     *
     * @return Closure(WorkerContext): void
     */
    protected function runner(WorkerRoleEnum $role, array $group): Closure
    {
        $app        = $this->app;
        $serverArgs = ServerFlags::fromBlock((array) ($group['server'] ?? []));

        return match ($role) {
            WorkerRoleEnum::Http => static function () use ($app, $serverArgs): void {
                new HttpServerRunner(
                    serverArgs: $serverArgs,
                )->run($app);
            },
            WorkerRoleEnum::Rabbitmq => static function () use ($app, $serverArgs): void {
                // Said before the consumer opens, not by its first wait: the broker refuses
                // a queue nobody declared within a millisecond, and every slot failing
                // before it is up would stop the master and every other group with it. As a
                // worker that came up, it is restarted with a growing backoff instead.
                Scheduler::get()->markReady();

                ConsumerRunner::configured(
                    consumer: QueueConsumer::fromArgs($serverArgs),
                )->run($app);
            },
            WorkerRoleEnum::Ws => static function () use ($app, $serverArgs): void {
                new WsServerRunner(
                    serverArgs: $serverArgs,
                )->run($app);
            },
            WorkerRoleEnum::Tasks => static function (WorkerContext $context) use ($app): void {
                /** @var TaskPool $taskPool */
                $taskPool = $app->make(TaskPool::class);

                $exitCode = $taskPool->run(masterPid: $context->masterPid);

                // A role that returns is a clean exit. The pool's other answers — a fresh
                // process wanted (TaskPool::EXIT_RESTART), a lock it could not take — are
                // what the group's on-failure policy reads, so they leave as the exit code.
                if ($exitCode !== 0) {
                    exit($exitCode);
                }
            },
        };
    }
}
