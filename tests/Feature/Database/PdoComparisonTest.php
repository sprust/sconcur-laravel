<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The same interleaving on the ordinary PDO connection, so the test above is not green
 * for both and therefore green for nothing.
 *
 * PDO has one handle per process: two coroutines that both open a transaction are in the
 * same one, and the second beginTransaction is a savepoint inside the first rather than
 * a transaction of its own. What that costs is recorded here rather than described — if
 * PDO ever stops behaving this way, this test says so instead of the other one quietly
 * losing its point.
 */
class PdoComparisonTest extends BaseDatabaseTestCase
{
    #[Test]
    public function onPdoTheTransactionsAreNotSeparate(): void
    {
        $connection = DB::connection('mysql');

        $levels = [];

        $this->interleave([
            function () use ($connection, &$levels): void {
                $connection->beginTransaction();

                $connection->table(self::TABLE)->insert(['owner' => 'first', 'value' => 1]);

                $this->yieldToOthers();

                $levels['seen by first'] = $connection->transactionLevel();
            },
            function () use ($connection, &$levels): void {
                // Not a transaction of its own: the neighbour already opened one on the
                // single process-wide handle, so this is a savepoint inside it.
                $connection->beginTransaction();

                $levels['seen by second'] = $connection->transactionLevel();

                $connection->table(self::TABLE)->insert(['owner' => 'second', 'value' => 2]);

                $this->yieldToOthers();
            },
        ]);

        // Two coroutines, one shared nesting counter — which is the whole problem. On
        // sconcur_mysql the same interleaving gives each of them 1.
        self::assertSame(2, $levels['seen by second']);
        self::assertSame(2, $levels['seen by first']);

        // Rolling back what the first coroutine believes is its transaction unwinds the
        // second one's savepoint, and leaves the outer transaction — and its locks —
        // still open. The teardown is what closes it.
        $connection->rollBack();

        self::assertSame(1, $connection->transactionLevel());

        // Read back on the same connection: the outer transaction is still open, so no
        // other connection can see any of this yet. The first coroutine's row is intact
        // — the rollback unwound the savepoint, which is the second coroutine's insert.
        $owners = $connection->table(self::TABLE)->orderBy('id')->pluck('owner')->all();

        self::assertSame(['first'], $owners);
    }
}
