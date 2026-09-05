<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Bus;

use JsonException;

/**
 * One broadcast on its way from whoever raised it to every worker holding subscribers.
 *
 * `data` is already the string the client will receive. It is encoded once, by the
 * publisher, and carried through untouched: decoding it in every worker only to encode it
 * again would be the same bytes twice for nothing, and it is not the workers' business
 * what is inside.
 *
 * `socket` is the connection that must not receive it — `broadcast()->toOthers()`, and a
 * client event never echoing back to its author.
 */
readonly class BroadcastMessageDto
{
    /**
     * @param list<string> $channels
     */
    public function __construct(
        public array $channels,
        public string $event,
        public string $data,
        public ?string $socket = null,
    ) {
    }

    public function toJson(): string
    {
        $payload = [
            'channels' => $this->channels,
            'event'    => $this->event,
            'data'     => $this->data,
        ];

        if ($this->socket !== null) {
            $payload['socket'] = $this->socket;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Null for anything that does not decode into a usable message: the bus is shared,
     * and a worker must not die over a frame someone else published.
     */
    public static function fromJson(string $json): ?self
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $event = $decoded['event'] ?? null;

        if (!is_string($event) || $event === '') {
            return null;
        }

        $channels = array_values(array_filter((array) ($decoded['channels'] ?? []), is_string(...)));

        if ($channels === []) {
            return null;
        }

        $data = $decoded['data'] ?? '{}';

        $socket = $decoded['socket'] ?? null;

        return new self(
            channels: $channels,
            event: $event,
            data: is_string($data) ? $data : '{}',
            socket: is_string($socket) ? $socket : null,
        );
    }
}
