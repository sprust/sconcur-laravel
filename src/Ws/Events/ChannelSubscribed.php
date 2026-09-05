<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Events;

readonly class ChannelSubscribed
{
    public function __construct(
        public string $socketId,
        public string $channel,
    ) {
    }
}
