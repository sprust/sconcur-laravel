<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\FakeConnection;
use SConcur\Laravel\Tests\Feature\Ws\Support\ThrowingPresenceRepository;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use SConcur\Laravel\Ws\Bus\LocalBroadcastBus;
use SConcur\Laravel\Ws\ConnectionHandler;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\Presence\PresencePayload;
use SConcur\Laravel\Ws\Protocol\MessageCodec;
use SConcur\Laravel\Ws\SocketIdGenerator;
use SConcur\Laravel\Ws\WsLogger;
use SConcur\Laravel\Ws\WsOptions;
use Throwable;

/**
 * What the registry looks like once a client has gone.
 *
 * It has to be empty whatever happened on the way out, and not for tidiness: an entry
 * left behind is a dead socket the fan-out keeps writing to, and — because the bus
 * subscriber lives exactly as long as the registry is not empty — a coroutine that never
 * ends and a graceful stop that never finishes.
 */
class ConnectionTeardownTest extends BaseTestCase
{
    private ConnectionRegistry $registry;

    private SignatureVerifier $verifier;

    private ThrowingPresenceRepository $presence;

    private ConnectionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $options = WsOptions::fromArray((array) config('sconcur.ws'));

        $this->registry = new ConnectionRegistry();

        $this->verifier = new SignatureVerifier(
            appKey: $options->appKey,
            appSecret: $options->appSecret,
        );

        $this->presence = new ThrowingPresenceRepository();

        $bus = new LocalBroadcastBus();

        $codec = new MessageCodec();

        $this->handler = new ConnectionHandler(
            options: $options,
            registry: $this->registry,
            verifier: $this->verifier,
            codec: $codec,
            bus: $bus,
            subscriber: new BusSubscriber(
                bus: $bus,
                registry: $this->registry,
                codec: $codec,
            ),
            presence: $this->presence,
            presencePayload: new PresencePayload(),
            socketIds: new SocketIdGenerator(processId: 1),
            events: $this->getApp()->make(Dispatcher::class),
            logger: new WsLogger(stream: 'php://memory'),
        );
    }

    /**
     * The regression: leaving a presence channel talks to the shared store, and the two
     * calls it makes were unguarded. A store that had gone away took the whole teardown
     * with it, and forget() never ran.
     */
    #[Test]
    public function theRegistryIsClearedEvenWhenThePresenceStoreDiesFirst(): void
    {
        $connection = new FakeConnection([$this->presenceSubscription()]);

        // The store goes away after the subscription and before the disconnect, which is
        // the ordering that used to leave the entry behind.
        $connection->onDrained = function (): void {
            $this->presence->down = true;
        };

        ($this->handler)($connection);

        self::assertTrue($this->registry->isEmpty());
        self::assertSame([], $this->registry->channelSocketIds('presence-room.1'));
    }

    #[Test]
    public function itIsClearedAfterAnOrdinarySessionToo(): void
    {
        $connection = new FakeConnection([
            (string) json_encode(['event' => 'pusher:subscribe', 'data' => ['channel' => 'demo']]),
        ]);

        ($this->handler)($connection);

        self::assertTrue($this->registry->isEmpty());
    }

    /** A store that is already down refuses the subscription rather than half-taking it. */
    #[Test]
    public function aPresenceSubscriptionAgainstADeadStoreLeavesNothingBehind(): void
    {
        $this->presence->down = true;

        $connection = new FakeConnection([$this->presenceSubscription()]);

        try {
            ($this->handler)($connection);
        } catch (Throwable) {
            // The store's failure is the server's to report; what is asserted here is
            // that the registry did not keep the connection because of it.
        }

        self::assertTrue($this->registry->isEmpty());
    }

    /** The frame a client sends to join a presence channel, signed for socket 1.1. */
    private function presenceSubscription(): string
    {
        $channelData = '{"user_id":"7","user_info":{"name":"Ann"}}';

        return (string) json_encode([
            'event' => 'pusher:subscribe',
            'data'  => [
                'channel'      => 'presence-room.1',
                'channel_data' => $channelData,
                'auth'         => $this->verifier->sign(
                    socketId: '1.1',
                    channelName: 'presence-room.1',
                    channelData: $channelData,
                ),
            ],
        ]);
    }
}
