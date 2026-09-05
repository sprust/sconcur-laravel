<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
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
        $this->repository->join(channel: 'presence-room.1', socketId: '1.1', member: ['user_id' => '7']);
        $this->repository->join(channel: 'presence-room.1', socketId: '1.2', member: ['user_id' => '9']);

        self::assertSame(
            ['1.1' => ['user_id' => '7'], '1.2' => ['user_id' => '9']],
            $this->repository->members('presence-room.1'),
        );
    }

    #[Test]
    public function channelsDoNotSeeEachOther(): void
    {
        $this->repository->join(channel: 'presence-a', socketId: '1.1', member: ['user_id' => '7']);

        self::assertSame([], $this->repository->members('presence-b'));
    }

    #[Test]
    public function leavingDropsOnlyThatSocket(): void
    {
        $this->repository->join(channel: 'presence-room.1', socketId: '1.1', member: ['user_id' => '7']);
        $this->repository->join(channel: 'presence-room.1', socketId: '1.2', member: ['user_id' => '9']);

        $this->repository->leave(channel: 'presence-room.1', socketId: '1.1');

        self::assertSame(['1.2' => ['user_id' => '9']], $this->repository->members('presence-room.1'));
    }

    /** The key goes when the last member does, rather than lingering until the TTL. */
    #[Test]
    public function theChannelDisappearsWithItsLastMember(): void
    {
        $this->repository->join(channel: 'presence-room.1', socketId: '1.1', member: ['user_id' => '7']);
        $this->repository->leave(channel: 'presence-room.1', socketId: '1.1');

        self::assertSame([], $this->repository->members('presence-room.1'));
        self::assertFalse($this->getApp()->make(CacheRepository::class)->has('sconcur:ws:presence:tests:presence-room.1'));
    }

    #[Test]
    public function leavingSomethingThatWasNeverThereIsQuiet(): void
    {
        $this->repository->leave(channel: 'presence-room.1', socketId: '1.1');

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
}
