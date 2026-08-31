<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

use SConcur\Features\Sql\Transaction;

/**
 * What every coroutine taking part in one transaction shares.
 *
 * The context stores this object beside a per-coroutine list of levels. A write
 * to the context copies the array into the writing coroutine's own map, but the
 * object inside it stays the same instance, so a coroutine that inherited the
 * transaction sees the same Transaction, the same owner and the same savepoint
 * counter as the one that opened it.
 *
 * The counter is why it has to be shared. Laravel names savepoints after the
 * current depth ('trans'.($transactions + 1)), and depth is now per-coroutine:
 * two sibling coroutines nesting inside one inherited transaction would both
 * compute 2 and both run `SAVEPOINT trans2`. MySQL drops the earlier savepoint
 * when a name is reused, so one coroutine's rollback would land on the other's.
 */
class TransactionState
{
    private int $savepointSequence = 0;

    public function __construct(
        public readonly Transaction $transaction,
        public readonly int $ownerFiberId,
    ) {
    }

    /**
     * The next savepoint name, unique within this transaction whichever coroutine
     * and whichever depth asks for it.
     */
    public function nextSavepointName(): string
    {
        return 'sc_sp_' . (++$this->savepointSequence);
    }
}
