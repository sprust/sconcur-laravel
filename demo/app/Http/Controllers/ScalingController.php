<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Support\ScalingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Changing the size of the pools from the page: how many worker processes the HTTP and
 * consumer groups run, and how many consumers the queue gets in each of them.
 *
 * The numbers are stored, and the roll that puts them in force is left to the task pool
 * (ScalingTask) — see there for why it cannot happen in this request.
 */
class ScalingController
{
    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => ScalingSettings::current(),
            'limits'   => ScalingSettings::limits(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $limits = ScalingSettings::limits();

        $data = $request->validate([
            'httpWorkers' => [
                'required', 'integer',
                'min:' . $limits['httpWorkers']['min'], 'max:' . $limits['httpWorkers']['max'],
            ],
            'rabbitmqWorkers' => [
                'required', 'integer',
                'min:' . $limits['rabbitmqWorkers']['min'], 'max:' . $limits['rabbitmqWorkers']['max'],
            ],
            'rabbitmqCoroutines' => [
                'required', 'integer',
                'min:' . $limits['rabbitmqCoroutines']['min'], 'max:' . $limits['rabbitmqCoroutines']['max'],
            ],
        ]);

        $before = ScalingSettings::current();
        $after  = ScalingSettings::store($data);

        // Only the groups whose numbers moved: rolling the HTTP pool because the queue's
        // consumer count changed would drop every connection for nothing.
        $groups = [];

        if ($after['httpWorkers'] !== $before['httpWorkers']) {
            $groups[] = 'http';
        }

        if ($after['rabbitmqWorkers'] !== $before['rabbitmqWorkers']
            || $after['rabbitmqCoroutines'] !== $before['rabbitmqCoroutines']
        ) {
            $groups[] = 'rabbitmq';
        }

        ScalingSettings::requestReload($groups);

        return response()->json([
            'settings' => $after,
            'rolling'  => $groups,
            // The pool picks the request up on its next tick; the workers table shows
            // the new counts once the roll is through.
            'note'     => $groups === []
                ? 'nothing changed, no reload needed'
                : 'the task pool is rolling: ' . implode(', ', $groups),
        ], 202);
    }
}
