<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Support\ScalingSettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

/**
 * The page itself and the liveness probe behind `make setup`.
 */
class DemoController
{
    public function index(): View
    {
        return view('demo', [
            'connection' => (string) config('database.default'),
            'queue'      => (string) config('queue.default'),
            'workerPid'  => getmypid() ?: 0,
            // Rendered into the fields rather than fetched after load: the page would
            // otherwise show empty boxes for a moment and, worse, a reader could type
            // into one before the answer arrived and have it overwritten.
            'scaling'       => ScalingSettings::current(),
            'scalingLimits' => ScalingSettings::limits(),
        ]);
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'ok'         => true,
            'connection' => (string) config('database.default'),
            'workerPid'  => getmypid() ?: 0,
        ]);
    }
}
