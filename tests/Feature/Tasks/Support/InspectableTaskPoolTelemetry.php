<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks\Support;

use SConcur\Laravel\Support\ProcessMemory;
use SConcur\Laravel\Tasks\TaskPoolTelemetry;

/**
 * The pool's telemetry sender with the snapshot it would push reachable, at a clock the
 * test sets. Nothing is sent: the socket path leads nowhere, and push() is never called.
 */
class InspectableTaskPoolTelemetry extends TaskPoolTelemetry
{
    public function __construct(ProcessMemory $processMemory)
    {
        parent::__construct(
            socketPath: '/nonexistent',
            name: 'tasks:0',
            processMemory: $processMemory,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function at(float $now): array
    {
        return $this->snapshot($now);
    }
}
