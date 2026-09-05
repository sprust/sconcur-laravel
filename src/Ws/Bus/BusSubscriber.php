<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Bus;

use Closure;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\Protocol\MessageCodec;
use SConcur\Scheduler\Scheduler;
use Throwable;

/**
 * Runs the bus inside the ws worker and lays what arrives over the local registry.
 *
 * Its lifetime is the interesting part. The server's shutdown does not end when the
 * connections do: Scheduler::serve stops accepting and then waits for every spawned
 * coroutine to finish, so a subscriber looping for ever would hold the drain open until
 * the master's shutdown timeout ran out and killed the process. Measured, not assumed —
 * the drain diagnostic names the coroutine it is waiting for.
 *
 * So the subscriber lives exactly as long as there is someone to deliver to: the first
 * connection starts it, and it stands down once the registry is empty. On a silent bus
 * that check comes around every readTimeoutSeconds, which is what bounds the shutdown.
 */
class BusSubscriber
{
    private bool $running = false;

    /**
     * @param null|Closure(string): void $logger
     */
    public function __construct(
        private readonly BroadcastBusInterface $bus,
        private readonly ConnectionRegistry $registry,
        private readonly MessageCodec $codec,
        private readonly ?Closure $logger = null,
    ) {
    }

    /**
     * Subscribes a bus that has no loop of its own. Called on the way into serve(), where
     * there is no coroutine yet and none is needed.
     */
    public function boot(): void
    {
        if ($this->bus->needsCoroutine()) {
            return;
        }

        $this->bus->subscribe(
            handler: $this->fanOut(...),
            shouldContinue: static fn(): bool => true,
        );
    }

    /**
     * Starts the delivery coroutine if it is not already up. Called by a connection as it
     * registers, which is what ties the subscriber's life to the registry's.
     */
    public function ensureRunning(): void
    {
        if ($this->running || !$this->bus->needsCoroutine()) {
            return;
        }

        $this->running = true;

        Scheduler::get()->spawn(function (): void {
            try {
                $this->bus->subscribe(
                    handler: $this->fanOut(...),
                    shouldContinue: fn(): bool => !$this->registry->isEmpty(),
                );
            } catch (Throwable $exception) {
                $this->log('sconcur ws bus: subscriber stopped: ' . $exception->getMessage());
            } finally {
                // Whatever ended it, the next connection is free to start a new one.
                $this->running = false;
            }
        });
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /**
     * Writes one broadcast to every local connection subscribed to its channels, minus
     * the excluded socket.
     *
     * A write to a client that has already gone is expected rather than exceptional —
     * the close and the delivery cross — so it is swallowed per connection: one dead
     * client must not cost the others their message.
     */
    public function fanOut(BroadcastMessageDto $message): void
    {
        foreach ($message->channels as $channel) {
            $frame = $this->codec->encodeRaw(
                event: $message->event,
                data: $message->data,
                channel: $channel,
            );

            foreach ($this->registry->subscribers(channel: $channel, exceptSocketId: $message->socket) as $state) {
                try {
                    $state->connection->write($frame);
                } catch (Throwable) {
                    // Gone between the lookup and the write; its handler will clean up.
                }
            }
        }
    }

    private function log(string $line): void
    {
        if ($this->logger === null) {
            return;
        }

        ($this->logger)($line);
    }
}
