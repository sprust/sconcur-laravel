<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Carbon\CarbonInterval as Duration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use RuntimeException;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Features\Redis\Connection as RedisClient;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Cache\Redis\Store;
use SConcur\Laravel\Redis\Exceptions\UnsupportedRedisOptionException;
use SConcur\Laravel\Tests\Feature\Redis\BaseRedisTestCase;
use SConcur\WaitGroup;
use stdClass;

/**
 * The `sconcur_redis` store, against the live server.
 */
class SconcurRedisStoreTest extends BaseRedisTestCase
{
    #[Test]
    public function theStoreIsOnTheFeature(): void
    {
        self::assertInstanceOf(Store::class, $this->cache()->getStore());
    }

    #[Test]
    public function aValueSurvivesTheRoundTrip(): void
    {
        $object        = new stdClass();
        $object->title = 'note';

        $this->cache()->put('array', ['a' => 1, 'b' => [true, null]], 60);
        $this->cache()->put('object', $object, 60);
        $this->cache()->put('binary', "\x00\xff", 60);

        self::assertSame(['a' => 1, 'b' => [true, null]], $this->cache()->get('array'));
        self::assertEquals($object, $this->cache()->get('object'));
        self::assertSame("\x00\xff", $this->cache()->get('binary'));
        self::assertNull($this->cache()->get('missing'));
    }

    /** A finite number is stored as it is — which is what lets INCRBY work on it. */
    #[Test]
    public function aNumberIsStoredUnserialized(): void
    {
        $this->cache()->put('number', 41, 60);

        self::assertSame('41', $this->client()->get('number'));
        self::assertSame(42, $this->cache()->increment('number'));
        self::assertSame(40, $this->cache()->decrement('number', 2));
    }

    #[Test]
    public function incrementStartsAMissingKeyAtZero(): void
    {
        self::assertSame(3, $this->cache()->increment('counter', 3));
    }

    #[Test]
    public function aValueWithATtlExpires(): void
    {
        $this->cache()->put('short', 'lived', 1);

        self::assertSame(1, $this->client()->ttl('short'));

        $this->client()->command('PEXPIRE', ['short', 1]);

        usleep(20_000);

        self::assertNull($this->cache()->get('short'));
    }

    #[Test]
    public function foreverHasNoExpiry(): void
    {
        $this->cache()->forever('kept', 'value');

        self::assertNull($this->client()->ttl('kept'));
        self::assertSame('value', $this->cache()->get('kept'));
    }

    #[Test]
    public function manyReadsSeveralKeysInOneGo(): void
    {
        $this->cache()->putMany(['first' => 'one', 'second' => ['two'], '3' => 3], 60);

        self::assertSame(
            ['first' => 'one', 'second' => ['two'], '3' => '3', 'missing' => null],
            $this->cache()->many(['first', 'second', '3', 'missing']),
        );

        self::assertSame(60, $this->client()->ttl('second'));
    }

    #[Test]
    public function addWritesOnlyWhenTheKeyIsAbsent(): void
    {
        self::assertTrue($this->cache()->add('once', 'first', 60));
        self::assertFalse($this->cache()->add('once', 'second', 60));
        self::assertSame('first', $this->cache()->get('once'));
    }

    #[Test]
    public function forgetRemovesTheKey(): void
    {
        $this->cache()->put('gone', 'soon', 60);

        self::assertTrue($this->cache()->forget('gone'));
        self::assertFalse($this->cache()->forget('gone'));
    }

    #[Test]
    public function thePrefixIsPartOfTheKey(): void
    {
        config()->set('cache.stores.sconcur_redis.prefix', 'app:');

        Cache::forgetDriver('sconcur_redis');

        $this->cache()->put('key', 'value', 60);

        self::assertSame('app:', $this->cache()->getPrefix());
        self::assertSame(serialize('value'), $this->client()->get('app:key'));
    }

    /** FLUSHDB, as the framework's store does: the whole database, other keys included. */
    #[Test]
    public function flushEmptiesTheDatabase(): void
    {
        $this->cache()->put('cached', 'value', 60);
        $this->client()->set('not-cached', 'value');

        self::assertTrue($this->cache()->flush());
        self::assertSame(0, $this->client()->dbSize());
    }

    #[Test]
    public function aClassOutsideTheAllowedListIsNotRestored(): void
    {
        config()->set('cache.serializable_classes', false);

        Cache::forgetDriver('sconcur_redis');

        $this->cache()->put('object', new stdClass(), 60);

        self::assertInstanceOf('__PHP_Incomplete_Class', $this->cache()->get('object'));
    }

    #[Test]
    public function tagsWork(): void
    {
        $this->cache()->tags(['users'])->put('first', 'one', 60);
        $this->cache()->tags(['posts'])->put('second', 'two', 60);

        $this->cache()->tags(['users'])->flush();

        self::assertNull($this->cache()->tags(['users'])->get('first'));
        self::assertSame('two', $this->cache()->tags(['posts'])->get('second'));
    }

