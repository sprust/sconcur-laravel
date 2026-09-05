<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Protocol;

/**
 * Close/error codes of the protocol. The range is what the client acts on, not the
 * number: 4000-4099 tells it not to reconnect, 4100-4199 to reconnect after a pause,
 * 4200-4299 to reconnect at once. Picking a code from the wrong range turns a
 * configuration mistake into a reconnect loop.
 */
enum ErrorCodeEnum: int
{
    /** The key in the connection path is not this server's. */
    case UnknownAppKey = 4001;

    /** The subscription carried no signature, or one that did not verify. */
    case Unauthorized = 4009;

    /** A limit of this server was reached (channels per connection, connections). */
    case OverCapacity = 4100;

    /** Anything unexpected on our side; the client may come straight back. */
    case GenericReconnect = 4200;

    /** Whether the client is expected to give up rather than reconnect. */
    public function isFatal(): bool
    {
        return $this->value < 4100;
    }
}
