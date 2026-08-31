<?php

declare(strict_types=1);

namespace Demo\App\Jobs;

use Demo\App\Exceptions\DemoJobFailedException;
use Demo\App\Models\JobResult;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use SConcur\Features\Sleeper\Sleeper;

/**
 * An ordinary Laravel job. Nothing about it knows it will be consumed by a coroutine
 * pool — that is the point: the pool runs jobs through Illuminate\Queue\Worker::process(),
 * so events, $tries, $backoff and failed_jobs all behave as they always do.
 *
 * The pause is the one thing written for the runtime. Native sleep() would freeze the
 * whole process — every message the pool is holding, not just this one — and the demo
 * would show a pool that handles messages strictly one at a time. Sleeper suspends only
 * this coroutine.
 *
 * A payload of "fail" throws, so the failed_jobs path is reachable from the page.
 */
class DemoJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        public string $payload,
        public int $workMs = 1000,
    ) {
    }

    public function handle(): void
    {
        $startedAt = microtime(true);

        Sleeper::usleep($this->workMs * 1000);

        if ($this->payload === 'fail') {
            throw new DemoJobFailedException('the payload asked for a failure');
        }

        JobResult::query()->create([
            'payload'     => $this->payload,
            'worker_pid'  => getmypid() ?: 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
