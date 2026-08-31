<?php

declare(strict_types=1);

namespace SConcur\Laravel\Queue\Rabbitmq;

use Closure;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Queue\Queue as BaseQueue;
use SConcur\Features\Amqp\Channel;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\Consumer\PublishChannelPool;
use SConcur\Features\Amqp\Message;
use SConcur\Features\Amqp\RetryTopology;

/**
 * A Laravel queue over the SConcur AMQP feature.
 *
 * The wire format is deliberately not ours: body, message properties and the attempt
 * header are exactly what vladimir-yuldashev/laravel-queue-rabbitmq writes, so a job
 * published by either side is readable and runnable by the other. See the
 * compatibility table in .ai/plans/sconcur-amqp-laravel-queue.md.
 *
 * Publishing goes to the default exchange with the queue name as the routing key —
 * straight into the queue — which is what that package does with its own default
 * configuration.
 *
 * One instance of this serves every coroutine in the process: QueueManager caches a
 * queue per connection name. A channel therefore cannot be kept in a property. Its
 * commands are serialized, so sharing one would turn concurrent publishes back into a
 * line — and, worse, publisher confirms are channel-wide, so two coroutines waiting on
 * one channel read each other's answers: one job reported unroutable that published
 * fine, another reported published that the broker dropped. Every publish therefore
 * runs on a channel nobody else holds, leased from PublishChannelPool, and a publish
 * from inside a message handler uses the channel the runtime already lent that handler.
 */
class Queue extends BaseQueue implements QueueContract
{
    /** The header the attempt counter lives in, nested as `laravel.attempts`. */
    public const string ATTEMPTS_HEADER = 'laravel';

    /** Channels lent out one at a time, so no two coroutines publish on the same one. */
    protected PublishChannelPool $publishChannels;

    /**
     * The channel pop() gets its deliveries on.
     *
     * Kept rather than leased because a delivery outlives the call that fetched it: its
     * acknowledgement goes to the channel it arrived on, so that channel cannot go back
     * to the pool while the job is still running. pop() is the plain single-consumer
     * queue:work path — the coroutine pool takes its deliveries through ConsumerRunner
     * and never comes here.
     */
    protected ?Channel $popChannel = null;

    public function __construct(
        protected Connection $connection,
        protected string $default = 'default',
        protected bool $confirmPublishes = false,
        protected float $confirmTimeoutSeconds = 5.0,
    ) {
        // Built here rather than on first use: a lazy `??=` on an object every coroutine
        // shares is a check and an assignment with a gap between them, and two first
        // publishes in the same slice would each build a pool and one would be dropped
        // with its connection. The constructor opens nothing — the pool dials only when a
        // lease finds no free channel — so there is nothing to defer.
        $this->publishChannels = new PublishChannelPool($connection->options);
    }

    public function size($queue = null): int
    {
        // On a leased channel like everything else, and for one reason beyond the
        // shared-state one: the broker answers declarePassive() for a queue that does
        // not exist by closing the channel. On a shared channel a single queue:size
        // against a typo took publishing down for every coroutine in the process.
        return $this->onChannel(
            fn(Channel $channel): int => $channel->queue($this->getQueue($queue))->declarePassive()->messageCount,
        );
    }

    public function push($job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data),
            $queue,
            null,
            fn(string $payload, ?string $queue): mixed => $this->pushRaw($payload, $queue),
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    public function pushRaw($payload, $queue = null, array $options = []): mixed
    {
        $attempts = (int) ($options['attempts'] ?? 0);

        $this->publish(
            queue: $this->getQueue($queue),
            payload: $payload,
            attempts: $attempts,
            delayMs: 0,
        );

        return $this->correlationId($payload);
    }

    public function later($delay, $job, $data = '', $queue = null): mixed
    {
        return $this->enqueueUsing(
            $job,
            $this->createPayload($job, $this->getQueue($queue), $data, $delay),
            $queue,
            $delay,
            fn(string $payload, ?string $queue, mixed $delay): mixed => $this->laterRaw($delay, $payload, $queue),
        );
    }

    /**
     * The wait queue for the exact delay is declared on the spot, so any delay is served
     * as asked for.
     */
    public function laterRaw(
        mixed $delay,
        string $payload,
        ?string $queue = null,
        int $attempts = 0,
        ?Channel $channel = null,
    ): mixed {
        $this->publish(
            queue: $this->getQueue($queue),
            payload: $payload,
            attempts: $attempts,
            delayMs: $this->delayMsFor($delay),
            channel: $channel,
        );

        return $this->correlationId($payload);
    }

    /**
     * @param iterable<mixed> $jobs
     */
    public function bulk($jobs, $data = '', $queue = null): void
    {
        foreach ($jobs as $job) {
            $this->push($job, $data, $queue);
        }
    }

    /**
     * An honest basic.get. It is what makes queue:size and a plain queue:work usable in
     * development; the concurrency win is on the consumer pool, not here.
     */
    public function pop($queue = null): ?Job
    {
        $name = $this->getQueue($queue);

        $delivery = $this->popChannel()->queue($name)->get();

        if ($delivery === null) {
            return null;
        }

        return new Job(
            container: $this->container,
            rabbitmq: $this,
            delivery: $delivery,
            connectionName: $this->connectionName,
            queue: $name,
        );
    }

