<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Models\Heartbeat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;

/**
 * The periodic task pool, as seen from outside it: a row whose counter grows on its own.
 * `make tasks-stop` freezes it, `make tasks-restart` starts it moving again — both from
 * a different container, through a cache key rather than a pid.
 */
class HeartbeatController
{
    public function index(): JsonResponse
    {
        /** @var Collection<int, Heartbeat> $heartbeats */
        $heartbeats = Heartbeat::query()
            ->orderBy('name')
            ->get(['name', 'ticks', 'worker_pid', 'updated_at']);

        return response()->json([
            // Field by field, not the model as it comes: the page reads these four, and
            // the answer says which ones rather than whatever the table grows next.
            'heartbeats' => $heartbeats->map(static fn(Heartbeat $heartbeat): array => [
                'name'       => $heartbeat->name,
                'ticks'      => $heartbeat->ticks,
                'worker_pid' => $heartbeat->worker_pid,
                'updated_at' => $heartbeat->updated_at?->toDateTimeString(),
            ])->all(),
            'now'        => now()->toDateTimeString(),
        ]);
    }
}
