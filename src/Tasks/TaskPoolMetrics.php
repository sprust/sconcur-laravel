<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

/**
 * Counts what the pool's ticks did, in the shape the telemetry panel already knows.
 *
 * A tick that found work is to a task what a delivery is to a queue consumer — a unit of
 * work handed to PHP, held for a while, and finished or failed — so the numbers go out as
 * the snapshot's `consumers` section and the panel's own "In-flight / Handled / Refused"
 * columns fill in with no changes on either side.
 *
 * An idle tick is counted by none of them. A task polling every second for work that is
 * not there is the consumer's empty wait, not a delivery: counting those would make
 * Handled a measure of the poll interval, and would drag avgMs down to the cost of an
 * empty poll rather than the cost of the work.
 *
 * The one place that mapping shows through: the master sums this section across every
 * worker, so its master-level "deliveries per second" and average duration will include
 * the pool's ticks alongside the AMQP pool's real deliveries. Per-group numbers stay
 * clean. Set `report_ticks` to false to keep the pool out of those totals.
 */
class TaskPoolMetrics
{
    protected int $started = 0;

    protected int $handled = 0;

    protected int $refused = 0;

    protected float $totalSeconds = 0;

    /** @var array<string, float> task name => when its current tick began */
    protected array $inFlight = [];

    public function __construct(protected int $tasks)
    {
    }

    public function tickStarted(string $name): void
    {
        $this->inFlight[$name] = microtime(true);
    }

    public function tickFinished(string $name, TickResultEnum $result): void
    {
        $startedAt = $this->inFlight[$name] ?? null;

        unset($this->inFlight[$name]);

        // Nothing to do is not work done. Only the outcome tells us which it was, which
        // is why the decision is here and not where the tick began.
        if ($startedAt === null || $result === TickResultEnum::Idle) {
            return;
        }

        ++$this->started;

        $this->totalSeconds += microtime(true) - $startedAt;

        if ($result === TickResultEnum::Failed) {
            ++$this->refused;

            return;
        }

        ++$this->handled;
    }

    /**
     * The snapshot's workload section.
     *
     * @return array<string, int|float>
     */
    public function section(): array
    {
        $timed = $this->handled + $this->refused;
        $now   = microtime(true);

        return [
            // One coroutine per task, which is the capacity inFlight is spent out of —
            // the same meaning the consumer pool gives it.
            'coroutines' => $this->tasks,
            // Ticks that had work, whatever came of it; an idle poll is not one.
            'delivered'  => $this->started,
            'acked'      => $this->handled,
            'refused'    => $this->refused,
            'timed'      => $timed,
            'avgMs'      => $timed > 0 ? round($this->totalSeconds / $timed * 1000, 3) : 0.0,
            'inFlight'   => count($this->inFlight),
            ...$this->buckets($now),
        ];
    }

    /**
     * In-flight ticks by age. The buckets do not overlap: a tick running for seven
     * seconds is only in the middle one.
     *
     * @return array<string, int>
     */
    protected function buckets(float $now): array
    {
        $buckets = ['inFlight1to5s' => 0, 'inFlight5to15s' => 0, 'inFlightOver15s' => 0];

        foreach ($this->inFlight as $startedAt) {
            $age = $now - $startedAt;

            if ($age >= 15) {
                ++$buckets['inFlightOver15s'];
            } elseif ($age >= 5) {
                ++$buckets['inFlight5to15s'];
            } elseif ($age >= 1) {
                ++$buckets['inFlight1to5s'];
            }
        }

        return $buckets;
    }
}
