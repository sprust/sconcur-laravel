<?php

declare(strict_types=1);

namespace SConcur\Laravel\Database\Mysql;

use Closure;
use Exception;
use Generator;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use RuntimeException;
use SConcur\Exceptions\FlowStoppedException;
use SConcur\Laravel\Database\TransactionStore;
use SConcur\Features\Sql\Connection as SqlConnection;
use SConcur\Features\Sql\Results\ExecResult;
use SConcur\Features\Sql\Transaction;
use Throwable;

/**
 * A Laravel connection whose statements run through the SConcur SQL feature
 * instead of PDO. Inside a coroutine the call goes to the Go extension while the
 * coroutine suspends, so the neighbouring ones keep running; outside one the same
 * calls work synchronously.
 *
 * Extends MySqlConnection rather than the base Connection so the MySQL grammars,
 * the schema builder, the post processor and `instanceof MySqlConnection` all
 * keep working. Only the methods that would reach for a PDO handle are replaced,
 * and each of them still goes through Connection::run(), which is what keeps the
 * timing, the QueryExecuted event, the query log and the QueryException wrapping.
 *
 * One instance serves every coroutine in the process, so nothing per-statement
 * may live in a property: the open transaction (TransactionStack) and the last
 * insert id are held in the coroutine context. This is not theoretical — the task
 * pool preempts coroutines on a timer, so a switch can happen between two
 * adjacent lines that call nothing at all.
 */
class Connection extends MySqlConnection
{
    private const string LAST_INSERT_ID_KEY_PREFIX = 'sconcur.db.last_insert_id.';

    protected ?string $serverVersion = null;

