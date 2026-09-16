<?php

declare(strict_types=1);

namespace Workbench\App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Redis;
use RuntimeException;

/**
 * Records that it ran, and on which attempt, in a Redis list the queue test reads back. With
 * `failOnce` its first attempt throws, so the test can see the job released and retried.
 */
class RedisQueueProbeJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    public const string DONE_KEY = 'probe:done';

    public int $tries = 2;

    public function __construct(
        public string $label,
        public bool $failOnce = false,
    ) {
    }

    public function handle(): void
    {
        if ($this->failOnce && ($this->attempts() === 1)) {
            throw new RuntimeException('The first attempt fails on purpose.');
        }

        Redis::connection()->command('rpush', [self::DONE_KEY, $this->label . '#' . $this->attempts()]);
    }
}
