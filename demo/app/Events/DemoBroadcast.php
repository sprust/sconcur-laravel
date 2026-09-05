<?php

declare(strict_types=1);

namespace Demo\App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * What the demo page listens for.
 *
 * ShouldBroadcastNow rather than ShouldBroadcast: queued broadcasting would prove the
 * queue works, which the demo already shows elsewhere. Here the point is the hop between
 * processes — this is raised in an http worker and arrives in a browser connected to a
 * ws one.
 */
class DemoBroadcast implements ShouldBroadcastNow
{
    use Dispatchable;

    // Not decoration, and the reason the class is not readonly: toOthers() writes the
    // caller's socket id into the $socket property this trait declares, and the payload
    // carries it from there. Without the trait toOthers() is silently a no-op — the
    // sender sees its own message like everybody else.
    use InteractsWithSockets;

    public function __construct(
        public readonly string $text,
        public readonly string $source,
        public readonly int $workerPid,
        public readonly float $sentAt,
    ) {
    }

    /**
     * @return list<Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('demo'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'demo.message';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'text'      => $this->text,
            'source'    => $this->source,
            'workerPid' => $this->workerPid,
            'sentAt'    => $this->sentAt,
        ];
    }
}
