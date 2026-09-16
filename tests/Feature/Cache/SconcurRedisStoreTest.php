<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Cache;

use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
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
     * The waiter pauses through Sleeper, so the holder keeps running while it waits: the
     * holder's release is what the waiter's next attempt sees.
     */
    #[Test]
    public function blockWaitsForAnotherCoroutineWithoutFreezingIt(): void
    {
        $order = [];

        $waitGroup = WaitGroup::create();

        $waitGroup->add(
            callback: function () use (&$order): void {
                $lock = $this->store()->lock('resource', 60);

                $lock->acquire();

                $order[] = 'held';

                Sleeper::usleep(200_000);

                $order[] = 'releasing';

                $lock->release();
            },
        );

        $waitGroup->add(
            callback: function () use (&$order): void {
                Sleeper::usleep(50_000);

                $this->store()->lock('resource', 60)->betweenBlockedAttemptsSleepFor(20)->block(
                    5,
                    static function () use (&$order): void {
                        $order[] = 'acquired';
                    },
                );
            },
        );

        $waitGroup->waitAll();

        self::assertSame(['held', 'releasing', 'acquired'], $order);
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

    /** The store builds its own connections, so the facade's client is not its concern. */
    #[Test]
    public function theStoreDoesNotDependOnTheFacadeClient(): void
    {
        config()->set('database.redis.client', 'phpredis');

        Cache::forgetDriver('sconcur_redis');

        $this->cache()->put('key', 'value', 60);

        self::assertInstanceOf(Store::class, $this->cache()->getStore());
        self::assertSame('value', $this->cache()->get('key'));
    }

    #[Test]
    public function theStoreRefusesWhatTheClientRefuses(): void
    {
        config()->set('database.redis.options', ['prefix' => 'laravel_database_']);

        Cache::forgetDriver('sconcur_redis');

        $this->expectException(UnsupportedRedisOptionException::class);

        $this->cache();
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
