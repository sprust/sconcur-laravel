<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws\Support;

use Illuminate\Cache\Lock;

/** A lock nobody ever gets, counting how many times it was asked. */
class NeverFreeLock extends Lock
{
    public function __construct(private readonly BusyLockStore $store)
    {
        parent::__construct('busy', 1);
    }

    public function acquire(): bool
    {
        ++$this->store->attempts;

        return false;
    }

    public function release(): bool
    {
        return true;
    }

    public function forceRelease(): void
    {
    }

    protected function getCurrentOwner(): string
    {
        return 'somebody-else';
    }
}
