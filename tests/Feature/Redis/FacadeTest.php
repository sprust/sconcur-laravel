<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\Events\CommandFailed;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SConcur\Exceptions\Redis\InvalidRedisArgumentException;
use SConcur\Exceptions\Redis\NestedPipelineExecutionException;
use SConcur\Exceptions\Redis\RedisCommandException;
use SConcur\Features\Redis\Connection as RedisClient;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Redis\CommandBatch;
use SConcur\Laravel\Redis\Connection;
use SConcur\Laravel\Redis\Exceptions\RedisClusterNotSupportedException;
use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisCallException;
use SConcur\Laravel\Redis\Limiters\ConcurrencyLimiterBuilder;
use SConcur\Laravel\Redis\Limiters\DurationLimiterBuilder;
use SConcur\Scheduler\Scheduler;
use SConcur\WaitGroup;

/**
 * `Redis::` on the `sconcur` client, against the live server.
 *
 * Magic calls go through callMagic() with the method name in a variable. That is still
 * __call, which is what is under test; PHPStan would otherwise check them against the
 * phpredis signatures the framework's connection declares as its mixin.
 */
class FacadeTest extends BaseRedisTestCase
{
    #[Test]
    public function theFacadeIsOnTheSconcurClient(): void
    {
        self::assertInstanceOf(Connection::class, Redis::connection());
        self::assertInstanceOf(RedisClient::class, $this->redis()->client());
    }

    /** The spelling RedisLock uses, answered the way PhpRedisConnection answers it. */
    #[Test]
    public function aMagicCallAnswersLikePhpRedis(): void
    {
        $redis = $this->redis();

        self::assertTrue($this->callMagic($redis, 'set', 'greeting', 'hello', 'EX', 60, 'NX'));
        self::assertFalse($this->callMagic($redis, 'set', 'greeting', 'again', 'EX', 60, 'NX'));
        self::assertSame('hello', $this->callMagic($redis, 'get', 'greeting'));
        self::assertSame(1, $this->callMagic($redis, 'del', 'greeting'));
        self::assertNull($this->callMagic($redis, 'get', 'greeting'));
    }

    #[Test]
    public function oneLevelOfArrayIsSpread(): void
    {
        $redis = $this->redis();

        self::assertTrue($this->callMagic($redis, 'mset', ['first' => 'one', 'second' => 'two']));
        self::assertSame(['one', 'two', null], $this->callMagic($redis, 'mget', ['first', 'second', 'third']));
        self::assertSame(2, $this->callMagic($redis, 'del', ['first', 'second']));
    }

    /** The spelling both limiters and RedisLock use for a script: the key count, then keys and arguments. */
    #[Test]
    public function evalTakesTheKeyCountTheWayLaravelPassesIt(): void
    {
        $redis = $this->redis();

        $this->callMagic($redis, 'set', 'counter', '5');

        self::assertSame(
            8,
            $this->callMagic($redis, 'eval', "return redis.call('incrby', KEYS[1], ARGV[1])", 1, 'counter', 3),
        );
    }

    #[Test]
    public function commandTakesTheArgumentsAsAList(): void
    {
        $redis = $this->redis();

        $redis->command('rpush', ['list', 'a', 'b', 'c']);

        self::assertSame(['a', 'b', 'c'], $redis->command('lrange', ['list', 0, -1]));
    }

    #[Test]
    public function aPipelineAnswersWithTheRepliesInOrder(): void
    {
        $replies = $this->redis()->pipeline(function (CommandBatch $pipe): void {
            $this->callMagic($pipe, 'set', 'a', 1);
            $this->callMagic($pipe, 'incr', 'a');
            $this->callMagic($pipe, 'get', 'a');
        });

        self::assertSame([true, 2, '2'], $replies);
    }

    /** A failed command takes its own place as false, as in phpredis; the others still ran. */
    #[Test]
    public function aFailedCommandInAPipelineIsFalse(): void
    {
        $redis = $this->redis();

        $redis->command('set', ['text', 'not a number']);

        $replies = $redis->pipeline(static function (CommandBatch $pipe): void {
            $pipe->command('incr', ['text']);
            $pipe->command('set', ['after', 'ran']);
        });

        self::assertSame([false, true], $replies);
        self::assertSame('ran', $redis->command('get', ['after']));
    }

