<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

use RuntimeException;
use SConcur\Features\Sql\Transaction;
use SConcur\Laravel\Database\TransactionStore;
use SConcur\State;

/**
 * The open transaction of the calling coroutine, held in the coroutine context.
 *
 * Illuminate keeps the nesting depth in Connection::$transactions, a property of
 * an object that here serves every coroutine in the process, so it cannot stay
 * there. TransactionStore is the right place: inside a coroutine it is the coroutine
 * context, where reads walk up the chain of spawning coroutines and writes land in the
 * caller's own map; outside one it is a plain array, so a synchronous caller cannot leave
 * a transaction behind for every future coroutine to inherit.
 *
 * That gives two properties for free. Sibling coroutines — concurrent HTTP
 * requests, concurrent jobs — never see each other's transaction, because they
 * are siblings and not ancestors. A coroutine spawned inside a transaction does
 * see it and its statements join it, which is what makes a WaitGroup inside
 * DB::transaction() atomic instead of quietly autocommitting beside it.
 *
 * What the context does not give is a shared write: a coroutine that inherited
 * the transaction and committed it would leave the opener's own entry in place,
 * pointing at a finished transaction. Hence the owner recorded in
 * TransactionState and the check on every operation that closes the root level.
 */
class TransactionStack
{
    private const string KEY_PREFIX = 'sconcur.db.tx.';

    public function __construct(
        protected string $connectionName,
        protected TransactionStore $store,
    ) {
    }

    public function level(): int
    {
        return count($this->read()['levels'] ?? []);
    }

    /**
     * The transaction this coroutine is inside, its own or an inherited one.
     */
    public function transaction(): ?Transaction
    {
        return $this->state()?->transaction;
    }

    public function state(): ?TransactionState
    {
        $frame = $this->read();

        return $frame === null ? null : $frame['state'];
    }

    /**
     * Opens the root level. The coroutine calling this owns the transaction and
     * is the only one allowed to close it.
     */
    public function begin(Transaction $transaction): void
    {
        $this->write([
            'state'  => new TransactionState(
                transaction: $transaction,
                ownerFiberId: State::currentContextFiberId(),
            ),
            'levels' => [null],
        ]);
    }

    /**
     * Reserves the next savepoint name. Taken from the shared state, so it is
     * unique across every coroutine in this transaction.
     */
    public function nextSavepointName(): string
    {
        return $this->requireFrame('reserve a savepoint in')['state']->nextSavepointName();
    }

    public function pushSavepoint(string $name): void
    {
        $frame = $this->requireFrame('nest a transaction in');

        $frame['levels'][] = $name;

        $this->write($frame);
    }

    /**
     * The savepoint that opened the given level (1-based), or null for the root.
     */
    public function savepointAt(int $level): ?string
    {
        return $this->read()['levels'][$level - 1] ?? null;
    }

    /**
     * Drops the top level. Used when a nested level commits — Illuminate does not
     * RELEASE the savepoint either, it only stops counting it.
     */
    public function pop(): void
    {
        $frame = $this->requireFrame('leave a transaction level of');

        array_pop($frame['levels']);

        if ($frame['levels'] === []) {
            $this->clear();

            return;
        }

        $this->write($frame);
    }

    public function truncateTo(int $level): void
    {
        $frame = $this->requireFrame('roll back a transaction level of');

        $frame['levels'] = array_slice($frame['levels'], 0, $level);

        $this->write($frame);
    }

    public function clear(): void
    {
        $this->store->forget($this->key());
    }

    /**
     * A transaction is closed by the coroutine that opened it. Anything else
     * would commit or roll back on the extension side while the opener's own context
     * entry stayed behind, pointing at a transaction that no longer exists —
     * after which its statements fail on a dead id and its own commit is a
     * silent no-op, because Transaction::finish() is idempotent.
     */
    public function assertOwner(string $action): void
    {
        $state = $this->state();

        if ($state === null || $state->ownerFiberId === State::currentContextFiberId()) {
            return;
        }

        throw new RuntimeException(
            'Cannot ' . $action . ' a transaction of connection [' . $this->connectionName . ']'
            . ' from a coroutine that did not open it. A coroutine spawned inside a transaction may'
            . ' run statements in it, but the coroutine that opened it is the one that closes it.'
        );
    }

    /**
     * @return array{state: TransactionState, levels: list<string|null>}|null
     */
    protected function read(): ?array
    {
        $frame = $this->store->find($this->key());

        if (!is_array($frame)) {
            return null;
        }

        /** @var array{state: TransactionState, levels: list<string|null>} $frame */
        return $frame;
    }

    /**
     * @return array{state: TransactionState, levels: list<string|null>}
     */
    protected function requireFrame(string $action): array
    {
        $frame = $this->read();

        if ($frame === null) {
            throw new RuntimeException(
                'Cannot ' . $action . ' connection [' . $this->connectionName . ']: no transaction is open.'
            );
        }

        return $frame;
    }

    /**
     * @param array{state: TransactionState, levels: list<string|null>} $frame
     */
    protected function write(array $frame): void
    {
        $this->store->set($this->key(), $frame);
    }

    protected function key(): string
    {
        return self::KEY_PREFIX . $this->connectionName;
    }
}
