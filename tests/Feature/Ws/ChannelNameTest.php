<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Protocol\ChannelName;
use SConcur\Laravel\Ws\Protocol\ChannelTypeEnum;

class ChannelNameTest extends BaseTestCase
{
    #[Test]
    public function itReadsTheTypeOffThePrefix(): void
    {
        self::assertSame(ChannelTypeEnum::Public, ChannelName::fromString('demo')->type);
        self::assertSame(ChannelTypeEnum::Private, ChannelName::fromString('private-demo')->type);
        self::assertSame(ChannelTypeEnum::Presence, ChannelName::fromString('presence-demo')->type);
    }

    /**
     * The encrypted prefix starts with the private one, so the order of the checks is
     * the whole of this: read the other way round, an encrypted channel would be treated
     * as private and silently accepted while nothing decrypts anything.
     */
    #[Test]
    public function itTellsAnEncryptedChannelFromAPrivateOne(): void
    {
        $channel = ChannelName::fromString('private-encrypted-orders.7');

        self::assertSame(ChannelTypeEnum::Encrypted, $channel->type);
        self::assertTrue($channel->isEncrypted());
        self::assertSame('orders.7', $channel->short);
    }

    /** The short name is what routes/channels.php registers a callback under. */
    #[Test]
    public function itStripsThePrefixForTheLaravelSide(): void
    {
        self::assertSame('orders.7', ChannelName::fromString('private-orders.7')->short);
        self::assertSame('room.1', ChannelName::fromString('presence-room.1')->short);
        self::assertSame('demo', ChannelName::fromString('demo')->short);
    }

    #[Test]
    public function onlyAPublicChannelNeedsNoSignature(): void
    {
        self::assertFalse(ChannelName::fromString('demo')->requiresAuthorization());
        self::assertTrue(ChannelName::fromString('private-demo')->requiresAuthorization());
        self::assertTrue(ChannelName::fromString('presence-demo')->requiresAuthorization());
    }

    #[Test]
    public function clientEventsAreForPrivateAndPresenceChannelsOnly(): void
    {
        self::assertFalse(ChannelName::fromString('demo')->acceptsClientEvents());
        self::assertTrue(ChannelName::fromString('private-demo')->acceptsClientEvents());
        self::assertTrue(ChannelName::fromString('presence-demo')->acceptsClientEvents());
    }
}
