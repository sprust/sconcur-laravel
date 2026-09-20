<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use SConcur\Features\Amqp\Channel;
use SConcur\Laravel\Ws\Bus\AmqpBroadcastBus;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;

/**
 * The AMQP bus with every channel its publisher opens kept where a test can see it.
 *
 * How often the publisher dials is the whole of what the idle limit and the retry decide,
 * and neither is visible from the outside: a publish that went through says nothing about
 * the channel it went through on. The channels themselves also give a test the one thing
 * it cannot do otherwise — close one behind the bus's back, which is what the extension's
 * collector does to a channel that has run no command for half an hour.
 */
class RecordingPublisherBus extends AmqpBroadcastBus
{
    /** @var list<Channel> every channel the publisher has opened, oldest first */
    public array $openedChannels = [];

    /**
     * A publish on a channel the caller is holding, which is what a coroutine that came late
     * to a channel everybody was publishing on has: the one it holds is dead, and another
     * coroutine has already given it up and dialled a replacement. A single-threaded test
     * has no other way to stage that.
     */
    public function publishOnChannel(Channel $channel, BroadcastMessageDto $message): void
    {
        $this->publishOn(
            channel: $channel,
            message: $message,
        );
    }

    protected function openPublisher(): Channel
    {
        $channel = parent::openPublisher();

        $this->openedChannels[] = $channel;

        return $channel;
    }
}
