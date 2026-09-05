<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\FakeConnection;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use SConcur\Laravel\Ws\Bus\LocalBroadcastBus;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\ConnectionState;
use SConcur\Laravel\Ws\Protocol\MessageCodec;

class BusSubscriberTest extends BaseTestCase
{
    private ConnectionRegistry $registry;

    private LocalBroadcastBus $bus;

    private BusSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ConnectionRegistry();

        $this->bus = new LocalBroadcastBus();

        $this->subscriber = new BusSubscriber(
            bus: $this->bus,
            registry: $this->registry,
            codec: new MessageCodec(),
        );
    }

    #[Test]
    public function itWritesTheEventToEverySubscriberOfTheChannel(): void
    {
        $first = $this->connect('1.1', 'demo');

        $second = $this->connect('1.2', 'demo');

        $this->connect('1.3', 'other');

        $this->subscriber->fanOut($this->message('demo'));

        self::assertCount(1, $first->written);
        self::assertCount(1, $second->written);

        $frame = json_decode($first->written[0], true);

        self::assertSame('OrderShipped', $frame['event']);
        self::assertSame('demo', $frame['channel']);
        // The payload is carried through as it came off the bus rather than re-encoded.
        self::assertSame('{"id":7}', $frame['data']);
    }

    #[Test]
    public function itLeavesTheExcludedSocketOut(): void
    {
        $author = $this->connect('1.1', 'demo');

        $other = $this->connect('1.2', 'demo');

        $this->subscriber->fanOut($this->message('demo', socket: '1.1'));

        self::assertSame([], $author->written);
        self::assertCount(1, $other->written);
    }

    /**
     * A close and a delivery cross all the time, so a dead client is expected rather than
     * exceptional — and must not cost the others their message.
     */
    #[Test]
    public function oneDeadClientDoesNotStopTheOthers(): void
    {
        $dead = $this->connect('1.1', 'demo');

        $dead->failWrites = true;

        $alive = $this->connect('1.2', 'demo');

        $this->subscriber->fanOut($this->message('demo'));

        self::assertCount(1, $alive->written);
    }

    #[Test]
    public function itDeliversToEveryChannelOfTheMessage(): void
    {
        $connection = $this->connect('1.1', 'demo');

        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'private-orders',
        );

        $this->subscriber->fanOut(new BroadcastMessageDto(
            channels: ['demo', 'private-orders'],
            event: 'OrderShipped',
            data: '{"id":7}',
        ));

        self::assertCount(2, $connection->written);
    }

    /**
     * A bus with no loop of its own is subscribed on the way into serve(), so a publish
     * from the same process reaches the connections with no coroutine involved.
     */
    #[Test]
    public function bootSubscribesABusThatNeedsNoCoroutine(): void
    {
        $connection = $this->connect('1.1', 'demo');

        $this->subscriber->boot();

        $this->bus->publish($this->message('demo'));

        self::assertCount(1, $connection->written);
        self::assertFalse($this->subscriber->isRunning());
    }

    private function connect(string $socketId, string $channel): FakeConnection
    {
        $connection = new FakeConnection(id: $socketId);

        $this->registry->add(new ConnectionState(socketId: $socketId, connection: $connection));

        $this->registry->subscribe(
            socketId: $socketId,
            channel: $channel,
        );

        return $connection;
    }

    private function message(string $channel, ?string $socket = null): BroadcastMessageDto
    {
        return new BroadcastMessageDto(
            channels: [$channel],
            event: 'OrderShipped',
            data: '{"id":7}',
            socket: $socket,
        );
    }
}
