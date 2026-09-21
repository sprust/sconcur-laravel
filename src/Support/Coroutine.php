<?php

declare(strict_types=1);

namespace SConcur\Laravel\Support;

use Fiber;
use SConcur\Scheduler\Scheduler;
use SConcur\State;

/**
 * Whether the calling code runs in a coroutine the extension drives and may still wait in
 * — the one question a cooperative replacement of a native call asks before it takes over.
 *
 * Not any fiber: one another library started (amphp, Revolt, a plain Fiber) is not the
 * extension's to suspend. And not a coroutine being unwound: once WaitGroup::stop() or a
 * shutdown has let it go, a suspension there is never resumed, so a `finally` block that
 * waited would hang for good. In both cases the native call is the right one.
 */
class Coroutine
{
    public static function isActive(): bool
    {
        $fiber = Fiber::getCurrent();

        if ($fiber === null || !extension_loaded('sconcur')) {
            return false;
        }

        return State::isAsyncFiber(spl_object_id($fiber)) && Scheduler::get()->canAwait();
    }
}
