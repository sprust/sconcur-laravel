<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use Illuminate\Contracts\Events\Dispatcher;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\FakeConnection;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use SConcur\Laravel\Ws\Bus\LocalBroadcastBus;
use SConcur\Laravel\Ws\ConnectionHandler;
use SConcur\Laravel\Ws\ConnectionRegistry;
use SConcur\Laravel\Ws\Presence\MemoryPresenceRepository;
use SConcur\Laravel\Ws\Presence\PresencePayload;
use SConcur\Laravel\Ws\Protocol\MessageCodec;
use SConcur\Laravel\Ws\SocketIdGenerator;
use SConcur\Laravel\Ws\WsLogger;
use SConcur\Laravel\Ws\WsOptions;

/**
 * The protocol as a client sees it. The connection is a stand-in whose read() hands over
 * scripted frames and whose write() records them, so a whole session runs in one test
 * with no network and no extension.
 */
class ConnectionHandlerTest extends BaseTestCase
{
    private ConnectionRegistry $registry;

    private LocalBroadcastBus $bus;

    private SignatureVerifier $verifier;

    private MemoryPresenceRepository $presence;

    private ConnectionHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refreshHandler();
    }

    #[Test]
    public function itGreetsTheClientWithItsSocketId(): void
    {
        $connection = $this->talk([]);

        self::assertSame(['pusher:connection_established'], $connection->events());

        self::assertSame(
            ['socket_id' => '1.1', 'activity_timeout' => 120],
            $connection->dataOf('pusher:connection_established'),
        );
    }

    #[Test]
    public function itAnswersAPing(): void
    {
        $connection = $this->talk([$this->frame('pusher:ping')]);

        self::assertContains('pusher:pong', $connection->events());
    }

    #[Test]
    public function aPublicChannelNeedsNoSignature(): void
    {
        $connection = $this->talk([$this->frame('pusher:subscribe', ['channel' => 'demo'])]);

        self::assertSame('demo', $connection->channelOf('pusher_internal:subscription_succeeded'));
    }

    #[Test]
    public function aPrivateChannelIsAcceptedOnAValidSignature(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', [
                'channel' => 'private-orders.7',
                'auth'    => $this->verifier->sign(
                    socketId: '1.1',
                    channelName: 'private-orders.7',
                ),
            ]),
        ]);

        self::assertSame(
            'private-orders.7',
            $connection->channelOf('pusher_internal:subscription_succeeded'),
        );
    }

    #[Test]
    public function aPrivateChannelIsRefusedWithoutOne(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'private-orders.7', 'auth' => 'testkey:nonsense']),
        ]);

        self::assertContains('pusher:subscription_error', $connection->events());
        self::assertNotContains('pusher_internal:subscription_succeeded', $connection->events());
    }

    /** Refused by name rather than quietly treated as an ordinary private channel. */
    #[Test]
    public function anEncryptedChannelIsRefused(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'private-encrypted-orders.7', 'auth' => 'x']),
        ]);

        self::assertSame(4009, $connection->dataOf('pusher:subscription_error')['status'] ?? null);
    }

    /** The workbench allows three channels per connection; the fourth is refused. */
    #[Test]
    public function itStopsAtTheChannelLimit(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'a']),
            $this->frame('pusher:subscribe', ['channel' => 'b']),
            $this->frame('pusher:subscribe', ['channel' => 'c']),
            $this->frame('pusher:subscribe', ['channel' => 'd']),
        ]);

        self::assertSame(4100, $connection->dataOf('pusher:subscription_error')['status'] ?? null);
    }

    #[Test]
    public function unsubscribingLeavesTheChannel(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'private-chat', 'auth' => $this->chatAuth()]),
            $this->frame('pusher:unsubscribe', ['channel' => 'private-chat']),
            // Only a subscriber may send a client event, so silence here is the proof
            // that the unsubscribe took effect.
            '{"event":"client-typing","channel":"private-chat","data":{}}',
        ]);

        self::assertContains('pusher_internal:subscription_succeeded', $connection->events());
        self::assertSame([], $this->bus->published());
    }

    /**
     * The connection is gone from the registry whatever ended it — a leak here is an
     * entry the worker keeps writing to for the life of the process.
     */
    #[Test]
    public function theRegistryIsEmptyOnceTheClientLeaves(): void
    {
        $this->talk([$this->frame('pusher:subscribe', ['channel' => 'demo'])]);

        self::assertTrue($this->registry->isEmpty());
        self::assertSame([], $this->registry->channelSocketIds('demo'));
    }

    #[Test]
    public function aWrongAppKeyInThePathIsRefusedAndClosed(): void
    {
        $connection = new FakeConnection(
            inbound: [],
            path: '/app/wrongkey',
        );

        ($this->handler)($connection);

        self::assertSame(4001, $connection->dataOf('pusher:error')['code'] ?? null);
        self::assertTrue($connection->isClosed());
        self::assertTrue($this->registry->isEmpty());
    }

    /** A client event goes to the bus, never back to whoever sent it. */
    #[Test]
    public function aClientEventIsPublishedWithItsSenderExcluded(): void
    {
        $this->talk([
            $this->frame('pusher:subscribe', [
                'channel' => 'private-chat',
                'auth'    => $this->chatAuth(),
            ]),
            '{"event":"client-typing","channel":"private-chat","data":{"who":"ann"}}',
        ]);

        $message = $this->bus->published()[0];

        self::assertSame('client-typing', $message->event);
        self::assertSame(['private-chat'], $message->channels);
        self::assertSame('1.1', $message->socket);
        self::assertSame('{"who":"ann"}', $message->data);
    }

    #[Test]
    public function aClientEventOnAPublicChannelGoesNowhere(): void
    {
        $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'demo']),
            '{"event":"client-typing","channel":"demo","data":{}}',
        ]);

        self::assertSame([], $this->bus->published());
    }

    #[Test]
    public function aPresenceSubscriptionAnswersWithTheMemberList(): void
    {
        $channelData = '{"user_id":"7","user_info":{"name":"Ann"}}';

        $connection = $this->talk([
            $this->frame('pusher:subscribe', [
                'channel'      => 'presence-room.1',
                'channel_data' => $channelData,
                'auth'         => $this->verifier->sign(
                    socketId: '1.1',
                    channelName: 'presence-room.1',
                    channelData: $channelData,
                ),
            ]),
        ]);

        $presence = (array) ($connection->dataOf('pusher_internal:subscription_succeeded')['presence'] ?? []);

        self::assertSame(['7'], $presence['ids']);
        self::assertSame(1, $presence['count']);
    }

    #[Test]
    public function aPresenceArrivalAndDepartureAreAnnouncedOnTheBus(): void
    {
        $channelData = '{"user_id":"7","user_info":{"name":"Ann"}}';

        $this->talk([
            $this->frame('pusher:subscribe', [
                'channel'      => 'presence-room.1',
                'channel_data' => $channelData,
                'auth'         => $this->verifier->sign(
                    socketId: '1.1',
                    channelName: 'presence-room.1',
                    channelData: $channelData,
                ),
            ]),
        ]);

        $events = array_map(static fn($message): string => $message->event, $this->bus->published());

        self::assertSame(
            ['pusher_internal:member_added', 'pusher_internal:member_removed'],
            $events,
        );

        // Announced to everyone but the member itself: it already has the full list.
        self::assertSame('1.1', $this->bus->published()[0]->socket);
        self::assertSame([], $this->presence->members('presence-room.1'));
    }

    #[Test]
    public function presenceDataThatDoesNotDecodeIsRefused(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', [
                'channel'      => 'presence-room.1',
                'channel_data' => 'not json',
                'auth'         => $this->verifier->sign(
                    socketId: '1.1',
                    channelName: 'presence-room.1',
                    channelData: 'not json',
                ),
            ]),
        ]);

        self::assertContains('pusher:subscription_error', $connection->events());
        self::assertNotContains('pusher_internal:subscription_succeeded', $connection->events());
        self::assertSame([], $this->presence->members('presence-room.1'));
    }

    /** Noise must not cost a client its connection. */
    #[Test]
    public function itIgnoresFramesItDoesNotSpeak(): void
    {
        $connection = $this->talk([
            'not json at all',
            '{"event":"pusher:whatever","data":{}}',
            $this->frame('pusher:ping'),
        ]);

        self::assertContains('pusher:pong', $connection->events());
    }

    /** Off unless asked for, as they are with Pusher. */
    #[Test]
    public function aClientEventIsDroppedWhenTheyAreTurnedOff(): void
    {
        config()->set('sconcur.ws.client_events', false);

        $this->refreshHandler();

        $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'private-chat', 'auth' => $this->chatAuth()]),
            '{"event":"client-typing","channel":"private-chat","data":{}}',
        ]);

        self::assertSame([], $this->bus->published());
    }

    /** Without a cap one client is a broadcast facility of its own. */
    #[Test]
    public function clientEventsStopAtTheRateLimit(): void
    {
        config()->set('sconcur.ws.client_events_per_minute', 2);

        $this->refreshHandler();

        $frames = [$this->frame('pusher:subscribe', ['channel' => 'private-chat', 'auth' => $this->chatAuth()])];

        for ($index = 0; $index < 5; $index++) {
            $frames[] = '{"event":"client-typing","channel":"private-chat","data":{}}';
        }

        $this->talk($frames);

        self::assertCount(2, $this->bus->published());
    }

    #[Test]
    public function aClientEventOnAChannelItNeverJoinedGoesNowhere(): void
    {
        $this->talk(['{"event":"client-typing","channel":"private-chat","data":{}}']);

        self::assertSame([], $this->bus->published());
    }

    #[Test]
    public function subscribingTwiceAnswersOnce(): void
    {
        $connection = $this->talk([
            $this->frame('pusher:subscribe', ['channel' => 'demo']),
            $this->frame('pusher:subscribe', ['channel' => 'demo']),
        ]);

        $succeeded = array_filter(
            $connection->events(),
            static fn(string $event): bool => $event === 'pusher_internal:subscription_succeeded',
        );

        self::assertCount(1, $succeeded);
    }

    #[Test]
    public function unsubscribingFromSomethingItNeverJoinedIsQuiet(): void
    {
        $connection = $this->talk([$this->frame('pusher:unsubscribe', ['channel' => 'demo'])]);

        self::assertSame(['pusher:connection_established'], $connection->events());
    }

    #[Test]
    public function aSubscribeWithNoChannelIsIgnored(): void
    {
        $connection = $this->talk([$this->frame('pusher:subscribe', [])]);

        self::assertSame(['pusher:connection_established'], $connection->events());
    }

    /** A member the protocol cannot name is nobody arriving. */
    #[Test]
    public function presenceDataWithoutAUserIdAnnouncesNothing(): void
    {
        $channelData = '{"user_info":{"name":"Ann"}}';

        $this->talk([
            $this->frame('pusher:subscribe', [
                'channel'      => 'presence-room.1',
                'channel_data' => $channelData,
                'auth'         => $this->verifier->sign(
                    socketId: '1.1',
                    channelName: 'presence-room.1',
                    channelData: $channelData,
                ),
            ]),
        ]);

        self::assertSame([], $this->bus->published());
    }

    /** Rebuilds the handler on the config as it stands, for tests that change it. */
    private function refreshHandler(): void
    {
        $options = WsOptions::fromArray((array) config('sconcur.ws'));

        $this->registry = new ConnectionRegistry();

        $this->bus = new LocalBroadcastBus();

        $this->verifier = new SignatureVerifier(
            appKey: $options->appKey,
            appSecret: $options->appSecret,
        );

        $this->presence = new MemoryPresenceRepository();

        $codec = new MessageCodec();

        $this->handler = new ConnectionHandler(
            options: $options,
            registry: $this->registry,
            verifier: $this->verifier,
            codec: $codec,
            bus: $this->bus,
            subscriber: new BusSubscriber(
                bus: $this->bus,
                registry: $this->registry,
                codec: $codec,
            ),
            presence: $this->presence,
            presencePayload: new PresencePayload(),
            // A fixed pid so the socket id under test is known, and so the signatures
            // below can be computed for it.
            socketIds: new SocketIdGenerator(processId: 1),
            events: $this->getApp()->make(Dispatcher::class),
            logger: new WsLogger('php://memory'),
        );
    }

    private function chatAuth(): string
    {
        return $this->verifier->sign(
            socketId: '1.1',
            channelName: 'private-chat',
        );
    }

    /**
     * @param list<string> $inbound
     */
    private function talk(array $inbound): FakeConnection
    {
        $connection = new FakeConnection($inbound);

        ($this->handler)($connection);

        return $connection;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function frame(string $event, array $data = []): string
    {
        return (string) json_encode(['event' => $event, 'data' => $data]);
    }
}
