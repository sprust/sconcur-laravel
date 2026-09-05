<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Protocol;

/**
 * What a channel name says about itself. The prefix is the whole of the type: the
 * protocol has no other way to tell a private channel from a public one, and neither
 * does Laravel — `Broadcast::channel('orders.{id}')` matches on the name with the prefix
 * already stripped.
 */
enum ChannelTypeEnum: string
{
    case Public = 'public';

    case Private = 'private';

    case Presence = 'presence';

    /** Recognised so it can be refused by name rather than silently treated as private. */
    case Encrypted = 'encrypted';

    /** Whether a subscription to this kind of channel must carry a signature. */
    public function requiresAuthorization(): bool
    {
        return $this !== self::Public;
    }
}
