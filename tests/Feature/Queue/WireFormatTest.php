<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Queue;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Queue\Rabbitmq\Job;
use SConcur\Laravel\Queue\Rabbitmq\Queue;

/**
 * The format on the wire is not this package's own: body, message properties and the
 * attempt header are what vladimir-yuldashev/laravel-queue-rabbitmq writes, so a job
 * published by either driver is readable and runnable by the other.
 *
 * Three things hold that up and none of them can be changed one-sidedly. Each has a
 * test here, so a change to any of them fails rather than silently splitting the two
 * drivers apart.
 */
class WireFormatTest extends BaseQueueTestCase
{
    /** Publishing goes to the default exchange with the queue name as the routing key. */
    #[Test]
    public function aPublishedJobLandsInTheQueueItNames(): void
    {
        $this->queue()->pushRaw('{"job":"probe"}', $this->queueName);

        $job = $this->waitForJob();

        self::assertInstanceOf(Job::class, $job);
        self::assertSame('{"job":"probe"}', $job->getRawBody());

        $job->delete();
    }

    /**
     * The attempt counter lives in the `laravel.attempts` header, not in x-death.
     * Worker::process() builds maxTries and the failed_jobs write on it.
     */
    #[Test]
    public function theAttemptCounterTravelsInTheLaravelHeader(): void
    {
        $this->queue()->pushRaw('{"job":"probe"}', $this->queueName);

        $job = $this->queue()->pop($this->queueName);

        self::assertInstanceOf(Job::class, $job);
        self::assertSame(1, $job->attempts());

        // Released back with the counter bumped, the way a retry carries it.
        $job->release();

        $again = $this->waitForJob();

        self::assertInstanceOf(Job::class, $again);
        self::assertSame(2, $again->attempts());
        self::assertSame(Queue::ATTEMPTS_HEADER, 'laravel');

        $again->delete();
    }

    #[Test]
    public function aDeletedJobLeavesTheQueue(): void
    {
        $this->queue()->pushRaw('{"job":"probe"}', $this->queueName);

        $job = $this->waitForJob();

        self::assertInstanceOf(Job::class, $job);

        $job->delete();

        self::assertTrue($this->waitForSize(0), 'the queue did not empty out');
    }

    /**
     * The broker's message count is what `size()` reports, and it settles a moment after
     * the publish rather than with it — hence the wait. Asserting it the instant the
     * publish returns is a race, and one that passes often enough to look fine.
     */
    #[Test]
    public function theQueueReportsWhatIsWaitingInIt(): void
    {
        $this->queue()->pushRaw('{"job":"probe"}', $this->queueName);

        self::assertTrue($this->waitForSize(1), 'the publish never showed up in the count');

        $job = $this->waitForJob();

        self::assertInstanceOf(Job::class, $job);

        $job->delete();
    }

    #[Test]
    public function anEmptyQueuePopsNothing(): void
    {
        self::assertNull($this->queue()->pop($this->queueName));
    }

    /**
     * A release with a delay goes through a wait queue named after the exact delay,
     * which the publish that needs it creates. The broker sends the message back when
     * its TTL expires.
     */
    #[Test]
    public function aDelayedPublishComesBackThroughAWaitQueue(): void
    {
        $this->queue()->laterRaw(1, '{"job":"delayed"}', $this->queueName);

        // Not in the queue yet: it is sitting in <queue>.wait.1000 until the TTL runs out.
        self::assertSame(0, $this->queue()->size($this->queueName));

        $job = $this->waitForJob(seconds: 5);

        self::assertInstanceOf(Job::class, $job);
        self::assertSame('{"job":"delayed"}', $job->getRawBody());

        $job->delete();
    }

    protected function waitForSize(int $expected, int $seconds = 3): bool
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            if ($this->queue()->size($this->queueName) === $expected) {
                return true;
            }

            usleep(100_000);
        }

        return false;
    }

    protected function waitForJob(int $seconds = 3): ?Job
    {
        $deadline = microtime(true) + $seconds;

        while (microtime(true) < $deadline) {
            $job = $this->queue()->pop($this->queueName);

            if ($job !== null) {
                return $job;
            }

            usleep(100_000);
        }

        return null;
    }
}
