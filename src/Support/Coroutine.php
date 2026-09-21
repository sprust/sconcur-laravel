<?php

declare(strict_types=1);

namespace SConcur\Laravel\Support;

use Fiber;

/**
 * Whether the calling code runs in a coroutine the extension drives — the one question a
 * cooperative replacement of a native call asks before it takes over. Outside a coroutine
 * there is nothing to yield to, so the native call is the right one there.
 */
class Coroutine
{
    public static function isActive(): bool
    {
        return Fiber::getCurrent() !== null && extension_loaded('sconcur');
    }
}
