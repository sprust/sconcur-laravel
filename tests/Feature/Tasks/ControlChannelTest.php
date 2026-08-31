<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\Control\ControlCommandDto;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The channel is what lets `make tasks-stop` reach a pool running in another container
 * without knowing its pid.
 */
class ControlChannelTest extends BaseTestCase
{
    #[Test]
    public function aSentCommandIsTakenOnce(): void
    {
        $channel = $this->channel();

        $channel->send(ControlActionEnum::Stop);

        $taken = $channel->takeAll(notBefore: 0);

        self::assertCount(1, $taken);
        self::assertSame(ControlActionEnum::Stop, $taken[0]->action);
        self::assertSame(ControlCommandDto::ALL, $taken[0]->target);

        self::assertSame([], $channel->takeAll(notBefore: 0));
    }

    /**
     * Commands are appended, not written over: two sent inside one poll window must both
     * arrive, or a script issuing `stop --task=a` then `stop --task=b` silently loses
     * the first.
     */
    #[Test]
    public function twoCommandsSentBeforeAPollBothArrive(): void
    {
        $channel = $this->channel();

        $channel->send(ControlActionEnum::Stop, target: 'counting');
        $channel->send(ControlActionEnum::Restart, target: 'idle');

        $taken = $channel->takeAll(notBefore: 0);

        self::assertCount(2, $taken);
        self::assertSame('counting', $taken[0]->target);
        self::assertSame('idle', $taken[1]->target);
    }

    /**
     * The timestamp is what holds when clearing does not happen — a pool killed between
     * reading and clearing would otherwise come back up, find its own stop still sitting
     * there and stop again, for as long as the supervisor kept restarting it.
     */
    #[Test]
    public function aCommandOlderThanTheCutoffIsIgnored(): void
    {
        $channel = $this->channel();

        $channel->send(ControlActionEnum::Stop);

        self::assertSame([], $channel->takeAll(notBefore: microtime(true) + 1));
    }

    protected function channel(): ControlChannel
    {
        return $this->getApp()->make(ControlChannel::class);
    }
}
