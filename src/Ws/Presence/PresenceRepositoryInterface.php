<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Presence;

/**
 * Who is in a presence channel.
 *
 * The member list is the one piece of ws state that cannot live in a single worker: the
 * kernel spreads connections across an SO_REUSEPORT pool, so a worker knows only its own
 * subscribers, and a list built from those is wrong rather than partial.
 */
interface PresenceRepositoryInterface
{
    /**
     * @param array<string, mixed> $member the decoded channel_data: user_id and user_info
     */
    public function join(string $channel, string $socketId, array $member): void;

    public function leave(string $channel, string $socketId): void;

    /**
     * Every member of the channel, keyed by socket id — one user may hold several
     * connections, and the caller is what folds them into one member.
     *
     * @return array<string, array<string, mixed>>
     */
    public function members(string $channel): array;
}
