<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Demo\App\Jobs\DemoJob;
use Demo\App\Models\JobResult;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The queue leg: dispatch to RabbitMQ over the sconcur_rabbitmq driver, and read back
 * what the consumer pool did with it.
 *
 * Dispatching several at once is what makes the pool visible — the rows come back
 * carrying one worker pid and overlapping durations, which is one process holding
 * several messages at the same time. A pool of blocking workers would need as many
 * processes for the same picture.
 */
class JobController
{
    protected const int MAX_BATCH = 50;

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payload' => ['nullable', 'string', 'max:255'],
            'count'   => ['nullable', 'integer', 'min:1', 'max:' . self::MAX_BATCH],
            'work_ms' => ['nullable', 'integer', 'min:0', 'max:10000'],
        ]);

        $payload = (string) ($data['payload'] ?? 'hello');
        $count   = (int) ($data['count'] ?? 1);
        $workMs  = (int) ($data['work_ms'] ?? 1000);

        for ($index = 0; $index < $count; $index++) {
            dispatch(new DemoJob(payload: $payload, workMs: $workMs));
        }

        return response()->json([
            'dispatched' => $count,
            'queue'      => (string) config('sconcur.queue.rabbitmq.queues.0'),
            'connection' => (string) config('queue.default'),
        ], 202);
    }

    public function index(): JsonResponse
    {
        /** @var Collection<int, JobResult> $results */
        $results = JobResult::query()
            ->latest('id')
            ->limit(20)
            ->get(['id', 'payload', 'worker_pid', 'duration_ms', 'created_at']);

        return response()->json([
            'total'   => JobResult::query()->count(),
            'failed'  => DB::table('failed_jobs')->count(),
            // Listed field by field: a row off sconcur_mysql carries no stable key order
            // (README, "Отличия от PDO"), and an answer whose keys move between requests
            // is unreadable on a page that polls.
            'results' => $results->map(static fn(JobResult $jobResult): array => [
                'id'          => $jobResult->id,
                'payload'     => $jobResult->payload,
                'worker_pid'  => $jobResult->worker_pid,
                'duration_ms' => $jobResult->duration_ms,
                'created_at'  => $jobResult->created_at?->toDateTimeString(),
            ])->all(),
        ]);
    }
}
