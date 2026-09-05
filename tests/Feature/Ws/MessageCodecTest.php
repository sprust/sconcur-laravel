<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Protocol\MessageCodec;
use SConcur\Laravel\Ws\Protocol\ProtocolEventEnum;

class MessageCodecTest extends BaseTestCase
{
    private MessageCodec $codec;

    protected function setUp(): void
    {
        parent::setUp();

        $this->codec = new MessageCodec();
    }

    /**
     * The rule the whole protocol hangs on: `data` goes out as a string with JSON inside
     * it, not as a nested object. A client that gets the nested form drops the frame
     * without a word.
     */
    #[Test]
    public function itEncodesDataAsAJsonString(): void
    {
        $frame = json_decode(
            $this->codec->encode(
                event: ProtocolEventEnum::ConnectionEstablished,
                data: ['socket_id' => '7.1'],
            ),
            true,
        );

        self::assertSame('pusher:connection_established', $frame['event']);
        self::assertIsString($frame['data']);
        self::assertSame(['socket_id' => '7.1'], json_decode($frame['data'], true));
    }

    /** An empty payload has to be an object, or the client reads `[]` as a list. */
    #[Test]
    public function itEncodesAnEmptyPayloadAsAnObject(): void
    {
        self::assertSame('{}', $this->codec->encodeData([]));
    }

    #[Test]
    public function itCarriesTheChannelOnlyWhenThereIsOne(): void
    {
        $withChannel = json_decode(
            $this->codec->encode(
                event: ProtocolEventEnum::SubscriptionSucceeded,
                channel: 'demo',
            ),
            true,
        );

        $without = json_decode($this->codec->encode(ProtocolEventEnum::Pong), true);

        self::assertSame('demo', $withChannel['channel']);
        self::assertArrayNotHasKey('channel', $without);
    }

    /** pusher-js sends its own frames with a nested object; both forms have to decode. */
    #[Test]
    public function itDecodesDataInBothForms(): void
    {
        $nested = $this->codec->decode('{"event":"pusher:subscribe","data":{"channel":"demo"}}');

        $stringified = $this->codec->decode('{"event":"pusher:subscribe","data":"{\"channel\":\"demo\"}"}');

        self::assertSame('demo', $nested?->channelName());
        self::assertSame('demo', $stringified?->channelName());
    }

    #[Test]
    public function itPrefersTheTopLevelChannelOverTheOneInsideData(): void
    {
        $message = $this->codec->decode('{"event":"client-typing","channel":"private-a","data":{"channel":"b"}}');

        self::assertSame('private-a', $message?->channelName());
    }

    #[Test]
    public function itAnswersNullForNoiseInsteadOfThrowing(): void
    {
        self::assertNull($this->codec->decode('not json at all'));
        self::assertNull($this->codec->decode('[]'));
        self::assertNull($this->codec->decode('{"data":{}}'));
        self::assertNull($this->codec->decode('{"event":""}'));
    }

    /** The application payload is already a JSON string when it comes off the bus. */
    #[Test]
    public function itPassesAnAlreadyEncodedPayloadThrough(): void
    {
        $frame = json_decode(
            $this->codec->encodeRaw(
                event: 'OrderShipped',
                data: '{"id":7}',
                channel: 'private-orders',
            ),
            true,
        );

        self::assertSame('OrderShipped', $frame['event']);
        self::assertSame('{"id":7}', $frame['data']);
        self::assertSame('private-orders', $frame['channel']);
    }

    /** `data` that decodes to something other than an object carries no fields. */
    #[Test]
    public function aScalarPayloadIsNoPayload(): void
    {
        self::assertSame([], $this->codec->decode('{"event":"e","data":"7"}')?->data);
        self::assertSame([], $this->codec->decode('{"event":"e","data":"not json"}')?->data);
        self::assertSame([], $this->codec->decode('{"event":"e","data":""}')?->data);
        self::assertSame([], $this->codec->decode('{"event":"e","data":7}')?->data);
    }

    #[Test]
    public function aFrameWithNoChannelAnywhereHasNone(): void
    {
        self::assertNull($this->codec->decode('{"event":"pusher:ping","data":{}}')?->channelName());
    }

    #[Test]
    public function aNonStringChannelIsNoChannel(): void
    {
        self::assertNull($this->codec->decode('{"event":"e","channel":7,"data":{}}')?->channelName());
    }

    #[Test]
    public function itReadsAStringFieldOnlyWhenItIsOne(): void
    {
        $message = $this->codec->decode('{"event":"e","data":{"auth":"k:s","count":7}}');

        self::assertNotNull($message);
        self::assertSame('k:s', $message->stringField('auth'));
        self::assertNull($message->stringField('count'));
        self::assertNull($message->stringField('absent'));
    }

    #[Test]
    public function aTopLevelValueThatIsNotAnObjectIsNoFrame(): void
    {
        self::assertNull($this->codec->decode('"a string"'));
        self::assertNull($this->codec->decode('7'));
    }

    #[Test]
    public function anApplicationFrameNeedsNoChannel(): void
    {
        $frame = (array) json_decode($this->codec->encodeRaw(event: 'E', data: '{}'), true);

        self::assertArrayNotHasKey('channel', $frame);
    }
}
