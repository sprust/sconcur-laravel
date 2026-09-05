<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

/**
 * Socket identifiers in the form the protocol requires, two integers separated by a dot.
 *
 * The first half is the worker's pid and the second a counter, which makes an id unique
 * across the pool without any coordination: the counter covers the process, the pid
 * covers everything else. The connection id the extension hands out is not usable here —
 * it is not of this shape, and the client echoes the socket id back into signatures.
 */
class SocketIdGenerator
{
    private int $counter = 0;

    public function __construct(
        private readonly int $processId = 0,
    ) {
    }

    public function next(): string
    {
        return sprintf('%d.%d', $this->processId > 0 ? $this->processId : getmypid(), ++$this->counter);
    }
}
