<?php

declare(strict_types=1);

namespace SConcur\Laravel\Queue\Rabbitmq;

use Illuminate\Container\Container;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job as BaseJob;
use SConcur\Exceptions\CoroutineTimeoutException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Features\Amqp\Delivery;
use Throwable;

/**
 * One AMQP delivery as a Laravel job, which is what Illuminate\Queue\Worker::process()
 * takes. It is the whole adapter: the worker already raises the job events, honours
 * maxTries and backoff and writes failed_jobs, and none of that is reimplemented here.
 *
 * The attempt counter is read from, and written back to, the `laravel.attempts` header
 * — the same place vladimir-yuldashev/laravel-queue-rabbitmq keeps it. That is what
 * makes a job published by that package retry correctly here, and vice versa; x-death
 * is not it, and counting attempts anywhere else would silently break maxTries.
 */
class Job extends BaseJob implements JobContract
{
    /**
     * The runtime's own unwind, when one reached this job.
     *
     * Illuminate\Queue\Worker::process() routes any Throwable through
     * handleJobException(), so a FlowStoppedException or a CoroutineTimeoutException —
     * the runtime telling this coroutine to stop — is treated there like any other
     * failure: failed_jobs is written and the job republished, both on a coroutine that
     * has no flow left to await on. Each would throw a second exception that replaced the
     * first, and the consumer would then read a deliberate unwind as a refusal.
     *
     * So the unwind is remembered here and every way of settling the job is short-
     * circuited while it stands. What happens to the message is then the runtime's to
     * decide, and it decides differently for the two: a shutdown leaves the delivery
     * unsettled, so the broker redelivers it once, while a handler past its deadline has
     * its message refused. ConsumerRunner reports the second, since nothing else records
     * it.
     */
    protected ?Throwable $unwind = null;

    public function __construct(
        Container $container,
        protected Queue $rabbitmq,
        protected Delivery $delivery,
        string $connectionName,
        string $queue,
    ) {
        $this->container      = $container;
        $this->connectionName = $connectionName;
        $this->queue          = $queue;
    }

    /** {@inheritDoc} */
    public function fire()
    {
        try {
            parent::fire();
        } catch (FlowStoppedException | CoroutineTimeoutException $exception) {
            $this->unwind = $exception;

            throw $exception;
        }
    }

    /** The unwind that reached this job, for the caller to rethrow. */
    public function unwind(): ?Throwable
    {
        return $this->unwind;
    }

    public function getRawBody(): string
    {
        return $this->delivery->body;
    }

    public function getJobId(): ?string
    {
        $id = $this->payload()['id'] ?? null;

        return is_string($id) ? $id : null;
    }

    public function attempts(): int
    {
        return $this->attemptsBefore() + 1;
    }

    /**
     * Acknowledge: the broker may forget the message.
     *
     * The delivery refuses to be settled twice, so once this has run the consumer
     * runtime leaves the message alone — it does not override a handler that settled
     * its own delivery.
     */
    public function delete(): void
    {
        if ($this->unwind !== null) {
            return;
        }

        parent::delete();

        if (!$this->delivery->isSettled()) {
            $this->delivery->ack();
        }
    }

    /**
     * Put the job back, with its attempt counter advanced.
     *
     * Republishing rather than nack(requeue: true), because the counter has to move: a
     * requeued delivery comes back with the header it arrived with, and a job that
     * always fails would then retry forever with attempts stuck at one. The seam this
     * creates — publish, then ack — is the ordinary at-least-once one: a worker dying
     * between them leaves the copy and the original, which is why handlers must be
     * idempotent.
     */
    public function release($delay = 0): void
    {
        if ($this->unwind !== null) {
            return;
        }

        // Republish first, mark released after. The other way round, a publish that threw
        // — an unroutable wait queue, say — would leave the job both unsettled and
        // ineligible for Worker's own release in its finally, and the message would be
        // dead-lettered instead of retried.

        $this->rabbitmq->laterRaw(
            delay: $delay,
            payload: $this->getRawBody(),
            queue: $this->queue,
            attempts: $this->attempts(),
            // The channel the consumer runtime lent this handler, which no other handler
            // holds. Without it the republish would go out on a channel shared with every
            // coroutine in the process, and a delayed publish waits for a confirmation —
            // which is channel-wide, so neighbours would collect each other's answers and
            // a released job could be reported as delivered while the broker dropped it.
            // Outside a handler this is the channel the basic.get arrived on; null once
            // the loan is over, and then laterRaw leases one.
            channel: $this->delivery->channel(),
        );

        parent::release($delay);

        if (!$this->delivery->isSettled()) {
            $this->delivery->ack();
        }
    }

    /**
     * {@inheritDoc}
     *
     * Short-circuited during an unwind for the same reason as delete() and release():
     * failing writes failed_jobs and acknowledges, and neither can be done on a coroutine
     * the runtime has already let go of.
     */
    public function fail($e = null)
    {
        if ($this->unwind !== null) {
            return;
        }

        parent::fail($e);
    }

    public function getDelivery(): Delivery
    {
        return $this->delivery;
    }

    /**
     * What the header says before this attempt is counted. A message that never went
     * round carries no header at all, which is zero.
     */
    protected function attemptsBefore(): int
    {
        $header = $this->delivery->header(Queue::ATTEMPTS_HEADER);

        if (!is_array($header)) {
            return 0;
        }

        return (int) ($header['attempts'] ?? 0);
    }
}
