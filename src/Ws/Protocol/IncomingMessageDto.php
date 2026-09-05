<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Protocol;

/**
 * One decoded frame from the client.
 *
 * `data` is always an array here, whatever the client sent: the protocol allows both a
 * nested object and a string carrying JSON, and the difference is the codec's problem,
 * not the handler's.
 */
readonly class IncomingMessageDto
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $event,
        public array $data,
        public ?string $channel = null,
    ) {
    }

    public function stringField(string $name): ?string
    {
        $value = $this->data[$name] ?? null;

        return is_string($value) ? $value : null;
    }

    /** The channel the frame is about: the top-level field, or the one inside `data`. */
    public function channelName(): ?string
    {
        return $this->channel ?? $this->stringField('channel');
    }
}
