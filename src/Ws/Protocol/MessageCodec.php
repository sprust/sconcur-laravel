<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Protocol;

use JsonException;

/**
 * Turns frames into strings and back.
 *
 * The one rule worth stating: in this protocol `data` travels as a string with JSON
 * inside it, not as a nested object. Both sides do it, pusher-js decodes on that
 * assumption, and a frame that nests the object instead is dropped without a word — which
 * is why every encode here goes through encodeData() rather than building the array by
 * hand.
 */
class MessageCodec
{
    /**
     * Decodes a client frame. Returns null for anything that is not a JSON object with
     * an `event` string — a client sending noise gets ignored, not crashed on.
     */
    public function decode(string $raw): ?IncomingMessageDto
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
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

        $channel = $decoded['channel'] ?? null;

        return new IncomingMessageDto(
            event: $event,
            data: $this->decodeData($decoded['data'] ?? null),
            channel: is_string($channel) ? $channel : null,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function encode(ProtocolEventEnum $event, array $data = [], ?string $channel = null): string
    {
        return $this->encodeRaw(
            event: $event->value,
            data: $this->encodeData($data),
            channel: $channel,
        );
    }

    /**
     * The same frame for an application event, whose payload is already the JSON string
     * that came off the bus. Re-decoding it only to encode it again in every worker would
     * be work for nothing.
     */
    public function encodeRaw(string $event, string $data, ?string $channel = null): string
    {
        $frame = [
            'event' => $event,
            'data'  => $data,
        ];

        if ($channel !== null) {
            $frame['channel'] = $channel;
        }

        return json_encode($frame, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function encodeData(array $data): string
    {
        // An empty array must go out as {} and not as [], or the client reads it as a
        // list and the frame is malformed for it.
        if ($data === []) {
            return '{}';
        }

        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * `data` is a string with JSON in it by the protocol, but pusher-js sends a plain
     * object for its own frames. Both are accepted; anything else becomes an empty array.
     *
     * @return array<string, mixed>
     */
    private function decodeData(mixed $data): array
    {
        if (is_array($data)) {
            /** @var array<string, mixed> $data */
            return $data;
        }

        if (!is_string($data) || $data === '') {
            return [];
        }

        try {
            $decoded = json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
