<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\FakeConnection;
use SConcur\Laravel\Tests\Feature\Ws\Support\LoopingBus;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\ConnectionState;
use SConcur\Laravel\Ws\Protocol\MessageCodec;

/**
 * How long the bus subscriber lives.
 *
 * This is the load-bearing part of the pool's shutdown: Scheduler::serve stops accepting
 * and then waits for every spawned coroutine, so a subscriber that outlives the
 * connections holds the drain open until the master kills the worker — and one that dies
 * too eagerly leaves a connected client receiving nothing.
 */
class SubscriberLifetimeTest extends BaseTestCase
{
    private ConnectionRegistry $registry;

    private LoopingBus $bus;

    private BusSubscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registry = new ConnectionRegistry();

        $this->bus = new LoopingBus();

        $this->subscriber = new BusSubscriber(
            bus: $this->bus,
            registry: $this->registry,
            codec: new MessageCodec(),
        );
    }

    #[Test]
    public function itStandsDownOnceTheRegistryIsEmpty(): void
    {
        $this->subscriber->ensureRunning();

        self::assertSame(1, $this->bus->subscribeCalls);
        self::assertFalse($this->subscriber->isRunning());
    }

    /**
     * The regression this exists for: the flag used to be cleared only after subscribe()
     * returned, and the real bus suspends on the way out — closing a connection and
     * cancelling a consumer. A client connecting in that window found the subscriber
     * still marked as running, so none was started for it and it received nothing until
     * somebody else connected.
     */
    #[Test]
    public function aConnectionArrivingWhileItStopsGetsASubscriberOfItsOwn(): void
    {
        $this->bus->onStopping = function (): void {
            // One shot: the second subscriber must not re-enter this.
            $this->bus->onStopping = null;

            $this->connect('1.1');

            $this->subscriber->ensureRunning();
        };

        $this->subscriber->ensureRunning();

        self::assertSame(2, $this->bus->subscribeCalls);
    }

    #[Test]
    public function itKeepsGoingWhileConnectionsRemain(): void
    {
        $this->connect('1.1');

        $this->subscriber->ensureRunning();

        // The bus asked the condition, got true, and returned of its own accord.
        self::assertSame(1, $this->bus->subscribeCalls);
    }

    #[Test]
    public function aBusWithALoopOfItsOwnIsNotSubscribedByBoot(): void
    {
        $this->subscriber->boot();

        self::assertSame(0, $this->bus->subscribeCalls);
    }

    private function connect(string $socketId): void
    {
        $this->registry->add(new ConnectionState(
            socketId: $socketId,
            connection: new FakeConnection(id: $socketId),
        ));
    }
}
