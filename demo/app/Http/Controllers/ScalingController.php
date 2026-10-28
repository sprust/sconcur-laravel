<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Support\ScalingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Changing the size of the pools from the page: how many worker processes the HTTP,
 * consumer and ws groups run, and how many consumers the queue gets in each of them.
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
            'wsWorkers' => [
                'required', 'integer',
                'min:' . $limits['wsWorkers']['min'], 'max:' . $limits['wsWorkers']['max'],
            ],
        ]);

        $before = ScalingSettings::current();
        $after  = ScalingSettings::store($data);

        // The groups whose numbers moved. A new master is the only way to new numbers and
        // it brings every pool up again, so they decide whether a restart is needed at
        // all, not which pools take it.
        $groups = [];

        if ($after['httpWorkers'] !== $before['httpWorkers']) {
            $groups[] = 'http';
        }

        if ($after['rabbitmqWorkers'] !== $before['rabbitmqWorkers']
            || $after['rabbitmqCoroutines'] !== $before['rabbitmqCoroutines']
        ) {
            $groups[] = 'rabbitmq';
        }

        if ($after['wsWorkers'] !== $before['wsWorkers']) {
            $groups[] = 'ws';
        }

        ScalingSettings::requestRestart($groups);

        return response()->json([
            'settings' => $after,
            'changed'  => $groups,
            // The pool picks the request up on its next tick; the workers table shows
            // the new counts once the new master is up.
            'note'     => $groups === []
                ? 'nothing changed, no restart needed'
                : 'the task pool is restarting the master for: ' . implode(', ', $groups),
        ], 202);
    }
}
