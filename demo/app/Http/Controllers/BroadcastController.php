<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Events\DemoBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publishes events to the ws pool.
 *
 * It answers from an http worker and the page is connected to a ws worker, so a message
 * arriving back on the socket is the whole demonstration: the two are different
 * processes, and what carried the event between them is the bus.
 */
class BroadcastController
{
    protected const int MAX_COUNT = 50;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text'   => ['required', 'string', 'max:200'],
            'count'  => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_COUNT],
            'others' => ['nullable', 'boolean'],
        ]);

        $count = (int) ($data['count'] ?? 1);

        $workerPid = getmypid() ?: 0;

        for ($number = 1; $number <= $count; $number++) {
            // Numbered on the way out, so the page can see the order they arrive in — a
            // burst that comes back shuffled would otherwise look like one message.
            $pending = broadcast(new DemoBroadcast(
                text: $number . ' ' . $data['text'],
                source: 'http worker',
                workerPid: $workerPid,
                sentAt: microtime(true),
            ));

            // toOthers() reads the X-Socket-ID header the page sends and puts it on the
            // message; every ws worker then skips that one connection. With the box
            // ticked the sender is the only browser that does not see its own messages.
            if ($request->boolean('others')) {
                $pending->toOthers();
            }
        }

        return response()->json([
            'published' => $count,
            'workerPid' => $workerPid,
            'toOthers'  => $request->boolean('others'),
        ]);
    }

    /** What the page needs to open its socket: the key and the path, never the secret. */
    public function config(): JsonResponse
    {
        return response()->json([
            'key'                    => (string) config('sconcur.ws.app_key'),
            'pathPrefix'             => (string) config('sconcur.ws.path_prefix', '/app'),
            'activityTimeoutSeconds' => (int) config('sconcur.ws.activity_timeout_seconds', 120),
            'channel'                => 'demo',
        ]);
    }
}
