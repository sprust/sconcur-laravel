<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Auth\SignatureVerifier;

class SignatureVerifierTest extends BaseTestCase
{
    private SignatureVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifier = new SignatureVerifier(
            appKey: 'testkey',
            appSecret: 'testsecret',
        );
    }

    #[Test]
    public function itSignsWithTheKeyInFront(): void
    {
        $auth = $this->verifier->sign(
            socketId: '7.1',
            channelName: 'private-demo',
        );

        self::assertStringStartsWith('testkey:', $auth);
        self::assertSame(
            'testkey:' . hash_hmac('sha256', '7.1:private-demo', 'testsecret'),
            $auth,
        );
    }

    #[Test]
    public function itVerifiesItsOwnSignature(): void
    {
        self::assertTrue($this->verifier->verify(
            socketId: '7.1',
            channelName: 'private-demo',
            auth: $this->verifier->sign(
                socketId: '7.1',
                channelName: 'private-demo',
            ),
        ));
    }

    /**
     * The socket id is in the signed string so that a signature handed out for one
     * connection cannot be replayed on another — which is the whole point of the client
     * fetching a new one per channel.
     */
    #[Test]
    public function aSignatureDoesNotTravelToAnotherSocketOrChannel(): void
    {
        $auth = $this->verifier->sign(
            socketId: '7.1',
            channelName: 'private-demo',
        );

        self::assertFalse($this->verifier->verify(
            socketId: '7.2',
            channelName: 'private-demo',
            auth: $auth,
        ));

        self::assertFalse($this->verifier->verify(
            socketId: '7.1',
            channelName: 'private-other',
            auth: $auth,
        ));
    }

    /**
     * The member payload is signed along with a presence channel: without it the client
     * would be free to rewrite who it says it is on the way to the ws worker, which has
     * no other way to know.
     */
    #[Test]
    public function presenceChannelDataIsPartOfTheSignature(): void
    {
        $channelData = '{"user_id":"7"}';

        $auth = $this->verifier->sign(
            socketId: '7.1',
            channelName: 'presence-room.1',
            channelData: $channelData,
        );

        self::assertTrue($this->verifier->verify(
            socketId: '7.1',
            channelName: 'presence-room.1',
            auth: $auth,
            channelData: $channelData,
        ));

        self::assertFalse($this->verifier->verify(
            socketId: '7.1',
            channelName: 'presence-room.1',
            auth: $auth,
            channelData: '{"user_id":"8"}',
        ));
    }

    #[Test]
    public function itRefusesAnEmptyOrMalformedAuth(): void
    {
        self::assertFalse($this->verifier->verify(socketId: '7.1', channelName: 'private-demo', auth: ''));
        self::assertFalse($this->verifier->verify(socketId: '7.1', channelName: 'private-demo', auth: 'testkey:'));
        self::assertFalse($this->verifier->verify(socketId: '7.1', channelName: 'private-demo', auth: 'nonsense'));
    }

    /** The key is public — the browser carries it — and the handler compares it. */
    #[Test]
    public function itHandsBackTheKeyItSignsWith(): void
    {
        self::assertSame('testkey', $this->verifier->appKey());
    }

    #[Test]
    public function withoutBothHalvesItIsNotConfigured(): void
    {
        self::assertTrue($this->verifier->isConfigured());

        self::assertFalse(new SignatureVerifier(appKey: '', appSecret: 's')->isConfigured());
        self::assertFalse(new SignatureVerifier(appKey: 'k', appSecret: '')->isConfigured());
    }
}
