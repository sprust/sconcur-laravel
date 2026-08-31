<?php

declare(strict_types=1);

namespace Demo\App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SConcur\Features\Sleeper\Sleeper;
use SConcur\WaitGroup;

/**
 * The shortest demonstration there is: N pauses of the same length inside one request.
 * Run one after another they take N × ms; run as coroutines of one WaitGroup they take
 * about ms, in one process, on one thread.
 *
 * This is also the honest version of the comparison — the sequential leg uses the same
 * cooperative Sleeper, not native sleep(), so what the numbers differ by is the
 * concurrency and nothing else.
 */
class ConcurrencyController
{
    protected const int MAX_TASKS = 200;

    protected const int MAX_WORK_MS = 5000;

    /**
     * The sequential leg is skipped past this, because it would really take that long:
     * 200 tasks of 5 s each is a request that waits out a quarter of an hour to prove a
     * point the concurrent leg already made. Skipped, not estimated — a number nobody
     * measured does not belong beside one somebody did.
     */
    protected const int MAX_SEQUENTIAL_MS = 3000;

    /**
     * @param callable(): list<int> $callback
     */
    protected function measure(callable $callback): int
    {
        $startedAt = microtime(true);

        $callback();

        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    /**
     * @return list<int>
     */
    protected function concurrent(int $taskCount, int $workMs): array
    {
        $waitGroup = WaitGroup::create();

        for ($index = 0; $index < $taskCount; $index++) {
            $waitGroup->add(static function () use ($workMs, $index): int {
                Sleeper::usleep($workMs * 1000);

                return $index;
            });
        }

        return array_values($waitGroup->waitResults());
    }

    /**
     * @return list<int>
     */
    protected function sequential(int $taskCount, int $workMs): array
    {
        $results = [];

        for ($index = 0; $index < $taskCount; $index++) {
            Sleeper::usleep($workMs * 1000);

            $results[] = $index;
        }

        return $results;
    }

    public function __invoke(Request $request): JsonResponse
    {
        $taskCount = min(max((int) $request->query('n', '10'), 1), self::MAX_TASKS);
        $workMs    = min(max((int) $request->query('ms', '200'), 1), self::MAX_WORK_MS);

        $concurrentMs = $this->measure(fn(): array => $this->concurrent(taskCount: $taskCount, workMs: $workMs));

        $sequentialMs = $taskCount * $workMs <= self::MAX_SEQUENTIAL_MS
            ? $this->measure(fn(): array => $this->sequential(taskCount: $taskCount, workMs: $workMs))
            : null;

        return response()->json([
            'tasks'        => $taskCount,
            'workMs'       => $workMs,
            'concurrentMs' => $concurrentMs,
            'sequentialMs' => $sequentialMs,
            // How many times over the process would have waited had the pauses been
            // taken one at a time.
            'speedup'      => ($sequentialMs !== null && $concurrentMs > 0)
                ? round($sequentialMs / $concurrentMs, 2)
                : null,
            'workerPid'    => getmypid() ?: 0,
        ]);
    }
}
