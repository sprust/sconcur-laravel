<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * A row keeps its columns in SELECT order, as PDO gives them: what reads a row by
 * position — array_values(), fputcsv() of a row as it is, json_encode() of a response —
 * sees the same order on both connections, on every query.
 */
class ColumnOrderTest extends BaseDatabaseTestCase
{
    protected const string SQL = 'SELECT 1 AS z, 2 AS a, 3 AS m, 4 AS b, 5 AS y, 6 AS c, 7 AS x, 8 AS d';

    #[Test]
    public function aRowKeepsTheSelectOrderOfPdo(): void
    {
        $expected = array_keys((array) DB::connection('mysql')->selectOne(self::SQL));

        self::assertSame(['z', 'a', 'm', 'b', 'y', 'c', 'x', 'd'], $expected);

        // Many times, because an order that is not kept is not kept at random.
        for ($attempt = 0; $attempt < 20; $attempt++) {
            self::assertSame($expected, array_keys((array) DB::connection('sconcur_mysql')->selectOne(self::SQL)));
        }
    }
}
