<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Exceptions\Amqp\AmqpException;
use SConcur\Features\Amqp\Connection;
use SConcur\Features\Amqp\ConnectionOptions;
use SConcur\Features\Amqp\ExchangeTypeEnum;
use SConcur\Features\Amqp\Queue;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\ImpatientPublisherBus;
use SConcur\Laravel\Tests\Feature\Ws\Support\RecordingPublisherBus;
use SConcur\Laravel\Tests\Feature\Ws\Support\UndialableBus;
use SConcur\Laravel\Ws\Bus\AmqpBroadcastBus;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;
use SConcur\Laravel\Ws\WsBusOptions;
use Throwable;

/**
 * Integration test against the live broker the compose file raises.
 *
 * The publish is made from inside shouldContinue(), on the second turn of the subscriber
 * loop — which means it lands after the first idle wake has already cancelled and
 * reopened the consumer. That is the point of the test as much as the delivery is: with
 * an auto-deleting queue the broker drops it in exactly that gap, and the reopen fails
 * with a 404 that takes the channel down with it.
 */
class AmqpBroadcastBusTest extends BaseTestCase
{
    /** Guards the loop against hanging the suite if nothing is ever delivered. */
    private const int MAX_TURNS = 8;

    /** How long a read of the test's own queue waits for what a publish put on the wire. */
    private const float READ_BUDGET_SECONDS = 5.0;

    /** The pause between two of those reads, so a publish has a moment to be routed. */
    private const int READ_PAUSE_US = 25_000;

    private string $exchange;

    /** Held open for as long as the queue the test reads its messages back from. */
    private ?Connection $readerConnection = null;

    /** The queue itself, deleted by name rather than left to the connection it lives on. */
    private ?Queue $readerQueue = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exchange = 'sconcur-ws-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        // Deleted rather than left to the connection under it. The queue is exclusive, but
        // the extension pools a connection by its options, so the socket this one closes is
        // the socket the bus published over — and the queue would outlive the test for as
        // long as the pool keeps it.
        $this->readerQueue?->delete();

        $this->readerConnection?->close();

        $this->readerQueue = null;

        $this->readerConnection = null;

        $connection = new Connection(ConnectionOptions::fromDsn($this->dsn()));

        $connection->channel()->exchange($this->exchange)->delete();

        $connection->close();

