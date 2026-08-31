<?php

declare(strict_types=1);

namespace SConcur\Laravel\Queue\Rabbitmq;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Failed\FailedJobProviderInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\Amqp\Delivery;
use Throwable;

/**
 * Runs a queue-consumer pool in the current process, the way HttpServerRunner runs an
 * HTTP server: the master spawns this as a group's worker, one process per slot, and
 * each process reads all of its queues at once with a coroutine per delivery.
 *
 * Illuminate\Queue\Worker::daemon() is deliberately not used. It is a strictly
 * sequential loop — getNextJob() -> pop() -> runJob() -> sleep() — one job at a time,
 * and its sleep() blocks the process, so a coroutine runtime would buy nothing.
 * process() is the half worth having: it raises the job events, honours maxTries and
 * backoff, and settles the job — releasing it or marking it failed. Writing failed_jobs
 * is not part of it; that lives in queue:work, and logFailedJobs() below puts it back. What it does not do
 * is what runJob() wraps around it: report the exception and then swallow it. Both are
 * this class's job, and it does them itself (see the handler).
 */
readonly class ConsumerRunner
{
    public function __construct(
        private QueueConsumer $consumer,
        private string $connectionName,
        private WorkerOptions $options,
    ) {
    }

    public function run(Application $app): int
    {
        /** @var QueueManager $manager */
        $manager = $app->make(QueueManager::class);

        /** @var Queue $queue */
        $queue = $manager->connection($this->connectionName);

        // The binding, not the class: Worker's constructor takes primitives Laravel
        // fills in itself (QueueServiceProvider::registerWorker), so autowiring it
        // would fail on an unresolvable dependency.
        /** @var Worker $worker */
        $worker = $app->make('queue.worker');

        /** @var ExceptionHandler $exceptions */
        $exceptions = $app->make(ExceptionHandler::class);

        $this->logFailedJobs($app);

        return $this->consumer->consume(
            connection: $queue->getConnection(),
            handler: function (Delivery $delivery) use ($queue, $worker, $exceptions): void {
                $job = new Job(
                    // The contract's Application is not the concrete Container the base
                    // job stores; the running one always is, and this is where it is said.
                    container: Container::getInstance(),
                    rabbitmq: $queue,
                    delivery: $delivery,
                    connectionName: $this->connectionName,
                    // A delivery does not carry the queue it came from, and the routing
                    // key is what stands in for it: publishing into the default exchange
                    // routes by queue name, which is what this driver does and what
                    // laravel-queue-rabbitmq does by default. The two agree, and a
                    // release() therefore goes back where the job came from.
                    queue: $delivery->routingKey,
                );

                try {
                    $worker->process($this->connectionName, $job, $this->options);
                } catch (FlowStoppedException $exception) {
                    // The runtime is unwinding this coroutine, and it knows what to do
                    // with that, so the exception goes straight back out. The two cases
                    // are settled differently on the way: a shutdown leaves the delivery
                    // untouched and the broker redelivers it once, while a handler that
                    // ran past its deadline has its message refused.
                    //
                    // The deadline is reported first. Nothing else would record it — the
                    // job never reached Laravel's failure path — so a job dropped for
                    // running long would otherwise leave no trace outside the runtime's
                    // own line.
                    if ($exception instanceof CoroutineTimeoutException) {
                        $exceptions->report($exception);
                    }

                    throw $exception;
                } catch (Throwable $exception) {
                    // Worker::process() does not swallow: handleJobException() ends in
                    // `throw $e`. What swallows is runJob(), the wrapper queue:work uses
                    // and this pool does not — and it is also where the exception gets
                    // reported. Both halves belong here, or a failing job leaves no trace
                    // anywhere but failed_jobs, and every ordinary failure escapes into
                    // the runtime, which counts it as a refused message.
                    //
                    // Only once the delivery is settled, though. If it is not, the job
                    // was not released and not failed — the republish itself threw, say —
                    // and swallowing would acknowledge a message nobody handled.
                    if (!$delivery->isSettled()) {
                        throw $exception;
                    }

                    $exceptions->report($exception);
                }
            },
        );
    }

    /**
     * Write failed jobs to the failed_jobs store.
     *
     * Worker::process() does not do this: it marks the job failed and raises JobFailed,
     * and the write itself lives in the queue:work command, which this pool replaces.
     * Without repeating it here a job that exhausted its attempts would vanish with no
     * record at all.
     */
    protected function logFailedJobs(Application $app): void
    {
        /** @var Dispatcher $events */
        $events = $app->make('events');

        $events->listen(JobFailed::class, static function (JobFailed $event) use ($app): void {
            /** @var FailedJobProviderInterface $failer */
            $failer = $app->make('queue.failer');

            $failer->log(
                $event->connectionName,
                $event->job->getQueue(),
                $event->job->getRawBody(),
                $event->exception,
            );
        });
    }
}
