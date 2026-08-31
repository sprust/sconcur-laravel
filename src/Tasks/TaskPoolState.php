<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use Closure;

/**
 * What the pool and its controller agree on: which tasks are still meant to tick, which
 * are still inside one, and whether a stop has been asked for.
 *
 * The controller only ever writes here; it never touches a task's coroutine, because
 * nothing can. Every decision reaches a task the same way — its loop reads this state at
 * the one point it is between ticks.
 */
class TaskPoolState
{
    /** @var array<string, bool> */
    protected array $active = [];

    /** @var array<string, bool> */
    protected array $running = [];

    /** @var array<string, bool> */
    protected array $relaunch = [];

    protected bool $stopRequested = false;

    protected ?float $hardDeadlineAt = null;

    protected float $startedAt;

    /**
     * @param list<string> $names
     */
    public function __construct(array $names)
    {
        $this->startedAt = microtime(true);

        foreach ($names as $name) {
            $this->active[$name]  = true;
            $this->running[$name] = true;
        }
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->active);
    }

    public function isActive(string $name): bool
    {
        return $this->active[$name] ?? false;
    }

    public function deactivate(string $name): void
    {
        $this->active[$name] = false;
    }

    public function stopAll(): void
    {
        $this->stopRequested = true;

        foreach (array_keys($this->active) as $name) {
            $this->active[$name] = false;
        }
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }

    /** Marks the task's loop as finished; the controller waits for this to empty out. */
    public function markStopped(string $name): void
    {
        $this->running[$name] = false;
    }

    /** @return list<string> */
    public function runningNames(): array
    {
        return array_keys(array_filter($this->running));
    }

    /**
     * Asks for a fresh instance of the task. Honoured between ticks, like everything
     * else — a task in the middle of a long tick finishes it first.
     */
    public function requestRelaunch(string $name): void
    {
        $this->relaunch[$name] = true;
    }

    /** Reads the request and clears it, so one command rebuilds the task once. */
    public function takeRelaunch(string $name): bool
    {
        if (!($this->relaunch[$name] ?? false)) {
            return false;
        }

        unset($this->relaunch[$name]);

        return true;
    }

    /**
     * What ends a task's pause early: it is no longer meant to tick, or it is about to
     * be rebuilt. Without this a stop would wait out the full idle interval of every
     * sleeping task.
     *
     * @return Closure(): bool
     */
    public function interruptFor(string $name): Closure
    {
        return fn(): bool => !$this->isActive($name) || ($this->relaunch[$name] ?? false);
    }

    public function hardDeadlineAt(): ?float
    {
        return $this->hardDeadlineAt;
    }

    /** Set once when the stop starts, and again — as "now" — by a second signal. */
    public function setHardDeadlineAt(float $at): void
    {
        $this->hardDeadlineAt = $at;
    }
}
