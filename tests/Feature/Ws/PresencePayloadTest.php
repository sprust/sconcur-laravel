<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Presence\PresencePayload;

class PresencePayloadTest extends BaseTestCase
{
    private PresencePayload $payload;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payload = new PresencePayload();
    }

    /**
     * The store keeps members per socket and the protocol counts them per user: one
     * person with two tabs is one member.
     */
    #[Test]
    public function itFoldsSeveralConnectionsOfOneUserIntoOneMember(): void
    {
        $presence = $this->payload->forSubscription([
            '1.1' => ['user_id' => '7', 'user_info' => ['name' => 'Ann']],
            '1.2' => ['user_id' => '7', 'user_info' => ['name' => 'Ann']],
            '1.3' => ['user_id' => '9', 'user_info' => ['name' => 'Bo']],
        ])['presence'];

        self::assertSame(2, $presence['count']);
        self::assertSame(['7', '9'], $presence['ids']);
    }

    /** The hash has to encode as an object; an empty one must not become a list. */
    #[Test]
    public function theHashIsAnObjectEvenWhenEmpty(): void
    {
        $encoded = json_encode($this->payload->forSubscription([]));

        self::assertSame('{"presence":{"ids":[],"hash":{},"count":0}}', $encoded);
    }

    #[Test]
    public function itSkipsAMemberWithNoUserId(): void
    {
        $presence = $this->payload->forSubscription([
            '1.1' => ['user_info' => ['name' => 'nobody']],
        ])['presence'];

        self::assertSame(0, $presence['count']);
    }

    /**
     * What keeps a second tab from announcing an arrival, and closing it from announcing
     * a departure the person has not made.
     */
    #[Test]
    public function itSeesAnotherConnectionOfTheSameUser(): void
    {
        $members = [
            '1.1' => ['user_id' => '7'],
            '1.2' => ['user_id' => '7'],
        ];

        self::assertTrue($this->payload->hasOtherConnection($members, '1.1', '7'));
        self::assertFalse($this->payload->hasOtherConnection(['1.1' => ['user_id' => '7']], '1.1', '7'));
    }

    #[Test]
    public function itBuildsTheMemberEvents(): void
    {
        $member = ['user_id' => 7, 'user_info' => ['name' => 'Ann']];

        self::assertSame(
            ['user_id' => '7', 'user_info' => ['name' => 'Ann']],
            $this->payload->forMemberAdded($member),
        );

        self::assertSame(['user_id' => '7'], $this->payload->forMemberRemoved($member));
    }
}