    /**
     * Publish a raw payload, keeping the attempt counter where the other package's
     * consumer reads it.
     *
     * @param null|Channel $channel a channel the caller already holds alone — what a
     *                              message handler passes, since the consumer runtime
     *                              lends it one for exactly this. Without it the publish
     *                              leases one for the length of the call.
     */
    public function publish(
        string $queue,
        string $payload,
        int $attempts,
        int $delayMs,
        ?Channel $channel = null,
    ): void {
        $message = new Message(
            body: $payload,
            contentType: 'application/json',
            persistent: true,
            correlationId: $this->correlationId($payload),
            headers: [self::ATTEMPTS_HEADER => ['attempts' => $attempts]],
        );

        if ($channel !== null) {
            $this->publishOn($channel, $queue, $message, $delayMs);

            return;
        }

        $this->onChannel(
            fn(Channel $on): null => $this->publishOn($on, $queue, $message, $delayMs),
        );
    }

    public function getConnection(): Connection
    {
        return $this->connection;
    }

    public function getQueue(mixed $queue = null): string
    {
        return (string) ($queue ?: $this->default);
    }

    /**
     * The publish itself, on the channel it was given.
     *
     * A delayed publish is always confirmed, whatever the connection asked for. It
     * addresses a wait queue rather than the queue itself, and a wait queue is the easy
     * one to forget to declare: an unconfirmed publish to a routing key nothing is bound
     * to is dropped by the broker without a word, so a job whose handler released it
     * would disappear. Confirmed publishing is mandatory by default, so the same case
     * throws UnroutableMessageException instead.
     *
     * That is also the second reason the channel must be the caller's own: enableConfirms
     * puts a channel into confirm mode for good, and a wait collects every answer the
     * channel holds, whoever published it.
     */
    protected function publishOn(Channel $channel, string $queue, Message $message, int $delayMs): null
    {
        if ($delayMs > 0) {
            $this->declareWaitQueue($channel, $queue, $delayMs);
        }

        $amqpQueue = $channel->queue($queue);

        if ($delayMs > 0 || $this->confirmPublishes) {
            $amqpQueue->publishConfirmed(
                message: $message,
                timeoutSeconds: $this->confirmTimeoutSeconds,
                delayMs: $delayMs,
            );

            return null;
        }

        $amqpQueue->publish($message);

        return null;
    }

    /**
     * Declares the wait queue a delayed publish is addressed to, immediately before
     * publishing into it.
     *
     * On demand rather than from a declared ladder, which is what
     * vladimir-yuldashev/laravel-queue-rabbitmq does and what makes an arbitrary delay
     * work: a ladder can only serve the rungs someone thought of, so a release(3) waited
     * five seconds and a release(600) waited three hundred — a backoff silently shortened
     * to whatever the topology happened to offer.
     *
     * The queue holds the message for exactly its delay and dead-letters it back. Nothing
     * has to clean it up: x-expires tells the broker to drop a wait queue that has gone
     * unused for twice its delay, and redeclaring it here on every retry is what keeps a
     * queue still in use alive.
     *
     * A queue is only unused when it has no consumers and nothing declares it — which a
     * wait queue never has and this always does while retries are happening.
     */
    protected function declareWaitQueue(Channel $channel, string $queue, int $delayMs): void
    {
        $channel->queue(RetryTopology::waitQueueName(queue: $queue, delayMs: $delayMs))->declare(
            durable: true,
            arguments: [
                'x-message-ttl'             => $delayMs,
                'x-dead-letter-exchange'    => '',
                'x-dead-letter-routing-key' => $queue,
                'x-expires'                 => $delayMs * 2,
            ],
        );
    }

    /**
     * Runs the callback on a channel nobody else holds, and gives it back afterwards.
     *
     * Leased rather than opened: a channel is a handle and a round trip, so opening one
     * per publish would pay for the guarantee twice over. The pool keeps a channel warm
     * between publishes and gives it up once nothing has wanted it for a while.
     *
     * @template TReturn
     *
     * @param Closure(Channel): TReturn $work
     *
     * @return TReturn
     */
    protected function onChannel(Closure $work): mixed
    {
        $channel = $this->lendChannel();

        try {
            return $work($channel);
        } finally {
            $this->returnChannel($channel);
        }
    }

    protected function lendChannel(): Channel
    {
        return $this->publishChannels->lease();
    }

    protected function returnChannel(Channel $channel): void
    {
        $this->publishChannels->release($channel);
    }

    /**
     * The channel pop() reads on, opened once.
     *
     * Unlike the pool this is a check followed by an assignment across a broker round
     * trip, which two coroutines could both enter. It is left that way deliberately:
     * pop() is the single-consumer queue:work path, where a second coroutine would
     * already be interleaving basic.get and ack on one channel — the concurrency this
     * driver answers with the consumer pool, not with pop().
     */
    protected function popChannel(): Channel
    {
        if ($this->popChannel === null || !$this->popChannel->isOpen()) {
            $this->popChannel = $this->connection->channel();
        }

        return $this->popChannel;
    }

    /** The delay as asked for, in milliseconds; anything at or below zero is no delay. */
    protected function delayMsFor(mixed $delay): int
    {
        return max(0, $this->secondsUntil($delay) * 1000);
    }

    protected function correlationId(string $payload): ?string
    {
        $decoded = json_decode($payload, true);

        if (!is_array($decoded)) {
            return null;
        }

        $id = $decoded['id'] ?? null;

        return is_string($id) ? $id : null;
    }
}
