<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\FakeConnection;
use SConcur\Laravel\Ws\ConnectionState;

/**
 * One client's own state: what it is subscribed to, and how much it is allowed to say.
 */
class ConnectionStateTest extends BaseTestCase
{
    private ConnectionState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->state = new ConnectionState(
            socketId: '1.1',
            connection: new FakeConnection(),
        );
    }

    #[Test]
    public function itStartsWithNothing(): void
    {
        self::assertSame([], $this->state->channels());
        self::assertSame(0, $this->state->channelCount());
        self::assertFalse($this->state->hasChannel('demo'));
    }

    /** The member payload is kept so leaving can announce the one that arrived. */
    #[Test]
    public function itRemembersWhatEachSubscriptionCarried(): void
    {
        $this->state->subscribe(
            channel: 'presence-room',
            channelData: '{"user_id":"7"}',
        );
        $this->state->subscribe(channel: 'demo');

        self::assertSame('{"user_id":"7"}', $this->state->channelData('presence-room'));
        self::assertNull($this->state->channelData('demo'));
        self::assertNull($this->state->channelData('never-subscribed'));
        self::assertSame(2, $this->state->channelCount());
    }

    #[Test]
    public function subscribingTwiceIsStillOneChannel(): void
    {
        $this->state->subscribe(channel: 'demo');
        $this->state->subscribe(channel: 'demo');

        self::assertSame(['demo'], $this->state->channels());
    }

    #[Test]
    public function unsubscribingDropsIt(): void
    {
        $this->state->subscribe(channel: 'demo');
        $this->state->unsubscribe('demo');

        self::assertFalse($this->state->hasChannel('demo'));
        self::assertSame(0, $this->state->channelCount());
    }

    #[Test]
    public function unsubscribingFromSomethingItNeverHadIsQuiet(): void
    {
        $this->state->unsubscribe('demo');

        self::assertSame(0, $this->state->channelCount());
    }

    /** Without a cap one client is a broadcast facility of its own. */
    #[Test]
    public function itStopsAllowingClientEventsAtTheLimit(): void
    {
        for ($index = 0; $index < 3; $index++) {
            self::assertTrue($this->state->allowClientEvent(3), 'event ' . $index);
        }

        self::assertFalse($this->state->allowClientEvent(3));
    }

    #[Test]
    public function aLimitOfZeroMeansNoLimit(): void
    {
        for ($index = 0; $index < 50; $index++) {
            self::assertTrue($this->state->allowClientEvent(0));
        }
    }

    #[Test]
    public function eachConnectionHasItsOwnAllowance(): void
    {
        $other = new ConnectionState(
            socketId: '1.2',
            connection: new FakeConnection(),
        );

        self::assertTrue($this->state->allowClientEvent(1));
        self::assertFalse($this->state->allowClientEvent(1));

        self::assertTrue($other->allowClientEvent(1));
    }
}
