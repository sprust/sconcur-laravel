<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws\Bus;

use Closure;

/**
 * A bus that goes no further than this process.
 *
 * For tests, and for a single-process run where the publisher and the connections are the
 * same program. It delivers nothing between processes, which means an application whose
 * http workers publish and whose ws workers listen gets silence — so it is not a
 * lightweight default, it is a test double, and the README says so.
 *
 * @internal
 */
class LocalBroadcastBus implements BroadcastBusInterface
{
    /** @var null|Closure(BroadcastMessageDto): void */
    private ?Closure $handler = null;

    /** @var list<BroadcastMessageDto> */
    private array $published = [];

    public function publish(BroadcastMessageDto $message): void
    {
        $this->published[] = $message;

        if ($this->handler === null) {
            return;
        }

        ($this->handler)($message);
    }

    public function subscribe(Closure $handler, Closure $shouldContinue): void
    {
        $this->handler = $handler;
    }

    public function needsCoroutine(): bool
    {
        return false;
    }

    /**
     * Everything published so far, for a test to assert on.
     *
     * @return list<BroadcastMessageDto>
     */
    public function published(): array
    {
        return $this->published;
    }

    public function flush(): void
    {
        $this->published = [];
    }
}
