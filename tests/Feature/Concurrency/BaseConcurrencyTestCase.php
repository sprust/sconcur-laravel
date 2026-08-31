<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Concurrency;

use Closure;
use Fiber;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\State;

/**
 * What a coroutine runtime does to this package, made deterministic.
 *
 * Neither the extension nor the scheduler is needed for it. The coroutine context keys
 * off spl_object_id(Fiber::getCurrent()) (SConcur\State::currentContextFiberId), so a
 * plain PHP fiber gets a context of its own without being registered anywhere — which
 * is exactly the condition every adapter here is written for. Each unit says where it
 * yields and the units are resumed in a fixed order, so what these tests assert is an
 * interleaving rather than a race.
 */
abstract class BaseConcurrencyTestCase extends BaseTestCase
{
    /**
     * Starts every unit, then resumes them in turn until all have finished.
     *
     * Each fiber is registered against the context it was created in, which is what
     * Scheduler::spawn and WaitGroup::add do before starting a coroutine. Without it a
     * coroutine would not inherit anything from the process root, and a test would be
     * asserting against conditions the runtime never produces. Registration is undone
     * afterwards, the way the scheduler releases a finished coroutine's context — the
     * ids are spl_object_id values and get reused once the fibers are collected.
     *
     * @param list<Closure(): void> $units
     */
    protected function interleave(array $units): void
    {
        $parentFiberId = State::currentContextFiberId();

        $fibers = array_map(static fn(Closure $unit): Fiber => new Fiber($unit), $units);

        foreach ($fibers as $fiber) {
            State::registerCoroutineContext(
                fiberId: spl_object_id($fiber),
                parentFiberId: $parentFiberId,
            );
        }

        try {
            foreach ($fibers as $fiber) {
                $fiber->start();
            }

            while (true) {
                $resumed = false;

                foreach ($fibers as $fiber) {
                    if (!$fiber->isSuspended()) {
                        continue;
                    }

                    $fiber->resume();

                    $resumed = true;
                }

                if (!$resumed) {
                    return;
                }
            }
        } finally {
            foreach ($fibers as $fiber) {
                State::unRegisterFiber(spl_object_id($fiber));
            }
        }
    }

    /** Hands the turn to the next coroutine, the way suspending on a task would. */
    protected function yieldToOthers(): void
    {
        Fiber::suspend();
    }
}