    /** Per-caller state — the open transaction, the last insert id — for this connection. */
    protected TransactionStore $store;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        protected SqlConnection $sql,
        string $database = '',
        string $tablePrefix = '',
        array $config = [],
    ) {
        parent::__construct(
            pdo: static function (): never {
                throw new RuntimeException('The sconcur_mysql connection has no PDO handle.');
            },
            database: $database,
            tablePrefix: $tablePrefix,
            config: $config,
        );

        $this->store = new TransactionStore();
    }

    // ---------------------------------------------------------------- statements

    /** {@inheritDoc} */
    public function select($query, $bindings = [], $useReadPdo = true)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            return array_map(
                static fn(array $row): object => (object) $row,
                $this->fetchRows($query, $this->prepareBindings($bindings)),
            );
        });
    }

    /**
     * {@inheritDoc}
     *
     * @return Generator<int, object>
     */
    public function cursor($query, $bindings = [], $useReadPdo = true)
    {
        $rows = $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return [];
            }

            return $this->streamRows($query, $this->prepareBindings($bindings));
        });

        foreach ($rows as $row) {
            yield (object) $row;
        }
    }

    /** {@inheritDoc} */
    public function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $this->execStatement($query, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /** {@inheritDoc} */
    public function affectingStatement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return 0;
            }

            $result = $this->execStatement($query, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified(($count = $result->affectedRows) > 0);

            return $count;
        });
    }

    /**
     * {@inheritDoc}
     *
     * The id goes into the coroutine context rather than a property, so the
     * getLastInsertId() that MySqlProcessor::processInsertGetId() calls right
     * after cannot read a neighbouring coroutine's insert.
     */
    public function insert($query, $bindings = [], $sequence = null)
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            if ($this->pretending()) {
                return true;
            }

            $result = $this->execStatement($query, $this->prepareBindings($bindings));

            $this->recordsHaveBeenModified();

            $this->store->set($this->lastInsertIdKey(), $result->lastInsertId);

            return true;
        });
    }

    /** {@inheritDoc} */
    public function unprepared($query)
    {
        return $this->run($query, [], function ($query) {
            if ($this->pretending()) {
                return true;
            }

            $this->execStatement($query, []);

            $this->recordsHaveBeenModified();

            return true;
        });
    }

    /** {@inheritDoc} */
    public function getLastInsertId()
    {
        return $this->store->find($this->lastInsertIdKey());
    }

    /**
     * {@inheritDoc}
     *
     * The feature returns one result set per query; there is no equivalent of
     * PDOStatement::nextRowset().
     */
    public function selectResultSets($query, $bindings = [], $useReadPdo = true)
    {
        throw new RuntimeException('The sconcur_mysql connection does not support multiple result sets.');
    }

    // -------------------------------------------------------------- transactions

    /**
     * {@inheritDoc}
     *
     * The loop has no bound of its own: every path inside it either returns or
     * throws, and the two handlers below stop retrying once the attempts are
     * spent. Upstream counts the same attempts in the loop header, which leaves
     * it able to fall out and return null when it is asked for zero of them.
     */
    public function transaction(Closure $callback, $attempts = 1)
    {
        for ($currentAttempt = 1; ; $currentAttempt++) {
            $this->beginTransaction();

            try {
                $callbackResult = $callback($this);
            } catch (Throwable $e) {
                $this->handleTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            try {
                $this->commit();
            } catch (Throwable $e) {
                $this->handleCommitTransactionException($e, $currentAttempt, $attempts);

                continue;
            }

            return $callbackResult;
        }
    }

    /** {@inheritDoc} */
    public function beginTransaction()
    {
        foreach ($this->beforeStartingTransaction as $callback) {
            $callback($this);
        }

        $stack = $this->transactionStack();

        if ($stack->level() === 0) {
            $stack->begin($this->openTransaction());
        } else {
            $name = $stack->nextSavepointName();

            $this->execStatement($this->getQueryGrammar()->compileSavepoint($name), []);

            $stack->pushSavepoint($name);
        }

        $this->transactionsManager?->begin($this->connectionName(), $stack->level());

        $this->fireConnectionEvent('beganTransaction');
    }

    /** {@inheritDoc} */
    public function commit()
    {
        $stack = $this->transactionStack();

        $levelBeingCommitted = $stack->level();

        if ($levelBeingCommitted === 1) {
            $stack->assertOwner('commit');

            $this->fireConnectionEvent('committing');

            try {
                $stack->transaction()?->commit();
            } finally {
                $stack->clear();
            }
        } elseif ($levelBeingCommitted > 1) {
            $stack->pop();
        }

        $this->transactionsManager?->commit($this->connectionName(), $levelBeingCommitted, $stack->level());

        $this->fireConnectionEvent('committed');
    }

    /** {@inheritDoc} */
    public function rollBack($toLevel = null)
    {
        $stack = $this->transactionStack();

        $level = $stack->level();

        $toLevel = is_null($toLevel) ? $level - 1 : $toLevel;

        if ($toLevel < 0 || $toLevel >= $level) {
            return;
        }

        if ($toLevel === 0) {
            $stack->assertOwner('roll back');

            try {
                $stack->transaction()?->rollback();
            } finally {
                // Whether or not the rollback reached the server, the transaction
                // is over: the Go side unwinds it when the flow's context is
                // cancelled. Leaving the stack behind would strand the coroutine
                // on a dead transaction id.
                $stack->clear();
            }
        } else {
            $this->execStatement(
                $this->getQueryGrammar()->compileSavepointRollBack((string) $stack->savepointAt($toLevel + 1)),
                [],
            );

            $stack->truncateTo($toLevel);
        }

        $this->transactionsManager?->rollback($this->connectionName(), $toLevel);

        $this->fireConnectionEvent('rollingBack');
    }

    /** {@inheritDoc} */
    public function transactionLevel()
    {
        return $this->transactionStack()->level();
    }

    // ------------------------------------------------------------------- no PDO

    /** {@inheritDoc} */
    public function getPdo()
    {
        throw new RuntimeException(
            'The sconcur_mysql connection has no PDO handle: its statements run through the SConcur SQL'
            . ' feature. Use the [mysql] connection for code that needs PDO.'
        );
    }

    /** {@inheritDoc} */
    public function getReadPdo()
    {
        return $this->getPdo();
    }

    /**
     * {@inheritDoc}
     *
     * The pool lives in the Go extension, which opens, recycles and closes its
     * own connections; there is nothing here to reconnect.
     */
    public function reconnect()
    {
        return $this;
    }

    /** {@inheritDoc} */
    public function disconnect()
    {
        //
    }

    /** {@inheritDoc} */
    public function reconnectIfMissingConnection()
    {
        //
    }

    /** {@inheritDoc} */
    public function getServerVersion(): string
    {
        return $this->serverVersion ??= (string) $this->scalar('SELECT VERSION()');
    }

    /** {@inheritDoc} */
    public function isMaria()
    {
        return str_contains($this->getServerVersion(), 'MariaDB');
    }

    /**
     * {@inheritDoc}
     *
     * A statement parked in the extension can be unwound by the runtime — a shutdown, a
     * handler deadline — and that arrives here as an exception like any other. Illuminate
     * catches Exception and wraps it in a QueryException, and FlowStoppedException
     * extends RuntimeException, so the unwind would lose its identity on the way out:
     * every `catch (FlowStoppedException)` guard in the pool, the task loop and the
     * actions would stop recognising it, and each would go on to do more work on a
     * coroutine that has none left.
     *
     * So it is let through untouched. Everything else is handed to the parent, which does
     * the wrapping — through a callback that rethrows what already happened, so the
     * statement is not run a second time.
     */
    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return $callback($query, $bindings);
        } catch (FlowStoppedException $exception) {
            throw $exception;
        } catch (Exception $exception) {
            return parent::runQueryCallback(
                $query,
                $bindings,
                static function () use ($exception): never {
                    throw $exception;
                },
            );
        }
    }

    /**
     * {@inheritDoc}
     *
     * Upstream guards the retry with Connection::$transactions, which is always
     * zero here — the depth lives in the coroutine's stack. Without this override
     * a statement that failed inside a transaction could be replayed on its own.
     */
    protected function handleQueryException(QueryException $e, $query, $bindings, Closure $callback)
    {
        if ($this->transactionLevel() >= 1) {
            throw $e;
        }

        return $this->tryAgainIfCausedByLostConnection($e, $query, $bindings, $callback);
    }

    /** {@inheritDoc} */
    protected function handleTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        $stack = $this->transactionStack();

        // A deadlock rolls the whole transaction back on the server, so a nested
        // level cannot be retried on its own — the same reasoning as upstream,
        // against the coroutine's stack instead of the shared counter.
        if ($this->causedByConcurrencyError($e) && $stack->level() > 1) {
            $stack->pop();

            $this->transactionsManager?->rollback($this->connectionName(), $stack->level());

            throw new DeadlockException($e->getMessage(), is_int($e->getCode()) ? $e->getCode() : 0, $e);
        }

        $this->rollBack();

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            return;
        }

        throw $e;
    }

    /** {@inheritDoc} */
    protected function handleCommitTransactionException(Throwable $e, $currentAttempt, $maxAttempts)
    {
        // Nothing is left to unwind: a commit that threw finished the transaction
        // one way or the other.
        $this->transactionStack()->clear();

        if ($this->causedByConcurrencyError($e) && $currentAttempt < $maxAttempts) {
            return;
        }

        throw $e;
    }

    /**
     * {@inheritDoc}
     *
     * PDO::quote() is gone, so the escaping is done here. Correct for the
     * single-byte and utf8/utf8mb4 charsets this connection is used with, where
     * no multi-byte sequence can contain one of these bytes.
     *
     * Only Grammar::substituteBindingsIntoRawSql() needs this — pretend() and
     * QueryExecuted::toRawSql(). Statements themselves always travel with their
     * bindings, which the driver escapes.
     */
    protected function escapeString($value)
    {
        return "'" . str_replace(
            ['\\', "\0", "\n", "\r", "'", '"', "\x1a"],
            ['\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'],
            $value,
        ) . "'";
    }

    /**
     * {@inheritDoc}
     *
     * MySqlConnection matches PDO's wording ("Integrity constraint violation:
     * 1062"). The Go driver reports the server's own: "Error 1062 (23000):
     * Duplicate entry ...". firstOrCreate() and createOrFirst() depend on this.
     */
    protected function isUniqueConstraintError(Exception $exception)
    {
        return (bool) preg_match('#Error 1062 \(\d+\)#i', $exception->getMessage())
            || parent::isUniqueConstraintError($exception);
    }

    // -------------------------------------------------------------------- seams

    /**
     * Where a statement goes: the transaction this coroutine is inside, or the
     * pooled connection when there is none.
     */
    protected function target(): SqlConnection|Transaction
    {
        return $this->transactionStack()->transaction() ?? $this->sql;
    }

    /**
     * @param array<int|string, mixed> $bindings
     *
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRows(string $query, array $bindings): array
    {
        return $this->target()->fetchAll($query, array_values($bindings));
    }

    /**
     * @param array<int|string, mixed> $bindings
     *
     * @return iterable<int, array<string, mixed>>
     */
    protected function streamRows(string $query, array $bindings): iterable
    {
        return $this->target()->query($query, array_values($bindings));
    }

    /**
     * @param array<int|string, mixed> $bindings
     */
    protected function execStatement(string $query, array $bindings): ExecResult
    {
        return $this->target()->exec($query, array_values($bindings));
    }

    protected function openTransaction(): Transaction
    {
        return $this->sql->begin();
    }

    protected function transactionStack(): TransactionStack
    {
        return new TransactionStack($this->connectionName(), $this->store);
    }

    protected function lastInsertIdKey(): string
    {
        return self::LAST_INSERT_ID_KEY_PREFIX . $this->connectionName();
    }

    /**
     * The connection's name. Connection::getName() reads it out of the config array and
     * is therefore nullable to the framework; the connector always sets it.
     */
    protected function connectionName(): string
    {
        return (string) $this->getName();
    }
}
