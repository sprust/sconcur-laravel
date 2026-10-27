<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Support\ProcessMemory;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Tasks\Support\InspectableTaskPoolTelemetry;

/**
 * The pool reports from PHP what every other worker reports from the extension, so its
 * snapshot has to carry the same memory fields: a group whose Pss stayed at zero would
 * pull the master's total under what the pool really takes.
 */
class TaskPoolTelemetryTest extends BaseTestCase
{
    #[Test]
    public function aSnapshotCarriesTheProportionalShareBesideTheResidentSet(): void
    {
        $memory = $this->snapshot(new ProcessMemory(), now: 1000.0)['memory'];

        self::assertSame(['rssBytes', 'pssBytes', 'swapPssBytes'], array_keys($memory));
        self::assertGreaterThan(0, $memory['pssBytes']);
        self::assertLessThanOrEqual($memory['rssBytes'], $memory['pssBytes']);
    }

    /** Memory is read every five seconds, the extension's cadence; snapshots in between repeat it. */
    #[Test]
    public function memoryIsReadEveryFiveSecondsRatherThanOnEverySnapshot(): void
    {
        $processMemory = new class extends ProcessMemory {
            public int $reads = 0;

            public function rssBytes(): int
            {
                return ++$this->reads * 1024;
            }

            public function proportionalShareBytes(): array
            {
                return [
                    'pssBytes'     => 512,
                    'swapPssBytes' => 0,
                ];
            }
        };

        $telemetry = $this->telemetry($processMemory);

        self::assertSame(1024, $telemetry->at(1000.0)['memory']['rssBytes']);
        self::assertSame(1024, $telemetry->at(1004.0)['memory']['rssBytes']);
        self::assertSame(2048, $telemetry->at(1005.0)['memory']['rssBytes']);
        self::assertSame(2, $processMemory->reads);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(ProcessMemory $processMemory, float $now): array
    {
        return $this->telemetry($processMemory)->at($now);
    }

    private function telemetry(ProcessMemory $processMemory): InspectableTaskPoolTelemetry
    {
        return new InspectableTaskPoolTelemetry($processMemory);
    }
}
