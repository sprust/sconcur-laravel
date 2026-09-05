<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tasks;

use Closure;
use Fiber;
use SConcur\Features\Sleeper\Sleeper;

/**
 * The pool's only pause. Native sleep() would freeze the process — every coroutine of
 * it, not just the caller — so the wait goes through Sleeper, which suspends the calling
 * coroutine and lets the scheduler run everything else.
 *
 * The wait is cut into chunks for two reasons. A pause has to end early when what it was
 * waiting for is over (a task deactivated mid-sleep should not hold the shutdown for its
 * full interval), and PHP has to reach an opcode boundary regularly for a pending signal
 * handler to run at all — a process whose coroutines are all parked in the extension executes no PHP
 * and would not see SIGTERM. The servers of the library poll on the same 250 ms for the
 * same reason.
 */
readonly class CooperativeSleeper
{
    public function __construct(protected int $chunkMs = 250)
    {
    }

    /**
     * @param Closure(): bool|null $interrupt asked between chunks; true ends the wait early
     */
    public function sleep(float $seconds, ?Closure $interrupt = null): void
    {
        $deadline = microtime(true) + $seconds;
        $chunk    = $this->chunkMs * 1000;

        while (true) {
            if ($interrupt !== null && $interrupt()) {
                return;
            }

            $left = (int) round(($deadline - microtime(true)) * 1_000_000);

            if ($left <= 0) {
                return;
            }

            $this->pause(min($left, $chunk));
        }
    }

    /**
     * Outside a coroutine, or with no extension loaded, there is no scheduler to yield
     * to and the native call is the whole of it — which is what keeps a pool runnable
     * under plain CLI and in tests.
     */
    protected function pause(int $microseconds): void
    {
        if (Fiber::getCurrent() !== null && extension_loaded('sconcur')) {
            Sleeper::usleep($microseconds);

            return;
        }

        usleep($microseconds);
    }
}
