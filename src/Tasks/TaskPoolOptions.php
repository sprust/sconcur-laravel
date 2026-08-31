<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * The `sconcur.tasks` settings the pool itself runs on (the task list is the registry's
 * business).
 */
readonly class TaskPoolOptions
{
    public function __construct(
        public string $controlKey,
        public string $lockPath,
        public int $memoryMb,
        public int $sleepChunkMs,
        public int $preemptionQuantumMs,
        public int $shutdownTimeoutSeconds,
        public bool $reportTicks = true,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            controlKey: (string) ($config['control_key'] ?? 'sconcur:tasks:control'),
            lockPath: (string) ($config['lock_path'] ?? sys_get_temp_dir() . '/sconcur-tasks.lock'),
            memoryMb: (int) ($config['memory_mb'] ?? 256),
            sleepChunkMs: max(1, (int) ($config['sleep_chunk_ms'] ?? 250)),
            preemptionQuantumMs: (int) ($config['preemption_quantum_ms'] ?? 1000),
            shutdownTimeoutSeconds: (int) ($config['shutdown_timeout_seconds'] ?? 20),
            reportTicks: (bool) ($config['report_ticks'] ?? true),
        );
    }

    public function memoryLimitBytes(): int
    {
        return $this->memoryMb * 1024 * 1024;
    }
}
