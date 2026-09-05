<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Protocol;

/**
 * The protocol events this server speaks. The names are what pusher-js expects on the
 * wire, so they are written out rather than composed — a typo here is a client that
 * silently ignores the frame.
 */
enum ProtocolEventEnum: string
{
    case ConnectionEstablished = 'pusher:connection_established';

    case Subscribe = 'pusher:subscribe';

    case Unsubscribe = 'pusher:unsubscribe';

    case Ping = 'pusher:ping';

    case Pong = 'pusher:pong';

    case Error = 'pusher:error';

    case SubscriptionError = 'pusher:subscription_error';

    case SubscriptionSucceeded = 'pusher_internal:subscription_succeeded';

    case MemberAdded = 'pusher_internal:member_added';

    case MemberRemoved = 'pusher_internal:member_removed';

    /** The prefix of an event a client may send on a private or presence channel. */
    public const string CLIENT_EVENT_PREFIX = 'client-';

    public static function isClientEvent(string $event): bool
    {
        return str_starts_with($event, self::CLIENT_EVENT_PREFIX);
    }
}
