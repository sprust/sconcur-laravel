<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;

/**
 * One broadcast on the wire between workers.
 *
 * The bus is shared, so what comes off it was written by somebody else — possibly an
 * older version of this package, possibly not this package at all. Nothing that arrives
 * may take a worker down.
 */
class BroadcastMessageDtoTest extends BaseTestCase
{
    #[Test]
    public function itSurvivesTheRoundTrip(): void
    {
        $message = new BroadcastMessageDto(
            channels: ['demo', 'private-orders.7'],
            event: 'OrderShipped',
            data: '{"id":7}',
            socket: '1.1',
        );

        $decoded = BroadcastMessageDto::fromJson($message->toJson());

        self::assertNotNull($decoded);
        self::assertSame(['demo', 'private-orders.7'], $decoded->channels);
        self::assertSame('OrderShipped', $decoded->event);
        self::assertSame('{"id":7}', $decoded->data);
        self::assertSame('1.1', $decoded->socket);
    }

    /** Addressing is only carried when there is any: no socket, no key on the wire. */
    #[Test]
    public function theExcludedSocketIsOmittedWhenThereIsNone(): void
    {
        $json = new BroadcastMessageDto(channels: ['demo'], event: 'E', data: '{}')->toJson();

        self::assertStringNotContainsString('socket', $json);
        self::assertNull(BroadcastMessageDto::fromJson($json)?->socket);
    }

    #[Test]
    public function unicodeAndSlashesStayReadable(): void
    {
        $json = new BroadcastMessageDto(channels: ['демо'], event: 'E', data: '{"u":"путь/сюда"}')->toJson();

        self::assertStringContainsString('демо', $json);
        self::assertStringContainsString('путь/сюда', $json);
    }

    #[Test]
    public function noiseDecodesToNothing(): void
    {
        self::assertNull(BroadcastMessageDto::fromJson('not json'));
        self::assertNull(BroadcastMessageDto::fromJson('[]'));
        self::assertNull(BroadcastMessageDto::fromJson('"a string"'));
    }

    #[Test]
    public function aMessageWithNoEventOrNoChannelIsNotOne(): void
    {
        self::assertNull(BroadcastMessageDto::fromJson('{"channels":["demo"]}'));
        self::assertNull(BroadcastMessageDto::fromJson('{"channels":["demo"],"event":""}'));
        self::assertNull(BroadcastMessageDto::fromJson('{"event":"E"}'));
        self::assertNull(BroadcastMessageDto::fromJson('{"event":"E","channels":[]}'));
    }

    /** Channels have to be strings, and a payload that is not one falls back to empty. */
    #[Test]
    public function itKeepsOnlyWhatItCanUse(): void
    {
        $decoded = BroadcastMessageDto::fromJson('{"event":"E","channels":["demo",7,null],"data":{"a":1}}');

        self::assertNotNull($decoded);
        self::assertSame(['demo'], $decoded->channels);
        self::assertSame('{}', $decoded->data);
    }

    #[Test]
    public function aNonStringSocketIsNoSocket(): void
    {
        $decoded = BroadcastMessageDto::fromJson('{"event":"E","channels":["demo"],"socket":7}');

        self::assertNull($decoded?->socket);
    }
}
