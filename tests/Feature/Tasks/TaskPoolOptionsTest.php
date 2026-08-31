<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\TaskPoolOptions;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

class TaskPoolOptionsTest extends BaseTestCase
{
    #[Test]
    public function itIsBuiltFromThePublishedConfig(): void
    {
        $options = $this->getApp()->make(TaskPoolOptions::class);

        self::assertSame('sconcur:tasks:control', $options->controlKey);
        self::assertSame(128, $options->memoryMb);
        self::assertSame(5, $options->shutdownTimeoutSeconds);
        self::assertFalse($options->reportTicks);
    }

    /**
     * A sleep chunk of zero would spin: the pause is cut into chunks so PHP reaches an
     * opcode boundary often enough for a pending signal handler to run, and a chunk of
     * nothing is a busy loop instead.
     */
    #[Test]
    public function aSleepChunkBelowOneIsRaisedToOne(): void
    {
        $options = TaskPoolOptions::fromArray(['sleep_chunk_ms' => 0]);

        self::assertSame(1, $options->sleepChunkMs);
    }

    #[Test]
    public function theMemoryLimitIsReportedInBytes(): void
    {
        $options = TaskPoolOptions::fromArray(['memory_mb' => 64]);

        self::assertSame(64 * 1024 * 1024, $options->memoryLimitBytes());
    }
}
