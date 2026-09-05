<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use Illuminate\Cache\Repository;
use SConcur\Laravel\Tests\Feature\Ws\Support\BusyLockStore;
use SConcur\Laravel\Tests\Feature\Ws\Support\LocklessStore;
use SConcur\Laravel\Tests\Feature\Ws\Support\StoreIsDownException;
use SConcur\Laravel\Ws\Presence\CachePresenceRepository;

/**
 * The member list as several workers share it.
 *
 * The store is the array driver, which is a LockProvider like Redis is — so the locked
 * path is the one under test here, not the fallback.
 */
class CachePresenceRepositoryTest extends BaseTestCase
{
    private CachePresenceRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new CachePresenceRepository(
            cache: $this->getApp()->make(CacheRepository::class),
            prefix: 'sconcur:ws:presence:tests',
            ttlSeconds: 60,
        );
    }

    #[Test]
    public function itKeepsMembersPerSocket(): void
    {
        $this->repository->join(
            channel: 'presence-room.1',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );
        $this->repository->join(
            channel: 'presence-room.1',
            socketId: '1.2',
            member: ['user_id' => '9'],
        );

        self::assertSame(
            ['1.1' => ['user_id' => '7'], '1.2' => ['user_id' => '9']],
            $this->repository->members('presence-room.1'),
        );
    }

    #[Test]
    public function channelsDoNotSeeEachOther(): void
    {
        $this->repository->join(
            channel: 'presence-a',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );

        self::assertSame([], $this->repository->members('presence-b'));
    }

    #[Test]
    public function leavingDropsOnlyThatSocket(): void
    {
        $this->repository->join(
            channel: 'presence-room.1',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );
        $this->repository->join(
            channel: 'presence-room.1',
            socketId: '1.2',
            member: ['user_id' => '9'],
        );

        $this->repository->leave(
            channel: 'presence-room.1',
            socketId: '1.1',
        );

        self::assertSame(['1.2' => ['user_id' => '9']], $this->repository->members('presence-room.1'));
    }

    /** The key goes when the last member does, rather than lingering until the TTL. */
    #[Test]
    public function theChannelDisappearsWithItsLastMember(): void
    {
        $this->repository->join(
            channel: 'presence-room.1',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );
        $this->repository->leave(
            channel: 'presence-room.1',
            socketId: '1.1',
        );

        self::assertSame([], $this->repository->members('presence-room.1'));
        self::assertFalse($this->getApp()->make(CacheRepository::class)->has('sconcur:ws:presence:tests:presence-room.1'));
    }

    #[Test]
    public function leavingSomethingThatWasNeverThereIsQuiet(): void
    {
        $this->repository->leave(
            channel: 'presence-room.1',
            socketId: '1.1',
        );

        self::assertSame([], $this->repository->members('presence-room.1'));
    }

    /**
     * The lock is released after every change. Without it the second call would wait out
     * its whole retry window and give up, and the member would never be recorded — which
     * is invisible until a second person joins.
     */
    #[Test]
    public function theLockIsGivenBackBetweenChanges(): void
    {
        for ($index = 1; $index <= 5; $index++) {
            $this->repository->join(
                channel: 'presence-room.1',
                socketId: '1.' . $index,
                member: ['user_id' => (string) $index],
            );
        }

        self::assertCount(5, $this->repository->members('presence-room.1'));
    }

    /**
     * A store with no locks — the array and file drivers of a single process — takes the
     * unlocked path. There is nothing to race with there: two workers cannot share such a
     * store in the first place.
     */
    #[Test]
    public function aStoreWithoutLocksStillKeepsTheList(): void
    {
        $repository = $this->onStore(new LocklessStore());

        $repository->join(
            channel: 'presence-room.1',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );

        self::assertSame(['1.1' => ['user_id' => '7']], $repository->members('presence-room.1'));
    }

    /**
     * Changing the list swallows a store that has gone away. It used to escape, and the
     * caller is a connection teardown that must finish: an entry left in the registry is
     * a dead socket the fan-out writes to and a subscriber coroutine that never ends.
     */
    #[Test]
    public function changingTheListSwallowsADeadStore(): void
    {
        $store = new LocklessStore();

        $repository = $this->onStore($store);

        $store->down = true;

        $repository->join(
            channel: 'presence-room.1',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );
        $repository->leave(
            channel: 'presence-room.1',
            socketId: '1.1',
        );

        // Nothing escaped, and nothing was recorded either — the documented trade-off.
        $store->down = false;

        self::assertSame([], $repository->members('presence-room.1'));
    }

    /**
     * Reading it does not swallow, and deliberately: an empty roster is a wrong answer,
     * not a missing one. The handler catches this and refuses that one subscription
     * rather than handing the client a channel it would believe is empty.
     */
    #[Test]
    public function readingTheListSurfacesADeadStore(): void
    {
        $store = new LocklessStore();

        $repository = $this->onStore($store);

        $store->down = true;

        $this->expectException(StoreIsDownException::class);

        $repository->members('presence-room.1');
    }

    /**
     * A lock held by another worker for the whole retry window costs this change and
     * nothing else. Writing anyway would be the race the lock exists to prevent, and the
     * member is announced over the bus either way — what is lost is one entry of the
     * snapshot, not the event.
     */
    #[Test]
    public function aLockItNeverGetsCostsTheChangeAndNothingElse(): void
    {
        $store = new BusyLockStore();

        $repository = new CachePresenceRepository(
            cache: new Repository($store),
            prefix: 'sconcur:ws:presence:tests',
            ttlSeconds: 60,
        );

        $repository->join(
            channel: 'presence-room.1',
            socketId: '1.1',
            member: ['user_id' => '7'],
        );

        self::assertSame([], $repository->members('presence-room.1'));
        // Retried rather than given up on the first refusal, and bounded rather than
        // waited out for ever.
        self::assertGreaterThan(1, $store->attempts);
        self::assertLessThan(20, $store->attempts);
    }

    private function onStore(LocklessStore $store): CachePresenceRepository
    {
        return new CachePresenceRepository(
            cache: new Repository($store),
            prefix: 'sconcur:ws:presence:tests',
            ttlSeconds: 60,
        );
    }
}
