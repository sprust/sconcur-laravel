<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

use SConcur\Features\WsServer\Dto\Connection;

/**
 * One live client: the connection itself, its socket id, and what it is subscribed to.
 *
 * The channel data of a presence subscription is kept beside the channel because leaving
 * the channel needs it again — the member to announce as gone is the one this connection
 * announced as arrived, and asking the store for it later is a lookup that can miss.
 */
class ConnectionState
{
    /** @var array<string, ?string> channel name => the channel data it subscribed with */
    private array $channels = [];

    /** Start of the current client-event window, as a unix timestamp. */
    private int $clientEventWindowStartedAt = 0;

    private int $clientEventsInWindow = 0;

    public function __construct(
        public readonly string $socketId,
        public readonly Connection $connection,
    ) {
    }

    public function subscribe(string $channel, ?string $channelData = null): void
    {
        $this->channels[$channel] = $channelData;
    }

    public function unsubscribe(string $channel): void
    {
        unset($this->channels[$channel]);
    }

    public function hasChannel(string $channel): bool
    {
        return array_key_exists($channel, $this->channels);
    }

    public function channelData(string $channel): ?string
    {
        return $this->channels[$channel] ?? null;
    }

    /**
     * @return list<string>
     */
    public function channels(): array
    {
        return array_keys($this->channels);
    }

    public function channelCount(): int
    {
        return count($this->channels);
    }

    /**
     * Whether one more client event fits into this minute. A fixed window rather than a
     * sliding one: the point is to stop a runaway client, not to meter it precisely.
     */
    public function allowClientEvent(int $limitPerMinute): bool
    {
        if ($limitPerMinute <= 0) {
            return true;
        }

        $now = time();

        if (($now - $this->clientEventWindowStartedAt) >= 60) {
            $this->clientEventWindowStartedAt = $now;

            $this->clientEventsInWindow = 0;
        }

        if ($this->clientEventsInWindow >= $limitPerMinute) {
            return false;
        }

        ++$this->clientEventsInWindow;

        return true;
    }
}
