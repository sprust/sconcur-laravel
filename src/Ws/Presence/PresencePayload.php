<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Presence;

/**
 * The member list in the shape the client expects.
 *
 * The store keeps members per socket, the protocol counts them per user: one person with
 * two tabs is one member, and joining the second tab is not an arrival. Folding one into
 * the other is this class's only real job, and it is also what tells the handler whether
 * an arrival or a departure is worth announcing at all.
 */
class PresencePayload
{
    /**
     * The `presence` block of a subscription_succeeded frame.
     *
     * @param array<string, array<string, mixed>> $members socket id => member
     *
     * @return array<string, mixed>
     */
    public function forSubscription(array $members): array
    {
        $hash = [];

        foreach ($members as $member) {
            $userId = $this->userId($member);

            if ($userId === null) {
                continue;
            }

            $hash[$userId] = $member['user_info'] ?? [];
        }

        return [
            'presence' => [
                // Cast back to strings: PHP turns a numeric string array key into an int,
                // and the protocol's ids are strings.
                'ids'   => array_map(strval(...), array_keys($hash)),
                'hash'  => (object) $hash,
                'count' => count($hash),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $member
     *
     * @return array<string, mixed>
     */
    public function forMemberAdded(array $member): array
    {
        return [
            'user_id'   => $this->userId($member),
            'user_info' => $member['user_info'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $member
     *
     * @return array<string, mixed>
     */
    public function forMemberRemoved(array $member): array
    {
        return [
            'user_id' => $this->userId($member),
        ];
    }

    /**
     * Whether this user is present through some other connection than $socketId. It is
     * what keeps a second tab from announcing an arrival, and closing it from announcing
     * a departure the person has not made.
     *
     * @param array<string, array<string, mixed>> $members
     */
    public function hasOtherConnection(array $members, string $socketId, string $userId): bool
    {
        foreach ($members as $memberSocketId => $member) {
            if ($memberSocketId === $socketId) {
                continue;
            }

            if ($this->userId($member) === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $member
     */
    public function userId(array $member): ?string
    {
        $userId = $member['user_id'] ?? null;

        if (is_string($userId) || is_int($userId)) {
            return (string) $userId;
        }

        return null;
    }
}
