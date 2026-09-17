<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use Illuminate\Queue\RedisQueue;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Scheduler\Scheduler;
use SConcur\WaitGroup;
use Workbench\App\Jobs\RedisQueueProbeJob;

/**
 * The connection's `prefix` on the paths PhpRedisParityTest does not take: the paths that
 * stay unprefixed, a subscription, the limiters and the framework's queue.
 */
class PrefixTest extends BaseRedisTestCase
{
    private const string PREFIX = 'app:';

    protected function setUp(): void
    {
        parent::setUp();

        // RedisManager keeps the section it was built with, so the manager is built again.
        config()->set('database.redis.default.prefix', self::PREFIX);

        $this->getApp()->forgetInstance('redis');

        Redis::clearResolvedInstance('redis');
    }

    #[Test]
    public function aKeyGetsThePrefixAndARawCommandDoesNot(): void
    {
        $redis = $this->redis();

        $redis->command('set', ['typed', 'v']);
        $redis->executeRaw(['SET', 'raw', 'v']);
        $redis->client()->set('feature', 'v');

        self::assertSame(['app:typed', 'feature', 'raw'], $this->keys());
        self::assertSame('v', $redis->command('get', ['typed']));
    }

    /** phpredis subscribes to the prefixed channel and hands the callback the name the server sends. */
    #[Test]
    public function aSubscriptionIsOnThePrefixedChannel(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                Scheduler::get()->spawn(
                    callback: function (): void {
                        for ($attempt = 0; $attempt < 200; ++$attempt) {
                            if ($this->redis()->client()->command('PUBLISH', ['app:news', 'first']) > 0) {
                                return;
                            }

                            Sleeper::usleep(10_000);
                        }
                    },
                );

                try {
                    $this->redis()->subscribe(
                        ['news'],
                        static function (string $payload, string $channel) use (&$received): void {
                            $received[] = [$channel, $payload];

                            throw new RuntimeException('enough');
                        },
                    );
                } catch (RuntimeException $exception) {
                    if ($exception->getMessage() !== 'enough') {
                        throw $exception;
                    }
                }
            },
            timeoutMs: self::COROUTINE_TIMEOUT_MS,
        );

        $waitGroup->waitAll();

        self::assertSame([['app:news', 'first']], $received);
    }

    #[Test]
    public function theLimitersKeepTheirKeysUnderThePrefix(): void
    {
        $this->redis()->throttle('throttled')->allow(1)->every(60)->then(static fn(): bool => true);
        $this->redis()->funnel('funneled')->limit(1)->then(static fn(): bool => true);

        foreach ($this->keys() as $key) {
            self::assertStringStartsWith(self::PREFIX, $key);
        }

        self::assertContains('app:throttled', $this->keys());
    }

    #[Test]
    public function theQueueRunsUnderThePrefix(): void
    {
        config()->set('queue.connections.redis_prefixed', [
            'driver'      => 'redis',
            'connection'  => 'default',
            'queue'       => 'prefixed',
            'retry_after' => 60,
            'block_for'   => 1,
        ]);

        $queue = Queue::connection('redis_prefixed');

        assert($queue instanceof RedisQueue);

        $queue->push(new RedisQueueProbeJob('plain'));
        $queue->later(60, new RedisQueueProbeJob('delayed'));

        self::assertSame(['app:queues:prefixed', 'app:queues:prefixed:delayed', 'app:queues:prefixed:notify'], $this->keys());
        self::assertSame(2, $queue->size());

        $worker = $this->getApp()->make('queue.worker');

        assert($worker instanceof Worker);

        $worker->runNextJob('redis_prefixed', 'prefixed', new WorkerOptions(sleep: 0, maxTries: 1, timeout: 0));

        self::assertSame(1, $queue->size());
    }

    /**
     * @return list<string>
     */
    private function keys(): array
    {
        $keys = (array) $this->redis()->client()->command('KEYS', ['*']);

        sort($keys);

        return array_map(strval(...), $keys);
    }
}
