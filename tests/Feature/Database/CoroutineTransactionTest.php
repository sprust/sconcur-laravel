<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Transactions on the sconcur_mysql connection belong to the coroutine that opened them.
 *
 * This is what the connection exists for. On PDO the handle is one per process, so two
 * coroutines writing at once are inside each other's transaction and one rollback takes
 * the other's work with it. Here the nesting level lives in the coroutine context and
 * the Go side pins the transaction to a physical connection of its own.
 */
class CoroutineTransactionTest extends BaseDatabaseTestCase
{
    #[Test]
    public function aRollbackInOneCoroutineLeavesTheOtherAlone(): void
    {
        $this->interleave([
            function (): void {
                DB::beginTransaction();

                DB::table(self::TABLE)->insert(['owner' => 'committed', 'value' => 1]);

                $this->yieldToOthers();

                DB::commit();
            },
            function (): void {
                DB::beginTransaction();

                DB::table(self::TABLE)->insert(['owner' => 'rolled-back', 'value' => 2]);

                $this->yieldToOthers();

                DB::rollBack();
            },
        ]);

        self::assertSame(['committed'], $this->owners());
    }

    /** Both commit: neither is inside the other, so both rows survive. */
    #[Test]
    public function twoCoroutinesCommitIndependently(): void
    {
        $this->interleave([
            function (): void {
                DB::transaction(function (): void {
                    DB::table(self::TABLE)->insert(['owner' => 'first', 'value' => 1]);

                    $this->yieldToOthers();
                });
            },
            function (): void {
                DB::transaction(function (): void {
                    DB::table(self::TABLE)->insert(['owner' => 'second', 'value' => 2]);

                    $this->yieldToOthers();
                });
            },
        ]);

        self::assertSame(['first', 'second'], $this->owners());
    }

    /**
     * The nesting level is counted per coroutine, so a coroutine inside a transaction
     * does not make its neighbour think it is inside one too.
     */
    #[Test]
    public function theTransactionLevelIsPerCoroutine(): void
    {
        $seen = [];

        $this->interleave([
            function () use (&$seen): void {
                DB::beginTransaction();
                DB::beginTransaction();

                $this->yieldToOthers();

                $seen['nested'] = DB::transactionLevel();

                DB::rollBack();
                DB::rollBack();
            },
            function () use (&$seen): void {
                $this->yieldToOthers();

                $seen['outside'] = DB::transactionLevel();
            },
        ]);

        self::assertSame(['nested' => 2, 'outside' => 0], $seen);
        self::assertSame(0, DB::transactionLevel());
    }

    /**
     * getLastInsertId() is what MySqlProcessor::processInsertGetId() calls right after
     * the insert, so it has to be the caller's own — a neighbour inserting in between
     * must not be the id that comes back.
     */
    #[Test]
    public function insertGetIdDoesNotReadANeighboursInsert(): void
    {
        $ids = [];

        $this->interleave([
            function () use (&$ids): void {
                $ids['first'] = DB::table(self::TABLE)->insertGetId(['owner' => 'first', 'value' => 1]);

                $this->yieldToOthers();
            },
            function () use (&$ids): void {
                $ids['second'] = DB::table(self::TABLE)->insertGetId(['owner' => 'second', 'value' => 2]);

                $this->yieldToOthers();
            },
        ]);

        self::assertNotSame($ids['first'], $ids['second']);

        $rows = [];

        foreach ($this->rows() as $row) {
            $rows[(string) $row->owner] = (int) $row->id;
        }

        self::assertSame($rows['first'], $ids['first']);
        self::assertSame($rows['second'], $ids['second']);
    }

    /**
     * There is no PDO object behind this connection. Code that needs one uses the `mysql`
     * connection, and saying so beats handing back something half-working.
     */
    #[Test]
    public function itHasNoPdoHandle(): void
    {
        $this->expectException(RuntimeException::class);

        DB::connection('sconcur_mysql')->getPdo();
    }

    #[Test]
    public function itRefusesMultipleResultSets(): void
    {
        $this->expectException(RuntimeException::class);

        DB::connection('sconcur_mysql')->selectResultSets('select 1');
    }
}
