<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;
use SConcur\Laravel\Ws\Bus\LocalBroadcastBus;

/**
 * The bus that goes no further than this process.
 *
 * It is a test double rather than a lighter default, and the README says so: with it an
 * http worker's broadcast never reaches a ws worker at all.
 */
class LocalBroadcastBusTest extends BaseTestCase
{
    private LocalBroadcastBus $bus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bus = new LocalBroadcastBus();
    }

    #[Test]
    public function itNeedsNoCoroutineOfItsOwn(): void
    {
        self::assertFalse($this->bus->needsCoroutine());
    }

    #[Test]
    public function itRemembersWhatWasPublished(): void
    {
        $this->bus->publish($this->message('a'));
        $this->bus->publish($this->message('b'));

        self::assertSame(['a', 'b'], array_map(
            static fn(BroadcastMessageDto $message): string => $message->event,
            $this->bus->published(),
        ));
    }

    #[Test]
    public function publishingWithNobodyListeningIsQuiet(): void
    {
        $this->bus->publish($this->message('a'));

        self::assertCount(1, $this->bus->published());
    }

    #[Test]
    public function aSubscriberGetsWhatComesAfterIt(): void
    {
        $seen = [];

        $this->bus->subscribe(
            handler: static function (BroadcastMessageDto $message) use (&$seen): void {
                $seen[] = $message->event;
            },
            shouldContinue: static fn(): bool => true,
        );

        $this->bus->publish($this->message('a'));

        self::assertSame(['a'], $seen);
    }

    #[Test]
    public function flushForgetsTheRecord(): void
    {
        $this->bus->publish($this->message('a'));

        $this->bus->flush();

        self::assertSame([], $this->bus->published());
    }

    private function message(string $event): BroadcastMessageDto
    {
        return new BroadcastMessageDto(channels: ['demo'], event: $event, data: '{}');
    }
}
