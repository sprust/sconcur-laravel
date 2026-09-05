<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Events\DemoBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Publishes one event to the ws pool.
 *
 * It answers from an http worker and the page is connected to a ws worker, so a message
 * arriving on the socket is the whole demonstration: the two are different processes, and
 * what carried the event between them is the bus.
 */
class BroadcastController
{
    public function store(Request $request): JsonResponse
    {
        $text = trim((string) $request->input('text', ''));

        if ($text === '') {
            return response()->json(['message' => 'text is required'], 422);
        }

        $workerPid = (int) getmypid();

        $pending = broadcast(new DemoBroadcast(
            text: mb_substr($text, 0, 200),
            source: 'http worker',
            workerPid: $workerPid,
            sentAt: microtime(true),
        ));

        // toOthers() reads the X-Socket-ID header the page sends and puts it on the
        // message, and every ws worker then skips that one connection. With the box
        // ticked the sender is the only browser that does not see its own message.
        if ($request->boolean('others')) {
            $pending->toOthers();
        }

        return response()->json([
            'published' => true,
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
