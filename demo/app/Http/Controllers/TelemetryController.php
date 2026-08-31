<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Telemetry\SconcurStatClient;
use Illuminate\Http\JsonResponse;

/**
 * The master's telemetry, as the page reads it. The panel is not exposed to the browser
 * directly: it wants a bearer token, and handing that to a page would put it in every
 * viewer's devtools.
 */
class TelemetryController
{
    public function index(SconcurStatClient $sconcurStatClient): JsonResponse
    {
        return response()->json($sconcurStatClient->find());
    }
}