    #[Test]
    public function aTransactionRunsAsOneUnit(): void
    {
        $replies = $this->redis()->transaction(static function (CommandBatch $transaction): void {
            $transaction->command('incrby', ['counter', 2]);
            $transaction->command('lpush', ['events', 'inc']);
        });

        self::assertSame([2, 1], $replies);
    }

    #[Test]
    public function withoutACallbackTheBatchIsReturnedToExec(): void
    {
        $batch = $this->redis()->transaction();

        self::assertInstanceOf(CommandBatch::class, $batch);

        $this->callMagic($batch, 'set', 'a', 'b');
        $this->callMagic($batch, 'get', 'a');

        self::assertSame([true, 'b'], $batch->exec());
    }

    #[Test]
    public function anEmptyBatchAnswersWithNothing(): void
    {
        self::assertSame([], $this->redis()->pipeline(static function (): void {
        }));
    }

    /** exec() inside the callback would send the commands outside the transaction. */
    #[Test]
    public function execInsideTheCallbackIsRefused(): void
    {
        $this->expectException(NestedPipelineExecutionException::class);

        $this->redis()->transaction(static function (CommandBatch $transaction): void {
            $transaction->command('set', ['a', 'b']);
            $transaction->exec();
        });
    }

    /**
     * @return iterable<string, array{string, list<mixed>, string}>
     */
    public static function unsupportedCalls(): iterable
    {
        yield 'multi' => ['multi', [], 'transaction(function'];
        yield 'exec' => ['exec', [], 'transaction(function'];
        yield 'watch' => ['watch', ['key'], 'optimistic locking'];
        yield 'select' => ['select', [1], '"database"'];
        yield 'auth' => ['auth', ['secret'], '"password"'];
        yield 'raw subscribe' => ['subscribe', ['news'], 'subscribe($channels'];
        yield 'sharded subscribe' => ['ssubscribe', ['news'], 'cluster'];
    }

    /**
     * @param list<mixed> $parameters
     */
    #[Test]
    #[DataProvider('unsupportedCalls')]
    public function aCallTheFeatureDoesNotHaveIsRefusedWithTheReplacement(
        string $method,
        array $parameters,
        string $replacement,
    ): void {
        $this->expectException(UnsupportedRedisCallException::class);
        $this->expectExceptionMessage($replacement);

        $this->redis()->command($method, $parameters);
    }

    #[Test]
    public function aMagicMultiIsRefusedToo(): void
    {
        $this->expectException(UnsupportedRedisCallException::class);

        $this->callMagic($this->redis(), 'multi');
    }

    #[Test]
    public function aBatchRefusesTheSameCalls(): void
    {
        $this->expectException(UnsupportedRedisCallException::class);

        $this->redis()->pipeline(function (CommandBatch $pipe): void {
            $this->callMagic($pipe, 'multi');
        });
    }

    #[Test]
    public function aClusterConnectionIsRefused(): void
    {
        config()->set('database.redis.clusters', [
            'shards' => [
                ['host' => 'one'],
            ],
        ]);

        // The manager takes its section when it is built, so a fresh one has to be.
        $this->getApp()->forgetInstance('redis');

        $this->expectException(RedisClusterNotSupportedException::class);

        $this->manager()->connection('shards');
    }

    #[Test]
    public function commandsReportToTheEventsTheWayTheFrameworkConnectionsDo(): void
    {
        $manager = $this->manager();

        $manager->enableEvents();
        $manager->purge();

        $executed = [];
        $failed   = [];

        $connection = $manager->connection();

        $connection->listen(static function (CommandExecuted $event) use (&$executed): void {
            $executed[] = $event->command;
        });

        $connection->listenForFailures(static function (CommandFailed $event) use (&$failed): void {
            $failed[] = $event->command;
        });

        $connection->command('set', ['text', 'not a number']);

        try {
            $connection->command('incr', ['text']);
        } catch (RedisCommandException) {
        }

        self::assertSame(['set'], $executed);
        self::assertSame(['incr'], $failed);
    }

