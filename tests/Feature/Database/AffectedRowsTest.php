<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * What an UPDATE answers with, on both connections, because the two do not agree.
 *
 * The extension negotiates `CLIENT_FOUND_ROWS` and PDO does not, so an UPDATE writing
 * the value a row already holds is 1 matched row here and 0 changed rows there. Neither
 * is wrong; what would be wrong is the README claiming one of them while the driver does
 * the other, so both are pinned here. sconcur 0.11.0 answered like PDO — this changed
 * with the Rust core, which is why it is worth a test rather than a sentence.
 */
class AffectedRowsTest extends BaseDatabaseTestCase
{
    #[Test]
    public function anUpdateCountsMatchedRowsRatherThanChangedOnes(): void
    {
        DB::connection('sconcur_mysql')->table(self::TABLE)->insert(['owner' => 'one', 'value' => 7]);

        $affected = DB::connection('sconcur_mysql')
            ->table(self::TABLE)
            ->where('owner', 'one')
            ->update(['value' => 7]);

        self::assertSame(1, $affected);
    }

    /**
     * The flag cannot be turned off, and ROW_COUNT() answers the same number, so the way
     * to a changed-rows count is to leave the unchanged rows out of the statement — then
     * matched is changed. Null-safe, because `<>` would drop a NULL column instead of
     * comparing it.
     */
    #[Test]
    public function excludingTheRowsThatWouldNotChangeCountsTheChangedOnes(): void
    {
        $connection = DB::connection('sconcur_mysql');

        $connection->table(self::TABLE)->insert(['owner' => 'one', 'value' => 7]);

        self::assertSame(
            0,
            $connection->table(self::TABLE)
                ->where('owner', 'one')
                ->whereRaw('NOT (value <=> ?)', [7])
                ->update(['value' => 7]),
        );

        self::assertSame(
            1,
            $connection->table(self::TABLE)
                ->where('owner', 'one')
                ->whereRaw('NOT (value <=> ?)', [9])
                ->update(['value' => 9]),
        );
    }

    /**
     * The same update on PDO, so the assertion above is a recorded difference rather
     * than a number nobody compared against anything.
     */
    #[Test]
    public function pdoCountsChangedRowsInstead(): void
    {
        DB::connection('mysql')->table(self::TABLE)->insert(['owner' => 'one', 'value' => 7]);

        $affected = DB::connection('mysql')
            ->table(self::TABLE)
            ->where('owner', 'one')
            ->update(['value' => 7]);

        self::assertSame(0, $affected);
    }
}
