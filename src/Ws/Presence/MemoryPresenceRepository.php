<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Presence;

/**
 * The member list in this process and nowhere else.
 *
 * Correct for a single-worker pool, where this process holds every connection there is,
 * and wrong for any other — which is why the choice is not left to chance: the start
 * command refuses to stay quiet when this store is paired with more than one worker.
 */
class MemoryPresenceRepository implements PresenceRepositoryInterface
{
    /** @var array<string, array<string, array<string, mixed>>> channel => socket id => member */
    private array $members = [];

    public function join(string $channel, string $socketId, array $member): void
    {
        $this->members[$channel][$socketId] = $member;
    }

    public function leave(string $channel, string $socketId): void
    {
        unset($this->members[$channel][$socketId]);

        if (($this->members[$channel] ?? []) === []) {
            unset($this->members[$channel]);
        }
    }

    public function members(string $channel): array
    {
        return $this->members[$channel] ?? [];
    }
}
