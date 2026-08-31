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

    /**
     * Parks the task: it stops ticking but keeps its coroutine, so a relaunch can put it
     * back to work. Without the coroutine there would be nothing left to read the
     * relaunch request — which is what made `sconcur:tasks:restart --task=NAME` unable
     * to undo `sconcur:tasks:stop --task=NAME`.
     */
    public function deactivate(string $name): void
    {
        $this->active[$name] = false;
    }

    /** Puts a parked task back into service. */
    public function activate(string $name): void
    {
        $this->active[$name] = true;
    }

    /**
     * The tasks still ticking. Empty means there is nothing left to supervise, which is
     * how a pool whose tasks were all stopped one by one comes to an end.
     *
     * @return list<string>
     */
    public function activeNames(): array
    {
        return array_keys(array_filter($this->active));
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

    /**
     * What ends a parked task's wait: something to come back for, or the pool going away.
     * The interrupt above cannot serve here — it fires on exactly the condition a parked
     * task is already in, which would turn the wait into a busy loop.
     *
     * @return Closure(): bool
     */
    public function parkInterruptFor(string $name): Closure
    {
        return fn(): bool => $this->stopRequested
            || $this->isActive($name)
            || ($this->relaunch[$name] ?? false);
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