        parent::tearDown();
    }

    #[Test]
    public function itDeliversAPublishedMessageAfterAnIdleWake(): void
    {
        $publisher = $this->bus();

        $subscriber = $this->bus();

        /** @var list<BroadcastMessageDto> $received */
        $received = [];

        $turns = 0;

        $subscriber->subscribe(
            handler: static function (BroadcastMessageDto $message) use (&$received): void {
                $received[] = $message;
            },
            shouldContinue: function () use (&$received, &$turns, $publisher): bool {
                ++$turns;

                if ($received !== []) {
                    return false;
                }

                if ($turns > self::MAX_TURNS) {
                    return false;
                }

                // The second turn is reached only through an idle wake, so the queue has
                // already outlived one cancelled consumer by the time this publishes.
                if ($turns === 2) {
                    $publisher->publish(new BroadcastMessageDto(
                        channels: ['private-orders.7'],
                        event: 'OrderShipped',
                        data: '{"id":7}',
                        socket: '1.1',
                    ));
                }

                return true;
            },
        );

        self::assertCount(1, $received);
        self::assertSame(['private-orders.7'], $received[0]->channels);
        self::assertSame('OrderShipped', $received[0]->event);
        self::assertSame('{"id":7}', $received[0]->data);
        self::assertSame('1.1', $received[0]->socket);
    }

    /** A frame someone else published must not take the worker down. */
    #[Test]
    public function itIgnoresAMessageItCannotDecode(): void
    {
        $delivered = 0;

        $turns = 0;

        $this->bus()->subscribe(
            handler: static function () use (&$delivered): void {
                ++$delivered;
            },
            shouldContinue: function () use (&$turns): bool {
                ++$turns;

                if ($turns === 2) {
                    $this->publishRaw('not a broadcast at all');
                }

                return $turns <= 3;
            },
        );

        self::assertSame(0, $delivered);
    }

    /**
     * A broker that cannot be reached is retried, not spun on and not fatal.
     *
     * The path matters more than it looks: a connection the library has marked dead never
     * comes back on its own, so the loop has to drop it and dial a new one. Keeping it
     * used to leave the worker throwing the same exception for the rest of its life, with
     * every one of its clients silently cut off from broadcasts.
     */
    #[Test]
    public function anUnreachableBrokerIsRetriedAndThenGivenUpOn(): void
    {
        $bus = new AmqpBroadcastBus(
            options: new WsBusOptions(
                dsn: 'amqp://nobody:nobody@127.0.0.1:5699/%2f',
                exchange: $this->exchange,
                readTimeoutSeconds: 1.0,
                reopenBackoffMs: 10,
            ),
        );

        $turns = 0;

        $bus->subscribe(
            handler: static function (): void {
                self::fail('nothing can be delivered by a broker that is not there');
            },
            shouldContinue: static function () use (&$turns): bool {
                return ++$turns <= 3;
            },
        );

        // It asked the condition again after each failure rather than throwing out.
        self::assertSame(4, $turns);
    }

    /** A publish that fails drops the channel, so the next one dials again. */
    #[Test]
    public function aFailedPublishDoesNotPoisonThePublisher(): void
    {
        $bus = new AmqpBroadcastBus(
            options: new WsBusOptions(
                dsn: 'amqp://nobody:nobody@127.0.0.1:5699/%2f',
                exchange: $this->exchange,
            ),
        );

        $refused = 0;

        foreach ([1, 2] as $attempt) {
            try {
                $bus->publish(new BroadcastMessageDto(channels: ['demo'], event: 'E', data: '{}'));

                self::fail('attempt ' . $attempt . ' should not have reached a broker');
            } catch (Throwable) {
                ++$refused;
            }
        }

        // The second attempt behaved like the first rather than failing on a handle the
        // first one left behind.
        self::assertSame(2, $refused);
    }

    /**
     * A publisher's kept channel can go away with nothing said to this side: the extension
     * collects a channel with no consumers that has run no command for half an hour, and the
     * handle here goes on looking open. The publish that ends a long silence is the one that
     * finds out, and without a second attempt that broadcast is simply lost.
     *
     * Closing the channel behind the bus leaves exactly that state, and leaves it in a moment
     * rather than in half an hour: the bus holds a channel that is not there any more, and the
     * command on it comes back with "No channel available."
     */
    #[Test]
    public function aPublishOnAChannelThatWentAwayIsRetriedOnAFreshOne(): void
    {
        $bus = new RecordingPublisherBus(options: $this->busOptions());

        $queue = $this->boundQueue();

        $bus->publish($this->message('BeforeTheSilence'));

        self::assertCount(1, $bus->openedChannels);

        $bus->openedChannels[0]->close();

        $bus->publish($this->message('AfterTheSilence'));

        // It dialled again instead of giving up, and the message went out on what it
        // dialled — which is the whole point: the frame is delivered, not merely retried.
        self::assertCount(2, $bus->openedChannels);
        self::assertSame(
            ['BeforeTheSilence', 'AfterTheSilence'],
            $this->deliveredEvents(queue: $queue, expected: 2),
        );
    }

    /**
     * The kept channel is given up once it has been idle longer than the limit, and it is
     * given up on the way in rather than after a failed publish: a collected channel costs
     * the caller a message and a line in the error log, and dialling before using it costs
     * neither. A channel still within the limit is kept, so an application broadcasting
     * every second does not dial every second.
     */
    #[Test]
    public function anIdlePublisherIsGivenUpAndABusyOneIsKept(): void
    {
        $keeping = new RecordingPublisherBus(options: $this->busOptions());

        $keeping->publish($this->message('First'));

        $keeping->publish($this->message('Second'));

        self::assertCount(1, $keeping->openedChannels);

        $impatient = new ImpatientPublisherBus(options: $this->busOptions());

        $impatient->publish($this->message('First'));

        $impatient->publish($this->message('Second'));

        self::assertCount(2, $impatient->openedChannels);
    }

    /**
     * The channel is one object shared by every coroutine publishing at the time, so a
     * channel that dies is met by all of them in turn. The first gives it up and dials a
     * replacement; the ones behind it arrive with their own failure on a channel that
     * belongs to nobody any more, and they must give up that one alone.
     *
     * Staged rather than raced: the publish below is handed the dead channel directly, which
     * is exactly the state a late coroutine is in, and leaves the assertion to depend on the
     * rule rather than on an interleaving.
     */
    #[Test]
    public function aLatePublisherGivesUpItsOwnChannelAndNotTheReplacement(): void
    {
        $bus = new RecordingPublisherBus(options: $this->busOptions());

        $queue = $this->boundQueue();

        $bus->publish($this->message('First'));

        $goneChannel = $bus->openedChannels[0];

        $goneChannel->close();

        $bus->publish($this->message('Second'));

        self::assertCount(2, $bus->openedChannels);

        try {
            $bus->publishOnChannel($goneChannel, $this->message('Lost'));

            self::fail('a publish on a channel that is gone cannot succeed');
        } catch (AmqpException) {
            // The late publisher's own failure, which is not what this test is about.
        }

        $bus->publish($this->message('Third'));

        // Still the channel the second publish dialled: the late one took away its own dead
        // channel and left the replacement where it was.
        self::assertCount(2, $bus->openedChannels);
        self::assertSame(
            ['First', 'Second', 'Third'],
            $this->deliveredEvents(queue: $queue, expected: 3),
        );
    }

    /**
     * The second attempt is the last one. What it fails with belongs to the caller, who is
     * the only one left able to do anything about it — unlike the first failure, which is
     * logged and then answered with a fresh channel.
     */
    #[Test]
    public function aSecondAttemptThatCannotDialFailsTheCaller(): void
    {
        $bus = new UndialableBus(options: $this->busOptions());

        $bus->publish($this->message('First'));

        $bus->openedChannels[0]->close();

        $bus->dialsRefused = true;

        $this->expectException(AmqpException::class);

        $bus->publish($this->message('Second'));
    }

    private function bus(): AmqpBroadcastBus
    {
        return new AmqpBroadcastBus(options: $this->busOptions());
    }

    private function busOptions(): WsBusOptions
    {
        return new WsBusOptions(
            dsn: $this->dsn(),
            exchange: $this->exchange,
            // Short, so the idle wake the subscriber tests rely on comes round quickly.
            readTimeoutSeconds: 1.0,
            reopenBackoffMs: 100,
        );
    }

    private function message(string $event): BroadcastMessageDto
    {
        return new BroadcastMessageDto(
            channels: ['private-orders.7'],
            event: $event,
            data: '{"id":7}',
        );
    }

    /**
     * A queue of the test's own on the bus exchange, so what a publish put on the wire can be
     * read back. It lives as long as the connection under it, which tearDown closes.
     */
    private function boundQueue(): Queue
    {
        $this->readerConnection = new Connection(ConnectionOptions::fromDsn($this->dsn()));

        $channel = $this->readerConnection->channel();

        $channel->exchange($this->exchange)->declare(type: ExchangeTypeEnum::Fanout);

        $queue = $channel->queue('');

        $queue->declare(
            durable: false,
            exclusive: true,
            autoDelete: false,
        );

        $queue->bind($this->exchange);

        return $this->readerQueue = $queue;
    }

    /**
     * The events that reached the queue, in the order they arrived. Read again after a pause,
     * because a publish hands the message to the broker and the routing that follows is the
     * broker's own.
     *
     * Bounded by a deadline rather than by a number of reads, so that a slow machine spends
     * longer waiting instead of failing, and a genuine loss is reported as itself rather than
     * as two arrays that do not match.
     *
     * @return list<string>
     */
    private function deliveredEvents(Queue $queue, int $expected): array
    {
        $events = [];

        $deadline = microtime(true) + self::READ_BUDGET_SECONDS;

        while (count($events) < $expected) {
            $delivery = $queue->get(autoAck: true);

            if ($delivery === null) {
                if (microtime(true) >= $deadline) {
                    self::fail(sprintf(
                        'only %d of %d messages reached the queue within %.1f seconds: %s',
                        count($events),
                        $expected,
                        self::READ_BUDGET_SECONDS,
                        implode(', ', $events),
                    ));
                }

                usleep(self::READ_PAUSE_US);

                continue;
            }

            $message = BroadcastMessageDto::fromJson($delivery->body);

            if ($message !== null) {
                $events[] = $message->event;
            }
        }

        return $events;
    }

    private function publishRaw(string $body): void
    {
        $connection = new Connection(ConnectionOptions::fromDsn($this->dsn()));

        $connection->channel()->exchange($this->exchange)->publish($body);

        $connection->close();
    }

    private function dsn(): string
    {
        return (string) env('SCONCUR_RABBITMQ_DSN');
    }
}
