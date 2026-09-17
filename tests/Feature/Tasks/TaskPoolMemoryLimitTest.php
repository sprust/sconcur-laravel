<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Support\ProcessMemory;
use SConcur\Laravel\Tasks\Control\ControlActionEnum;
use SConcur\Laravel\Tasks\Control\ControlChannel;
use SConcur\Laravel\Tasks\TaskPool;
use SConcur\Laravel\Tasks\TaskPoolLogger;
use SConcur\Laravel\Tasks\TaskPoolOptions;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The memory limit is the one stop that asks for a fresh process, and it has to see the
 * memory a native leak takes: the extension allocates outside the PHP heap, so a limit
 * compared against the heap alone would let the process grow until the container is
 * killed.
 */
class TaskPoolMemoryLimitTest extends BaseTestCase
{
    protected string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = (string) tempnam(sys_get_temp_dir(), 'sconcur-task-pool-log');

        $this->getApp()->instance(TaskPoolLogger::class, new TaskPoolLogger(stream: $this->logPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->logPath);

        parent::tearDown();
    }

    /** The PHP heap is far under the limit here; only the resident set size is over it. */
    #[Test]
    public function aProcessOverTheLimitOutsideTheHeapStopsForARestart(): void
    {
        $this->fakeRssBytes(1024 * 1024 * 1024);

        $exitCode = $this->getApp()->make(TaskPool::class)->run(only: ['idle']);

        self::assertSame(TaskPool::EXIT_RESTART, $exitCode);
        self::assertStringContainsString('memory limit reached (rss 1024 MiB)', $this->log());
    }

    /** Where /proc is not there the RSS reads as zero, and the heap still stops the pool. */
    #[Test]
    public function withoutAnRssReadingTheHeapStillStopsThePool(): void
    {
        $this->fakeRssBytes(0);

        $options = $this->getApp()->make(TaskPoolOptions::class);

        $this->getApp()->instance(
            TaskPoolOptions::class,
            new TaskPoolOptions(
                controlKey: $options->controlKey,
                lockPath: $options->lockPath,
                memoryMb: 1,
                sleepChunkMs: $options->sleepChunkMs,
                preemptionQuantumMs: $options->preemptionQuantumMs,
                shutdownTimeoutSeconds: $options->shutdownTimeoutSeconds,
                reportTicks: $options->reportTicks,
            ),
        );

        $exitCode = $this->getApp()->make(TaskPool::class)->run(only: ['idle']);

        self::assertSame(TaskPool::EXIT_RESTART, $exitCode);
        self::assertStringContainsString('memory limit reached (heap ', $this->log());
    }

    #[Test]
    public function theRssOfThisProcessIsRead(): void
    {
        if (!is_readable('/proc/self/status')) {
            self::markTestSkipped('/proc is not available');
        }

        $rssBytes = new ProcessMemory()->rssBytes();

        self::assertGreaterThanOrEqual(memory_get_usage(true), $rssBytes);
    }

    /**
     * A pool that ignores the reading would tick for ever, so after enough controller
     * passes to have noticed — a few seconds at the workbench's 10 ms chunk — the fake
     * stops the pool by hand. A regression then fails on the exit code instead of hanging
     * the suite.
     */
    protected function fakeRssBytes(int $rssBytes): void
    {
        $this->getApp()->instance(
            ProcessMemory::class,
            new class($rssBytes, $this->getApp()->make(ControlChannel::class)) extends ProcessMemory {
                protected int $reads = 0;

                public function __construct(
                    protected int $fakeRssBytes,
                    protected ControlChannel $controlChannel,
                ) {
                }

                public function rssBytes(): int
                {
                    if (++$this->reads === 300) {
                        $this->controlChannel->send(ControlActionEnum::Stop);
                    }

                    return $this->fakeRssBytes;
                }
            },
        );
    }

    protected function log(): string
    {
        return (string) file_get_contents($this->logPath);
    }
}
