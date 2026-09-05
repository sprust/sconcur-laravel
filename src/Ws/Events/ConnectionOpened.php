<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Events;

/**
 * A client finished the handshake and was given its socket id. Raised for the
 * application to observe; the pool needs no listener of its own.
 */
readonly class ConnectionOpened
{
    public function __construct(
        public string $socketId,
        public string $remoteAddr,
        public string $path,
    ) {
    }
}
