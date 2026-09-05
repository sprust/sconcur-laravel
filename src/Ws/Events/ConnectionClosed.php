<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Events;

/**
 * A client went away, whatever ended it: its own close, a network failure, or the pool
 * shutting down.
 */
readonly class ConnectionClosed
{
    /**
     * @param list<string> $channels what it was still subscribed to
     */
    public function __construct(
        public string $socketId,
        public array $channels,
    ) {
    }
}
