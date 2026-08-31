<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database;

use Fiber;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Support\Collection;
use SConcur\Context\Context;

/**
 * The transactions manager, one per coroutine instead of one per process.
 *
 * Laravel binds `db.transactions` as a singleton and every connection is handed that one
 * instance, which keeps its pending and committed records in three properties keyed by
 * connection name — not by whoever opened the transaction. Under coroutines two of them
 * on the same connection are indistinguishable to it: coroutine A's commit calls
 * stageTransactions() for that connection name and then runs executeCallbacks() on
 * everything staged, B's records among them, while B's transaction is still open. A
 * rollback is worse — removeAllTransactionsForConnection() fires every other coroutine's
 * rollback callbacks.
 *
 * This is not hypothetical bookkeeping: Model::saveOrFail() is a transaction, so an
 * ordinary create on a busy connection drives it.
 *
 * The state cannot be moved into the context field by field. Callbacks run inside these
 * methods, and a callback may dispatch a job or write to Mongo — a suspension point — so
 * loading state at the top and storing it at the bottom would let a neighbour overwrite
 * it in between. Instead each coroutine gets a whole manager of its own and this object,
 * the one the container hands out, only routes to it. Reads walk up the context chain, so
 * a coroutine spawned inside a transaction shares its parent's manager, exactly as it
 * shares the transaction itself.
 */
class CoroutineTransactionsManager extends DatabaseTransactionsManager
{
    protected const string CONTEXT_KEY = 'sconcur.db.transactions';

    /**
     * The manager for callers that are not in a coroutine at all.
     *
     * Held here rather than in the root context, which every coroutine reads through: a
     * manager left there before the first fiber existed would be shared by all of them,
     * which is the failure this class exists to prevent. Without fibers there is one
     * caller, so one manager is exactly right — and it makes the class safe to register
     * in a process that never starts a coroutine.
     */
    protected ?DatabaseTransactionsManager $outsideCoroutine = null;

    /** @inheritDoc */
    public function begin($connection, $level)
    {
        $this->delegate()->begin($connection, $level);
    }

    /**
     * @inheritDoc
     *
     * @return array<array-key, mixed>
     */
    public function commit($connection, $levelBeingCommitted, $newTransactionLevel)
    {
        return $this->delegate()->commit($connection, $levelBeingCommitted, $newTransactionLevel);
    }

    /** @inheritDoc */
    public function stageTransactions($connection, $levelBeingCommitted)
    {
        $this->delegate()->stageTransactions($connection, $levelBeingCommitted);
    }

    /** @inheritDoc */
    public function rollback($connection, $newTransactionLevel)
    {
        $this->delegate()->rollback($connection, $newTransactionLevel);
    }

    /** @inheritDoc */
    public function addCallback($callback)
    {
        $this->delegate()->addCallback($callback);
    }

    /** @inheritDoc */
    public function addCallbackForRollback($callback)
    {
        $this->delegate()->addCallbackForRollback($callback);
    }

    /**
     * @inheritDoc
     *
     * @return Collection<int, \Illuminate\Database\DatabaseTransactionRecord>
     */
    public function callbackApplicableTransactions()
    {
        return $this->delegate()->callbackApplicableTransactions();
    }

    /** @inheritDoc */
    public function afterCommitCallbacksShouldBeExecuted($level)
    {
        return $this->delegate()->afterCommitCallbacksShouldBeExecuted($level);
    }

    /**
     * @inheritDoc
     *
     * @return Collection<int, \Illuminate\Database\DatabaseTransactionRecord>
     */
    public function getPendingTransactions()
    {
        return $this->delegate()->getPendingTransactions();
    }

    /**
     * @inheritDoc
     *
     * @return Collection<int, \Illuminate\Database\DatabaseTransactionRecord>
     */
    public function getCommittedTransactions()
    {
        return $this->delegate()->getCommittedTransactions();
    }

    /**
     * The manager of the coroutine that is asking, created on first use.
     *
     * A plain DatabaseTransactionsManager and not another of these: the routing happens
     * once, here, and what it routes to is the framework's own implementation with its
     * behaviour untouched.
     */
    protected function delegate(): DatabaseTransactionsManager
    {
        if (Fiber::getCurrent() === null) {
            return $this->outsideCoroutine ??= new DatabaseTransactionsManager();
        }

        $context = Context::current();

        $manager = $context->find(static::CONTEXT_KEY);

        if ($manager instanceof DatabaseTransactionsManager) {
            return $manager;
        }

        $manager = new DatabaseTransactionsManager();

        $context->set(static::CONTEXT_KEY, $manager, replace: true);

        return $manager;
    }
}
