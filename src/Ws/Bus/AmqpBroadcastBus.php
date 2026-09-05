<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Bus;

use Closure;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Features\Amqp\Queue;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\Laravel\Ws\WsBusOptions;
use Throwable;

/**
 * The bus every worker of a pool shares: a fanout exchange, and one queue per ws worker
 * bound to it. A publish reaches every worker, and each of them decides which of its own
 * connections the message belongs to.
 *
 * Two properties of that queue are load bearing:
 *
 * - `exclusive` ties it to this worker's connection to the broker, so a dead worker takes
 *   its queue with it and nothing accumulates;
 * - `autoDelete` is off, and that is not an oversight. The subscriber leaves the consumer
 *   generator on every idle wake to re-check whether it is still needed, and leaving it
 *   cancels the consumer. With autoDelete the broker would drop the queue in that gap,
 *   and the next consume would take the channel down with a 404.
 *
 * Deliveries are auto-acknowledged. A broadcast is a notification, not a job: a worker
 * that was away for a second should miss the event, not be handed a minute of them at
 * once. An application that needs delivery guarantees needs a queue instead.
 */
class AmqpBroadcastBus implements BroadcastBusInterface
{
    /** Connection used by publishers, opened on the first publish and kept. */
    private ?Connection $publisherConnection = null;

    private ?Channel $publisherChannel = null;

    /**
     * @param null|Closure(string): void $logger
     */
    public function __construct(
        private readonly WsBusOptions $options,
        private readonly ?Closure $logger = null,
    ) {
    }

    public function publish(BroadcastMessageDto $message): void
    {
        try {
            $this->publisherChannel()
                ->exchange($this->options->exchange)
                ->publish($message->toJson());
        } catch (Throwable $exception) {
            // A broken publisher channel must not survive as a broken one: drop it so the
            // next publish dials again instead of failing for the life of the process.
            $this->closePublisher();

            throw $exception;
        }
    }

    public function subscribe(Closure $handler, Closure $shouldContinue): void
    {
        $connection = null;

        $queue = null;

        try {
            while ($shouldContinue()) {
                try {
                    $connection ??= new Connection($this->subscriberOptions());

                    $queue ??= $this->declareSubscriberQueue($connection->channel());

                    foreach ($queue->consume(autoAck: true) as $delivery) {
                        $message = BroadcastMessageDto::fromJson($delivery->body);

                        if ($message !== null) {
                            $handler($message);
                        }

                        if ($this->shouldStop($shouldContinue)) {
                            return;
                        }
                    }

                    // The stream ended quietly, which happens only when this coroutine's
                    // flow is stopped — the server is going down and so are we.
                    return;
                } catch (AmqpException $exception) {
                    if ($this->isIdleTimeout($exception)) {
                        // Not an ending: the queue was simply silent for readTimeoutSeconds.
                        // Loop back, re-check the caller's condition, reopen the consumer.
                        continue;
                    }

                    $this->log('subscriber failed, reopening: ' . $exception->getMessage());

                    // The connection goes too, not only the channel. One the library has
                    // marked dead never comes back on its own — ensureOpen() throws for as
                    // long as it still holds its handle, by design — so keeping it here
                    // would spin on that exception for the life of the worker, and this
                    // worker's clients would never see another broadcast.
                    $this->closeQuietly($connection);

                    $connection = null;

                    $queue = null;

                    Sleeper::usleep($this->options->reopenBackoffMs * 1000);
                }
            }
        } finally {
            $this->closeQuietly($connection);
        }
    }

    public function needsCoroutine(): bool
    {
        return true;
    }

    /**
     * Whether the consumer merely outwaited readTimeoutSeconds. The library says this one
     * apart from a real ending in its message and nowhere else — there is no exception
     * class for it — so this is a substring test, kept in one place rather than spread
     * through the loop.
     *
     * Matched on the consumer's own wording rather than on the word "timeout": a command
     * that outran the rpc deadline says "command timeout exceeded" and a wait says "wait
     * timeout exceeded", and neither of those is idleness. Read as one, a real failure
     * would go round the loop with no pause and with the dead handle still held.
     */
    protected function isIdleTimeout(AmqpException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'consumer timeout');
    }

    /**
     * The caller's condition, asked between deliveries. Wrapped rather than called
     * inline: the loop above is entered on the same condition, and reading it through
     * one place keeps the delivery loop's exit next to the reason for it.
     *
     * @param Closure(): bool $shouldContinue
     */
    private function shouldStop(Closure $shouldContinue): bool
    {
        return !$shouldContinue();
    }

    /**
     * This worker's own queue: named by the broker, bound to the fanout exchange, and
     * alive for exactly as long as this connection.
     */
    private function declareSubscriberQueue(Channel $channel): Queue
    {
        $channel->exchange($this->options->exchange)->declare(type: ExchangeTypeEnum::Fanout);

        $queue = $channel->queue('');

        $queue->declare(
            durable: false,
            exclusive: true,
            autoDelete: false,
        );

        $queue->bind($this->options->exchange);

        $this->log('subscribed as ' . $queue->name() . ' on ' . $this->options->exchange);

        return $queue;
    }

    private function publisherChannel(): Channel
    {
        if ($this->publisherChannel !== null) {
            return $this->publisherChannel;
        }

        // Built in locals and published to the object only once both have succeeded.
        // Opening a channel and declaring an exchange are two suspension points, and a
        // second coroutine entering that window used to overwrite the connection while the
        // first still held the channel opened on it — leaving that channel owned by
        // nobody, closed by the destructor, and every later publish failing on a handle
        // this object still advertised.
        $connection = new Connection($this->connectionOptions());

        $channel = $connection->channel();

        // Declared by the publisher too: the exchange belongs to the package rather than
        // to the application, so there is no declare command to run first.
        $channel->exchange($this->options->exchange)->declare(type: ExchangeTypeEnum::Fanout);

        $this->publisherConnection = $connection;

        return $this->publisherChannel = $channel;
    }

    private function closePublisher(): void
    {
        $connection = $this->publisherConnection;

        // Dropped before the close, which suspends: a coroutine arriving in that window
        // must build a connection of its own rather than take the one being torn down.
        $this->publisherChannel = null;

        $this->publisherConnection = null;

        $this->closeQuietly($connection);
    }

    private function connectionOptions(): ConnectionOptions
    {
        return ConnectionOptions::fromDsn($this->options->dsn);
    }

    /**
     * The publisher's options plus the read timeout that gives the subscriber its
     * heartbeat. It cannot come from the DSN — the AMQP URI has no parameter for it.
     */
    private function subscriberOptions(): ConnectionOptions
    {
        $options = $this->connectionOptions();

        return new ConnectionOptions(
            host: $options->host,
            port: $options->port,
            login: $options->login,
            password: $options->password,
            vhost: $options->vhost,
            connectTimeoutSeconds: $options->connectTimeoutSeconds,
            readTimeoutSeconds: $this->options->readTimeoutSeconds,
            writeTimeoutSeconds: $options->writeTimeoutSeconds,
            rpcTimeoutSeconds: $options->rpcTimeoutSeconds,
            heartbeatSeconds: $options->heartbeatSeconds,
            channelMax: $options->channelMax,
            frameMaxBytes: $options->frameMaxBytes,
            tls: $options->tls,
            saslMethod: $options->saslMethod,
            connectionName: $options->connectionName,
        );
    }

    /** Hands a connection back where there is nothing useful to do about a failure. */
    private function closeQuietly(?Connection $connection): void
    {
        try {
            $connection?->close();
        } catch (Throwable) {
            // Already gone — a teardown is no place to fail.
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
