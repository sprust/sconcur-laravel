<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use RuntimeException;
use SConcur\Laravel\Ws\Presence\MemoryPresenceRepository;
use SConcur\Laravel\Ws\Presence\PresenceRepositoryInterface;

/**
 * A presence store that works until it is told to stop, the way a cache does when the
 * Redis behind it goes away mid-session.
 *
 * Which call fails is chosen per test, because the interesting failures are not at the
 * start: a store that is down before the subscription simply refuses it, while one that
 * dies before the disconnect is what used to leave the connection in the registry.
 */
class ThrowingPresenceRepository implements PresenceRepositoryInterface
{
    public bool $down = false;

    private MemoryPresenceRepository $inner;

    public function __construct()
    {
        $this->inner = new MemoryPresenceRepository();
    }

    public function join(string $channel, string $socketId, array $member): void
    {
        $this->guard();

        $this->inner->join(channel: $channel, socketId: $socketId, member: $member);
    }

    public function leave(string $channel, string $socketId): void
    {
        $this->guard();

        $this->inner->leave(channel: $channel, socketId: $socketId);
    }

    public function members(string $channel): array
    {
        $this->guard();

        return $this->inner->members($channel);
    }

    private function guard(): void
    {
        if ($this->down) {
            throw new RuntimeException('presence store is unreachable');
        }
    }
}
