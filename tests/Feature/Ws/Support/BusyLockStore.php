<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Contracts\Cache\Lock;

/**
 * A store whose lock is always held by somebody else.
 *
 * It stands in for the other worker of a pool: the presence repository has to give up
 * after its retries rather than write over a change it cannot see, and it has to give up
 * without stopping the worker while it waits.
 */
class BusyLockStore extends ArrayStore
{
    public int $attempts = 0;

    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return new NeverFreeLock($this);
    }
}
