<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * One entry of `sconcur.tasks.list`: which class to run and how long to wait after each
 * of the three tick outcomes.
 */
readonly class TaskDefinitionDto
{
    /**
     * @param class-string<TaskInterface> $task
     */
    public function __construct(
        public string $name,
        public string $task,
        public float $idle,
        public float $busy,
        public float $backoff,
    ) {
    }

    public function intervalFor(TickResultEnum $result): float
    {
        return match ($result) {
            TickResultEnum::Worked => $this->busy,
            TickResultEnum::Idle   => $this->idle,
            TickResultEnum::Failed => $this->backoff,
        };
    }
}
