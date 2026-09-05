<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use Closure;
use RuntimeException;
use SConcur\Laravel\Ws\Bus\BroadcastBusInterface;
use SConcur\Laravel\Ws\Bus\BroadcastMessageDto;

/** A bus that cannot be subscribed to at all — a broker that is simply not there. */
class FailingBus implements BroadcastBusInterface
{
    public function publish(BroadcastMessageDto $message): void
    {
        throw new RuntimeException('bus is unreachable');
    }

    public function subscribe(Closure $handler, Closure $shouldContinue): void
    {
        throw new RuntimeException('bus is unreachable');
    }

    public function needsCoroutine(): bool
    {
        return true;
    }
}
