<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Events;

/**
 * A client-* event a subscriber sent on a private or presence channel, already accepted
 * (authorized channel, client events enabled, rate limit not reached) and about to be
 * published to the other subscribers.
 */
readonly class ClientEventReceived
{
    public function __construct(
        public string $socketId,
        public string $channel,
        public string $event,
        public string $data,
    ) {
    }
}