    /**
     * A subscriber and a publisher in two coroutines of one process. The publisher repeats
     * until PUBLISH counts a receiver, because it cannot know when the SUBSCRIBE landed. The
     * subscriber has a deadline, so a publisher that gives up fails the test instead of
     * leaving it waiting for a message that never comes.
     */
    #[Test]
    public function aSubscriptionDeliversToTheCallback(): void
    {
        $received = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$received): void {
                Scheduler::get()->spawn(
                    callback: function (): void {
                        for ($attempt = 0; $attempt < 200; ++$attempt) {
                            if ($this->redis()->command('publish', ['news', 'first']) > 0) {
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

        self::assertSame([['news', 'first']], $received);
    }

    /** The framework's duration limiter, which runs a script through eval(). */
    #[Test]
    public function theThrottleLimiterWorks(): void
    {
        $outcomes = [];

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $this->redis()->throttle('limited')
                ->allow(2)
                ->every(60)
                ->then(
                    static function () use (&$outcomes): void {
                        $outcomes[] = 'allowed';
                    },
                    static function () use (&$outcomes): void {
                        $outcomes[] = 'limited';
                    },
                );
        }

        self::assertSame(['allowed', 'allowed', 'limited'], $outcomes);
    }

    /** The framework's concurrency limiter: a slot taken and given back through scripts. */
    #[Test]
    public function theFunnelLimiterWorks(): void
    {
        $result = $this->redis()->funnel('funnel')
            ->limit(1)
            ->block(0)
            ->then(
                static fn(): string => 'ran',
                static fn(): string => 'limited',
            );

        self::assertSame('ran', $result);
        self::assertSame(0, $this->redis()->command('exists', ['funnel1']));
    }

    /** PHP keeps `'0'` and `'1'` as integer keys, so the shape cannot tell this map from a list. */
    #[Test]
    public function aMapOfNumericKeysStaysPairsForThePairCommands(): void
    {
        $redis = $this->redis();

        $this->callMagic($redis, 'mset', ['0' => 'zero', '1' => 'one']);
        $this->callMagic($redis, 'hset', 'hash', ['0' => 'zero', 'field' => 'value']);

        self::assertSame('zero', $redis->command('get', ['0']));
        self::assertSame('one', $redis->command('get', ['1']));
        self::assertSame('zero', $redis->client()->hGet('hash', '0'));
        self::assertSame('value', $redis->client()->hGet('hash', 'field'));
    }

    /** ZADD reads a map the way predis does: member => score. */
    #[Test]
    public function aZaddMapIsMemberToScore(): void
    {
        $this->callMagic($this->redis(), 'zadd', 'ranking', ['first' => 1, 'second' => 2.5]);

        self::assertSame(2.5, $this->redis()->client()->zScore('ranking', 'second'));
    }

    #[Test]
    public function aMapForACommandThatTakesAListIsRefused(): void
    {
        $this->expectException(InvalidRedisArgumentException::class);

        $this->callMagic($this->redis(), 'del', ['first' => 'second']);
    }

    /**
     * The workbench connection has `timeout_ms => 5000`. A flat deadline would be refused by
     * the extension for a wait of 5 seconds and for a wait without end.
     */
    #[Test]
    public function aBlockingCommandGetsTheDeadlineItsWaitNeeds(): void
    {
        $redis = $this->redis();

        $redis->command('rpush', ['queue', 'first', 'second']);

        self::assertSame(['queue', 'first'], $this->callMagic($redis, 'blpop', 'queue', 5));
        self::assertSame(['queue', 'second'], $this->callMagic($redis, 'brpop', ['queue'], 0));
        self::assertNull($this->callMagic($redis, 'blpop', 'empty', 0.1));
    }

    #[Test]
    public function aStreamReadWithBlockGetsTheDeadlineItsWaitNeeds(): void
    {
        $redis = $this->redis();

        $redis->command('xadd', ['stream', '*', 'field', 'value']);

        $reply = $redis->command('xread', [['stream' => '0'], -1, 6000]);

        self::assertIsArray($reply);
        self::assertFalse($redis->command('xread', [['stream' => '$'], -1, 100]));
        self::assertFalse($redis->executeRaw(['XREAD', 'BLOCK', 100, 'STREAMS', 'stream', '$']));
    }

    /** A command failing while it runs does not stop the others — in a transaction too. */
    #[Test]
    public function aRuntimeErrorInATransactionTakesItsOwnPlace(): void
    {
        $redis = $this->redis();

        $redis->command('hset', ['hash', 'field', 'value']);

        $replies = $redis->transaction(static function (CommandBatch $transaction): void {
            $transaction->command('incr', ['hash']);
            $transaction->command('set', ['after', 'ran']);
        });

        self::assertSame([false, true], $replies);
        self::assertSame('ran', $redis->command('get', ['after']));
    }

    /** A command refused while the transaction is queued aborts all of it, and the call throws. */
    #[Test]
    public function aQueueingErrorAbortsTheTransaction(): void
    {
        $redis = $this->redis();

        try {
            $redis->transaction(static function (CommandBatch $transaction): void {
                $transaction->command('set', ['before', 'queued']);
                $transaction->command('get', []);
            });

            self::fail('The transaction ran.');
        } catch (RedisCommandException $exception) {
            self::assertStringContainsString('EXECABORT', $exception->getMessage());
        }

        self::assertFalse($redis->command('get', ['before']));
    }

    #[Test]
    public function theLimitersPauseWithoutFreezingTheWorker(): void
    {
        self::assertInstanceOf(ConcurrencyLimiterBuilder::class, $this->redis()->funnel('funnel'));
        self::assertInstanceOf(DurationLimiterBuilder::class, $this->redis()->throttle('throttle'));

        $outcome = null;

        $longestStallMs = $this->longestStallMs([
            function (): void {
                $this->redis()->funnel('funnel')->limit(1)->then(static function (): void {
                    Sleeper::usleep(600_000);
                });
            },
            function () use (&$outcome): void {
                Sleeper::usleep(20_000);

                $outcome = $this->redis()->funnel('funnel')
                    ->limit(1)
                    ->block(5)
                    ->sleep(250)
                    ->then(static fn(): string => 'ran');
            },
        ]);

        self::assertSame('ran', $outcome);
        self::assertLessThan(150, $longestStallMs);
    }

    /**
     * phpredis answers a refused command with false and keeps the reason; this client throws
     * it. A caller would otherwise read `false` as an answer, and a WRONGTYPE as a missing key.
     */
    #[Test]
    public function aRefusedCommandThrowsWherePhpRedisAnswersFalse(): void
    {
        $redis = $this->redis();

        $redis->command('set', ['text', 'not a number']);

        $this->expectException(RedisCommandException::class);

        $this->callMagic($redis, 'incr', 'text');
    }

    /** phpredis moves the cursor through a reference; here it is part of the answer. */
    #[Test]
    public function theScanFamilyAnswersTheCursorAndTheItems(): void
    {
        $redis = $this->redis();

        $redis->client()->hSet('hash', ['field' => 'value']);
        $redis->command('zadd', ['ranking', 1.5, 'first']);

        self::assertSame(['0', ['hash']], $redis->scan(0, ['match' => 'ha*', 'count' => 100]));
        self::assertSame(['0', ['field' => 'value']], $redis->hscan('hash', 0));
        self::assertSame(['0', ['first' => 1.5]], $redis->zscan('ranking', 0));
    }

    /** A status and a bulk string arrive alike, so a script's status reply stays a string. */
    #[Test]
    public function aStatusFromAScriptStaysAString(): void
    {
        self::assertSame('OK', $this->redis()->eval("return redis.call('set', KEYS[1], 'x')", 1, 'key'));
    }

    private function callMagic(object $target, string $method, mixed ...$arguments): mixed
    {
        return $target->{$method}(...$arguments);
    }

    private function manager(): RedisManager
    {
        return $this->getApp()->make(RedisManager::class);
    }
}
