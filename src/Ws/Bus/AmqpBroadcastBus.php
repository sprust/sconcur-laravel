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
use SConcur\Features\FeatureExecutor;
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
 *
 * The publishing side keeps one connection and one channel for the life of the process,
 * because a process broadcasts in bursts and a channel per message would be a dial per
 * message. Two rules keep that channel from turning into a failure: it is given up before
 * it is handed out once it is closed or has been idle longer than
 * MAX_PUBLISHER_IDLE_SECONDS, and a publish that fails on a kept channel is tried once
 * more on a channel that is there. A publish that opened its own channel is not tried
 * again — what it failed on is the broker's answer, and a second attempt arrives at the
 * same one.
 *
 * A retried publish can reach the broker twice, because a command that failed on its way
 * out may still have been carried out. A broadcast is therefore delivered at least once
 * rather than exactly once, which for a notification is the cheaper side of the trade: the
 * alternative is the silence the retry exists to prevent.
 */
class AmqpBroadcastBus implements BroadcastBusInterface
{
    /**
     * How long the kept publisher may sit unused before the next publish dials again.
     *
     * The extension collects a channel with no consumers that has run no command for half an
     * hour, and this side is never told: the handle still looks open, and the publish that
     * ends a long silence fails on a channel that is not there any more. Comfortably under
     * that half hour, the same margin SConcur's own PublishChannelPool keeps.
     *
     * Not a setting: the number follows the extension's collector rather than anything an
     * application knows about.
     */
    protected const float MAX_PUBLISHER_IDLE_SECONDS = 600.0;

    /** Connection used by publishers, opened on the first publish and kept. */
    private ?Connection $publisherConnection = null;

    private ?Channel $publisherChannel = null;

    /** When the kept channel was last handed to a publish, as microtime(true). */
    private float $publisherUsedAt = 0.0;

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
        // Asked for first, so that the one decision this method makes — whether a failure
        // is worth a second attempt — is made about the very channel the publish runs on.
        // Read as two separate questions it would be neither: every call between them is a
        // point the runtime can switch coroutines at, and the publisher is shared by all of
        // them.
        $keptChannel = $this->keptPublisherChannel();

        if ($keptChannel === null) {
            // Nothing worth keeping was there, so this publish opens a channel of its own.
            // What a channel just opened fails on is the broker's answer, and a second
            // attempt arrives at the same one.
            $this->publishOn($this->openPublisher(), $message);

            return;
        }

        try {
            $this->publishOn($keptChannel, $message);
        } catch (AmqpException $exception) {
            // A kept channel can go away with nothing said to this side — collected by the
            // extension, or taken down with its connection — and the command that fails on
            // it is how this side finds out. publishOn() has given that channel up, so the
            // attempt below takes one that is there.
            //
            // Only what the broker or the channel failed with. A deliberate unwind
            // (FlowStoppedException) and a coroutine that ran out of time reach past this
            // on purpose: there is no flow left to dial on, and a second attempt would
            // spend it on a connection nothing can be awaited over.
            $this->log('publisher failed, dialling again: ' . $exception->getMessage());

            $this->publishOn($this->publisherChannel(), $message);
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

    /** Dials a connection of the publisher's own, opens a channel on it and keeps both. */
    protected function openPublisher(): Channel
    {
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

        $this->publisherUsedAt = microtime(true);

        return $this->publisherChannel = $channel;
    }

    /**
     * One publish on one channel, with that channel given up when it fails.
     *
     * Protected for the tests: a publish on a channel the caller holds is what a coroutine
     * that came late to a channel everybody was publishing on does, and there is no other
     * way to stage that from a single-threaded test.
     */
    protected function publishOn(Channel $channel, BroadcastMessageDto $message): void
    {
        try {
            $channel->exchange($this->options->exchange)->publish($message->toJson());
        } catch (Throwable $exception) {
            // A broken publisher channel must not survive as a broken one: give it up so
            // the next publish dials again instead of failing for the life of the process.
            $this->closePublisher($channel);

            throw $exception;
        }
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
        return $this->keptPublisherChannel() ?? $this->openPublisher();
    }

    /**
     * The kept channel while it is still worth handing out, null when there is none or the
     * one there is has sat idle too long — which also gives that one up.
     *
     * The channel is not asked whether it is open. isOpen() reports what this side believes,
     * and this side's belief is exactly what is wrong in the failure this guards against: a
     * collected channel goes on looking open here. The one thing the question would catch —
     * a connection this side has already marked failed — is given up by the publish that
     * marked it, inside the same catch, so there is nothing left for it to find.
     */
    private function keptPublisherChannel(): ?Channel
    {
        $channel = $this->publisherChannel;

        if ($channel === null) {
            return null;
        }

        if (!$this->outlivedIdleLimit()) {
            // Marked as it goes out rather than when the command it is going out for
            // finishes: a publish that takes a while then makes the channel look older than
            // it is, and being early is the safe direction to be wrong in.
            $this->publisherUsedAt = microtime(true);

            return $channel;
        }

        $this->closePublisher($channel);

        return null;
    }

    /**
     * Whether the kept channel has been idle long enough for the extension to have collected
     * it. Read on the way in, because this side is told nothing when it happens.
     */
    private function outlivedIdleLimit(): bool
    {
        return (microtime(true) - $this->publisherUsedAt) >= static::MAX_PUBLISHER_IDLE_SECONDS;
    }

    /**
     * Gives up one publisher channel, and only while it is still the one this object holds.
     *
     * The channel is shared by every coroutine publishing at the time, so one that dies is
     * met by all of them, one after another. The first gives it up and dials again; the ones
     * behind it arrive with their own failure on a channel that belongs to nobody any more,
     * and a teardown that looked only at the fields would close the connection another
     * coroutine had just opened and is publishing on — turning one dead channel into a dial
     * per publisher, all but one of them orphaned. Keyed by the object, the way SConcur's own
     * PublishChannelPool keys everything it tracks.
     */
    private function closePublisher(Channel $channel): void
    {
        if ($this->publisherChannel !== $channel) {
            return;
        }

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
        if ($connection === null) {
            return;
        }

        // An unwound coroutine has nothing to await an answer on, and asking would park it
        // for good. Dropping the object is enough there: a Connection hands its handle back
        // from its destructor, detached — the same release without the wait.
        if (!FeatureExecutor::canAwait()) {
            return;
        }

        try {
            $connection->close();
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
