<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

/**
 * Every connection this process holds, and who is subscribed to what.
 *
 * Two indexes rather than one, because both directions are on a hot path: a delivery off
 * the bus needs the subscribers of a channel, and a closing connection needs its own
 * channels. Keeping only the first would make every disconnect a scan of every channel.
 *
 * Emptiness is not a detail of this class — it is what the bus subscriber's lifetime is
 * tied to, so that a stopping worker has nothing left running to wait for. See
 * BusSubscriber.
 */
class ConnectionRegistry
{
    /** @var array<string, ConnectionState> socket id => connection */
    private array $connections = [];

    /** @var array<string, array<string, true>> channel => set of socket ids */
    private array $channels = [];

    public function add(ConnectionState $state): void
    {
        $this->connections[$state->socketId] = $state;
    }

    /** Drops the connection and every subscription it held. */
    public function forget(string $socketId): void
    {
        $state = $this->connections[$socketId] ?? null;

        if ($state === null) {
            return;
        }

        foreach ($state->channels() as $channel) {
            $this->unsubscribe(
                socketId: $socketId,
                channel: $channel,
            );
        }

        unset($this->connections[$socketId]);
    }

    public function get(string $socketId): ?ConnectionState
    {
        return $this->connections[$socketId] ?? null;
    }

    public function subscribe(string $socketId, string $channel, ?string $channelData = null): void
    {
        $state = $this->connections[$socketId] ?? null;

        if ($state === null) {
            return;
        }

        $state->subscribe(
            channel: $channel,
            channelData: $channelData,
        );

        $this->channels[$channel][$socketId] = true;
    }

    public function unsubscribe(string $socketId, string $channel): void
    {
        ($this->connections[$socketId] ?? null)?->unsubscribe($channel);

        unset($this->channels[$channel][$socketId]);

        if (($this->channels[$channel] ?? []) === []) {
            unset($this->channels[$channel]);
        }
    }

    /**
     * The connections to write a channel's message to, minus the one that sent it when
     * the message carries an excluded socket (`toOthers`, and a client event never
     * echoing back to its author).
     *
     * @return list<ConnectionState>
     */
    public function subscribers(string $channel, ?string $exceptSocketId = null): array
    {
        $subscribers = [];

        foreach (array_keys($this->channels[$channel] ?? []) as $socketId) {
            if ($socketId === $exceptSocketId) {
                continue;
            }

            $state = $this->connections[$socketId] ?? null;

            if ($state === null) {
                continue;
            }

            $subscribers[] = $state;
        }

        return $subscribers;
    }

    /**
     * @return list<string>
     */
    public function channelSocketIds(string $channel): array
    {
        return array_keys($this->channels[$channel] ?? []);
    }

    public function isEmpty(): bool
    {
        return $this->connections === [];
    }

    public function count(): int
    {
        return count($this->connections);
    }
}