    #[Test]
    public function aLockIsHeldByItsOwnerOnly(): void
    {
        $lock  = $this->store()->lock('resource', 60);
        $other = $this->store()->lock('resource', 60);

        self::assertTrue($lock->get());
        self::assertFalse($other->get());
        self::assertFalse($other->release());
        self::assertTrue($lock->release());
        self::assertTrue($other->get());
    }

    #[Test]
    public function aLockCanBeRestoredByItsOwnerString(): void
    {
        $lock = $this->store()->lock('resource', 60);

        $lock->acquire();

        $restored = $this->store()->restoreLock('resource', $lock->owner());

        self::assertTrue($restored->isOwnedByCurrentProcess());
        self::assertTrue($restored->release());
    }

    #[Test]
    public function aLockRefreshExtendsItsExpiry(): void
    {
        $lock = $this->store()->lock('resource', 5);

        $lock->acquire();

        self::assertTrue($lock->refresh(60));
        self::assertSame(60, $this->lockClient()->ttl('resource'));
    }

    #[Test]
    public function forceReleaseIgnoresTheOwner(): void
    {
        $this->store()->lock('resource', 60)->acquire();

        $this->store()->lock('resource')->forceRelease();

        self::assertTrue($this->store()->lock('resource', 60)->get());
    }

    /** Locks go to `lock_connection`, which in the workbench is another database. */
    #[Test]
    public function locksLiveOnTheLockConnection(): void
    {
        $this->store()->lock('resource', 60)->acquire();

        self::assertSame(0, $this->client()->exists('resource'));
        self::assertSame(1, $this->lockClient()->exists('resource'));
    }

    #[Test]
    public function blockGivesUpAfterItsTimeout(): void
    {
        $this->store()->lock('resource', 60)->acquire();

        $this->expectException(LockTimeoutException::class);

        $this->store()->lock('resource', 60)->betweenBlockedAttemptsSleepFor(50)->block(0);
    }

    /**
     * The waiter pauses between attempts, and a ticker coroutine beside it keeps waking: the
     * pause is the waiter's alone. With the framework's usleep() the ticker would stall for
     * the whole 250 ms of every pause.
     */
    #[Test]
    public function blockWaitsForAnotherCoroutineWithoutFreezingIt(): void
    {
        $acquired = false;

        $longestStallMs = $this->longestStallMs([
            function (): void {
                $lock = $this->store()->lock('resource', 60);

                $lock->acquire();

                Sleeper::usleep(600_000);

                $lock->release();
            },
            function () use (&$acquired): void {
                Sleeper::usleep(20_000);

                $acquired = $this->store()
                    ->lock('resource', 60)
                    ->betweenBlockedAttemptsSleepFor(250)
                    ->block(5);
            },
        ]);

        self::assertTrue($acquired);
        self::assertLessThan(150, $longestStallMs);
    }

    /** The measurement above is worth something only if it sees a native pause. */
    #[Test]
    public function theStallMeasurementSeesANativePause(): void
    {
        $longestStallMs = $this->longestStallMs([
            static function (): void {
                Sleeper::usleep(20_000);

                usleep(250_000);
            },
        ]);

        self::assertGreaterThanOrEqual(240, $longestStallMs);
    }

    /** Outside a coroutine the pause is the framework's Sleep, which a test can fake. */
    #[Test]
    public function blockOutsideACoroutineSleepsThroughTheFrameworksSleep(): void
    {
        Sleep::fake(syncWithCarbon: true);

        $this->store()->lock('resource', 60)->acquire();

        try {
            $this->store()->lock('resource', 60)->betweenBlockedAttemptsSleepFor(250)->block(1);

            self::fail('The lock was taken while it was held.');
        } catch (LockTimeoutException) {
        }

        Sleep::assertSlept(static fn(Duration $duration): bool => (int) $duration->totalMilliseconds === 250, 3);
    }

    /** Concurrent increments of one key from many coroutines: none is lost. */
    #[Test]
    public function concurrentIncrementsAreAllCounted(): void
    {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 50; ++$index) {
            $waitGroup->add(
                callback: function (): void {
                    $this->cache()->increment('hits');
                },
            );
        }

        $waitGroup->waitAll();

