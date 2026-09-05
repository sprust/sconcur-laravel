<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use Closure;
use SConcur\Laravel\Ws\Bus\BroadcastBusInterface;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;

/**
 * A bus with a loop of its own, like the AMQP one, but synchronous.
 *
 * It exists to drive BusSubscriber's lifetime: the subscriber's flag, and the window
 * between deciding to stop and actually being gone. `$onStopping` is what a test uses to
 * act inside that window — the real one suspends there, closing a connection and
 * cancelling a consumer.
 */
class LoopingBus implements BroadcastBusInterface
{
    public int $subscribeCalls = 0;

    /** @var list<BroadcastMessageDto> */
    public array $published = [];

    /** Runs after the subscriber has decided to stop but before subscribe() returns. */
    public ?Closure $onStopping = null;

    /**
     * @param list<BroadcastMessageDto> $deliveries handed to the handler, one per turn
     */
    public function __construct(private readonly array $deliveries = [])
    {
    }

    public function publish(BroadcastMessageDto $message): void
    {
        $this->published[] = $message;
    }

    public function subscribe(Closure $handler, Closure $shouldContinue): void
    {
        ++$this->subscribeCalls;

        foreach ($this->deliveries as $delivery) {
            if (!$shouldContinue()) {
                break;
            }

            $handler($delivery);
        }

        // Asked once more so a subscriber whose registry emptied has somewhere to notice
        // it, exactly as the idle wake does on the real bus.
        $shouldContinue();

        if ($this->onStopping !== null) {
            ($this->onStopping)();
        }
    }

    public function needsCoroutine(): bool
    {
        return true;
    }
}
