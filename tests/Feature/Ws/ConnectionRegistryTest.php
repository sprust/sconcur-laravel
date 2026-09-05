<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\FakeConnection;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\ConnectionState;

class ConnectionRegistryTest extends BaseTestCase
{
    private ConnectionRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ConnectionRegistry();
    }

    #[Test]
    public function itFindsTheSubscribersOfAChannel(): void
    {
        $this->register('1.1');
        $this->register('1.2');
        $this->register('1.3');

        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'demo',
        );
        $this->registry->subscribe(
            socketId: '1.2',
            channel: 'demo',
        );

        self::assertSame(['1.1', '1.2'], $this->socketIdsOf('demo'));
    }

    /** What toOthers() is, and what keeps a client event from echoing to its author. */
    #[Test]
    public function itLeavesTheExcludedSocketOut(): void
    {
        $this->register('1.1');
        $this->register('1.2');

        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'demo',
        );
        $this->registry->subscribe(
            socketId: '1.2',
            channel: 'demo',
        );

        $subscribers = $this->registry->subscribers(
            channel: 'demo',
            exceptSocketId: '1.1',
        );

        self::assertSame(['1.2'], array_map(static fn($state) => $state->socketId, $subscribers));
    }

    /**
     * A connection that goes leaves nothing behind in any channel. A leak here is a
     * registry entry the worker keeps writing to for the life of the process.
     */
    #[Test]
    public function forgettingAConnectionClearsEveryChannelItWasIn(): void
    {
        $this->register('1.1');

        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'demo',
        );
        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'private-orders',
        );

        $this->registry->forget('1.1');

        self::assertSame([], $this->registry->subscribers('demo'));
        self::assertSame([], $this->registry->subscribers('private-orders'));
        self::assertSame([], $this->registry->channelSocketIds('demo'));
        self::assertTrue($this->registry->isEmpty());
    }

    #[Test]
    public function unsubscribingDropsTheChannelOnceItIsEmpty(): void
    {
        $this->register('1.1');

        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'demo',
        );
        $this->registry->unsubscribe(
            socketId: '1.1',
            channel: 'demo',
        );

        self::assertSame([], $this->registry->channelSocketIds('demo'));
        self::assertFalse($this->registry->get('1.1')?->hasChannel('demo'));
        self::assertFalse($this->registry->isEmpty());
    }

    /** The presence member to announce as gone is the one the connection announced. */
    #[Test]
    public function itKeepsTheChannelDataOfASubscription(): void
    {
        $this->register('1.1');

        $this->registry->subscribe(
            socketId: '1.1',
            channel: 'presence-room',
            channelData: '{"user_id":"7"}',
        );

        self::assertSame('{"user_id":"7"}', $this->registry->get('1.1')?->channelData('presence-room'));
    }

    /**
     * Emptiness is not a detail: it is what the bus subscriber's lifetime is tied to, so
     * that a stopping worker has nothing left running for the drain to wait on.
     */
    #[Test]
    public function itIsEmptyOnlyWhileNoConnectionIsHeld(): void
    {
        self::assertTrue($this->registry->isEmpty());

        $this->register('1.1');

        self::assertFalse($this->registry->isEmpty());
        self::assertSame(1, $this->registry->count());

        $this->registry->forget('1.1');

        self::assertTrue($this->registry->isEmpty());
    }

    /** Nothing addressed to a socket the registry never had may create anything. */
    #[Test]
    public function anUnknownSocketIsIgnoredRatherThanInvented(): void
    {
        $this->registry->subscribe(
            socketId: 'nobody',
            channel: 'demo',
        );
        $this->registry->unsubscribe(
            socketId: 'nobody',
            channel: 'demo',
        );
        $this->registry->forget('nobody');

        self::assertNull($this->registry->get('nobody'));
        self::assertSame([], $this->registry->channelSocketIds('demo'));
        self::assertTrue($this->registry->isEmpty());
    }

    #[Test]
    public function anEmptyChannelHasNoSubscribers(): void
    {
        self::assertSame([], $this->registry->subscribers('never-used'));
    }

    private function register(string $socketId): void
    {
        $this->registry->add(new ConnectionState(
            socketId: $socketId,
            connection: new FakeConnection(id: $socketId),
        ));
    }

    /**
     * @return list<string>
     */
    private function socketIdsOf(string $channel): array
    {
        return array_map(
            static fn(ConnectionState $state): string => $state->socketId,
            $this->registry->subscribers($channel),
        );
    }
}