        self::assertSame('50', $this->cache()->get('hits'));
    }

    #[Test]
    public function concurrentAddsLetExactlyOneWin(): void
    {
        $wins = 0;

        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < 20; ++$index) {
            $waitGroup->add(
                callback: function () use (&$wins, $index): void {
                    if ($this->cache()->add('winner', $index, 60)) {
                        ++$wins;
                    }
                },
            );
        }

        $waitGroup->waitAll();

        self::assertSame(1, $wins);
    }

    /**
     * The store builds its own connections: with RedisManager made unusable and the facade on
     * another client — whose options, prefix included, are that client's business — the store
     * still works.
     */
    #[Test]
    public function theStoreDoesNotDependOnTheFacadeClient(): void
    {
        config()->set('database.redis.client', 'phpredis');
        config()->set('database.redis.options', ['prefix' => 'laravel_database_']);

        $this->getApp()->forgetInstance('redis');
        $this->getApp()->bind('redis', static function (): never {
            throw new RuntimeException('The store must not go through RedisManager.');
        });

        Cache::forgetDriver('sconcur_redis');

        $this->cache()->put('key', 'value', 60);

        self::assertInstanceOf(Store::class, $this->cache()->getStore());
        self::assertSame('value', $this->cache()->get('key'));
    }

    /** With the facade on the sconcur client, the options are this client's, and what it refuses is refused. */
    #[Test]
    public function theStoreRefusesWhatTheClientRefuses(): void
    {
        config()->set('database.redis.client', 'sconcur');
        config()->set('database.redis.options', ['persistent' => true]);

        Cache::forgetDriver('sconcur_redis');

        $this->expectException(UnsupportedRedisOptionException::class);

        $this->cache();
    }

    /**
     * The connection's prefix goes in front of the store's, whichever client the facade is on,
     * and a key written by the framework's RedisStore on phpredis with the same configuration
     * is found under the same name — a value both ways, and a lock.
     */
    #[Test]
    public function theStoreSharesKeysAndLocksWithRedisStoreOnPhpRedis(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('phpredis is not installed.');
        }

        config()->set('database.redis.client', 'phpredis');
        config()->set('database.redis.options', ['prefix' => 'laravel_database_']);
        config()->set('cache.prefix', 'laravel_cache_');
        config()->set('cache.stores.redis', [
            'driver'          => 'redis',
            'connection'      => 'cache',
            'lock_connection' => 'default',
        ]);

        $this->getApp()->forgetInstance('redis');

        Cache::forgetDriver('sconcur_redis');

        $this->cache()->put('shared', 'from sconcur', 60);

        $redisStore = Cache::store('redis');

        $redisLocks = $redisStore->getStore();

        assert($redisLocks instanceof LockProvider);

        self::assertSame('from sconcur', $redisStore->get('shared'));
        self::assertSame(serialize('from sconcur'), $this->client()->get('laravel_database_laravel_cache_shared'));

        $redisStore->put('other', 'from phpredis', 60);

        self::assertSame('from phpredis', $this->cache()->get('other'));

        $lock = $this->store()->lock('job', 60);

        self::assertTrue($lock->acquire());
        self::assertFalse($redisLocks->lock('job', 60)->get());
        self::assertSame($lock->owner(), $this->lockClient()->get('laravel_database_laravel_cache_job'));
        self::assertTrue($lock->release());
    }

    #[Test]
    public function manyFindsIntegerLikeKeysWithAndWithoutAPrefix(): void
    {
        $this->cache()->putMany(['1' => 'one', '2' => 'two'], 60);

        self::assertSame(['1' => 'one', '2' => 'two', '3' => null], $this->cache()->many(['1', '2', '3']));

        config()->set('cache.prefix', '7');

        Cache::forgetDriver('sconcur_redis');

        $this->cache()->putMany(['1' => 'seventeen'], 60);

        self::assertSame(['1' => 'seventeen'], $this->cache()->many(['1']));
        self::assertSame(serialize('seventeen'), $this->client()->get('71'));
    }

    /** put() and putMany() write a number in one spelling, the shortest that reads back exactly. */
    #[Test]
    public function aFloatIsWrittenTheSameWayByEveryMethod(): void
    {
        $this->cache()->put('put', 0.1 + 0.2, 60);
        $this->cache()->putMany(['many' => 0.1 + 0.2], 60);
        $this->cache()->forever('large', 1e15);

        self::assertSame('0.30000000000000004', $this->client()->get('put'));
        self::assertSame('0.30000000000000004', $this->client()->get('many'));
        self::assertSame('1000000000000000', $this->client()->get('large'));
        self::assertSame(1000000000000001, $this->cache()->increment('large'));
    }

    #[Test]
    public function aLockWithoutSecondsHasNoExpiry(): void
    {
        $lock = $this->store()->lock('resource');

        self::assertTrue($lock->acquire());
        self::assertNull($this->lockClient()->ttl('resource'));
    }

    #[Test]
    public function refreshingWithZeroSecondsRemovesTheExpiry(): void
    {
        $lock = $this->store()->lock('resource', 60);

        $lock->acquire();

        self::assertTrue($lock->refresh(0));
        self::assertNull($this->lockClient()->ttl('resource'));
    }

    #[Test]
    public function aRefreshByAnotherOwnerIsRefused(): void
    {
        $this->store()->lock('resource', 60)->acquire();

        self::assertFalse($this->store()->lock('resource', 60)->refresh(120));
    }

    private function cache(): Repository
    {
        $repository = Cache::store('sconcur_redis');

        assert($repository instanceof Repository);

        return $repository;
    }

    private function store(): Store
    {
        $store = $this->cache()->getStore();

        assert($store instanceof Store);

        return $store;
    }

    private function client(): RedisClient
    {
        return $this->redis('cache')->client();
    }

    private function lockClient(): RedisClient
    {
        return $this->redis()->client();
    }
}
