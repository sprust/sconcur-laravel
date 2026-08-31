<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database;

use Fiber;
use SConcur\Context\Context;

/**
 * Where per-caller database state is kept, whichever kind of process this is.
 *
 * Inside a coroutine it is the coroutine context, so concurrent callers are isolated and
 * a coroutine spawned inside a transaction inherits it. Outside one it is an array of
 * this object's own — deliberately not the root context, even though the root context is
 * what `Context::current()` would give.
 *
 * That distinction is the whole reason this class exists. Context reads walk up the chain
 * of spawning coroutines to the process root, and the root is never released, so anything
 * stored there is visible to every coroutine for the rest of the process. A transaction
 * opened before any fiber existed would then be joined by every unrelated request,
 * message and task at once. Keeping the synchronous path outside the context makes that
 * impossible, and costs nothing: without fibers there is one caller anyway.
 *
 * One store belongs to one connection, so two connections cannot see each other's state.
 */
class TransactionStore
{
    /** @var array<string, mixed> the synchronous path, where there is a single caller */
    protected array $outsideCoroutine = [];

    public function find(string $key): mixed
    {
        return Fiber::getCurrent() === null
            ? ($this->outsideCoroutine[$key] ?? null)
            : Context::current()->find($key);
    }

    public function set(string $key, mixed $value): void
    {
        if (Fiber::getCurrent() === null) {
            $this->outsideCoroutine[$key] = $value;

            return;
        }

        Context::current()->set($key, $value, replace: true);
    }

    public function forget(string $key): void
    {
        if (Fiber::getCurrent() === null) {
            unset($this->outsideCoroutine[$key]);

            return;
        }

        Context::current()->forget($key);
    }
}
