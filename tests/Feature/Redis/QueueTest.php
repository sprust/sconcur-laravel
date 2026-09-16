<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Queue;
use SConcur\Laravel\Redis\Connection;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Jobs\RedisQueueProbeJob;

/**
 * Laravel's own `redis` queue driver on the sconcur client, unchanged: RedisQueue runs on
 * Lua scripts, BLPOP and a few plain commands, and all of them go through the facade.
 */
class QueueTest extends BaseRedisTestCase
{
    private const string CONNECTION = 'redis_sconcur';

    private const string QUEUE = 'probe';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('queue.connections.' . self::CONNECTION, [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => self::QUEUE,
            'retry_after' => 60,
            'block_for'   => 1,
        ]);
    }

    #[Test]
    public function theDriverRunsOnTheSconcurConnection(): void
    {
        $queue = $this->queue();

        self::assertInstanceOf(RedisQueue::class, $queue);
        self::assertInstanceOf(Connection::class, $queue->getConnection());
    }

    #[Test]
    public function pushedDelayedBulkAndRetriedJobsAllRun(): void
    {
        $queue = $this->queue();

        $queue->push(new RedisQueueProbeJob('plain'));
        $queue->later(1, new RedisQueueProbeJob('delayed'));
        $queue->push(new RedisQueueProbeJob('retried', failOnce: true));
        $queue->bulk([
            new RedisQueueProbeJob('bulk-first'),
            new RedisQueueProbeJob('bulk-second'),
        ]);

        self::assertSame(5, $queue->size());
        self::assertSame(1, $queue->delayedSize());

        $worker  = $this->getApp()->make('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 2, timeout: 0);

        assert($worker instanceof Worker);

        for ($attempt = 0; $attempt < 10; ++$attempt) {
            $worker->runNextJob(self::CONNECTION, self::QUEUE, $options);

            if (count($this->done()) === 5) {
                break;
            }
        }

        $done = $this->done();

        sort($done);

        self::assertSame(['bulk-first#1', 'bulk-second#1', 'delayed#1', 'plain#1', 'retried#2'], $done);
        self::assertSame(0, $queue->size());
    }

    #[Test]
    public function anEmptyQueueWaitsForBlockFor(): void
    {
        $startedAt = microtime(true);

        self::assertNull($this->queue()->pop(self::QUEUE));
        self::assertGreaterThanOrEqual(0.9, microtime(true) - $startedAt);
    }

    private function queue(): RedisQueue
    {
        $queue = Queue::connection(self::CONNECTION);

        assert($queue instanceof RedisQueue);

        return $queue;
    }

    /**
     * @return list<string>
     */
    private function done(): array
    {
        return array_values(array_map(
            strval(...),
            (array) $this->redis()->command('lrange', [RedisQueueProbeJob::DONE_KEY, 0, -1]),
        ));
    }
}
