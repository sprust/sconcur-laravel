<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;
use SConcur\Laravel\Ws\Broadcasting\SConcurBroadcaster;
use SConcur\Laravel\Ws\Bus\LocalBroadcastBus;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The loop that matters most in the whole feature: the http worker signs, the ws worker
 * verifies, and the two have to agree on the signed string. Nothing else notices when
 * they stop agreeing — a private channel simply becomes one anybody can subscribe to.
 */
class BroadcasterTest extends BaseTestCase
{
    private LocalBroadcastBus $bus;

    private SignatureVerifier $verifier;

    private SConcurBroadcaster $broadcaster;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new LocalBroadcastBus();

        $this->verifier = new SignatureVerifier(appKey: 'testkey', appSecret: 'testsecret');

        $this->broadcaster = new SConcurBroadcaster(
            bus: $this->bus,
            verifier: $this->verifier,
        );
    }

    #[Test]
    public function whatItSignsForAPrivateChannelIsWhatTheWsWorkerVerifies(): void
    {
        $response = $this->broadcaster->validAuthenticationResponse(
            $this->authRequest('private-orders.7'),
            true,
        );

        self::assertTrue($this->verifier->verify(
            socketId: '7.1',
            channelName: 'private-orders.7',
            auth: $response['auth'],
        ));
    }

    #[Test]
    public function whatItSignsForAPresenceChannelIsWhatTheWsWorkerVerifies(): void
    {
        $response = $this->broadcaster->validAuthenticationResponse(
            $this->authRequest('presence-room.1'),
            ['name' => 'Ann'],
        );

        self::assertSame(
            ['user_id' => 7, 'user_info' => ['name' => 'Ann']],
            json_decode($response['channel_data'], true),
        );

        self::assertTrue($this->verifier->verify(
            socketId: '7.1',
            channelName: 'presence-room.1',
            auth: $response['auth'],
            channelData: $response['channel_data'],
        ));
    }

    #[Test]
    public function itRunsTheChannelCallbacksOfTheApplication(): void
    {
        $this->broadcaster->channel('orders.{id}', static fn($user, string $id): bool => (int) $id === 7);

        // auth() answers with the signed response itself: verifyUserCanAccessChannel
        // hands a truthy callback result straight to validAuthenticationResponse.
        $response = $this->broadcaster->auth($this->authRequest('private-orders.7'));

        self::assertTrue($this->verifier->verify(
            socketId: '7.1',
            channelName: 'private-orders.7',
            auth: $response['auth'],
        ));

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($this->authRequest('private-orders.8'));
    }

    /** A guarded channel with nobody signed in is a refusal, not an unsigned answer. */
    #[Test]
    public function aPrivateChannelNeedsAUser(): void
    {
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => 'private-orders.7',
            'socket_id'    => '7.1',
        ]);

        $this->expectException(AccessDeniedHttpException::class);

        $this->broadcaster->auth($request);
    }

    #[Test]
    public function itPublishesTheEventToTheBus(): void
    {
        $this->broadcaster->broadcast(['demo', 'private-orders.7'], 'OrderShipped', ['id' => 7]);

        $message = $this->bus->published()[0];

        self::assertSame(['demo', 'private-orders.7'], $message->channels);
        self::assertSame('OrderShipped', $message->event);
        self::assertSame('{"id":7}', $message->data);
        self::assertNull($message->socket);
    }

    /**
     * toOthers() puts the socket into the payload; it is addressing rather than data, so
     * it travels on the message and never reaches the client.
     */
    #[Test]
    public function itLiftsTheSocketOutOfThePayload(): void
    {
        $this->broadcaster->broadcast(['demo'], 'OrderShipped', ['id' => 7, 'socket' => '7.1']);

        $message = $this->bus->published()[0];

        self::assertSame('7.1', $message->socket);
        self::assertSame('{"id":7}', $message->data);
    }

    private function authRequest(string $channel): Request
    {
        $request = Request::create('/broadcasting/auth', 'POST', [
            'channel_name' => $channel,
            'socket_id'    => '7.1',
        ]);

        $request->setUserResolver(static fn(): GenericUser => new GenericUser(['id' => 7]));

        return $request;
    }
}
