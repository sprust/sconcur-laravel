<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * A duplicate key has to arrive as UniqueConstraintViolationException rather than as a
 * plain QueryException — firstOrCreate() and createOrFirst() catch the former and fall
 * back to a read, and catch nothing otherwise.
 *
 * The framework decides which of the two to throw by matching the driver's message, and
 * the message is the driver's own: PDO writes "Integrity constraint violation: 1062",
 * sconcur 0.11.0 wrote "Error 1062 (23000)", and 0.12 writes "error returned from
 * database: 1062 (23000)". Connection::isUniqueConstraintError matches the code and its
 * SQLSTATE, which is the part every spelling shares — and this is the test that says so.
 */
class UniqueConstraintTest extends BaseDatabaseTestCase
{
    #[Test]
    public function aDuplicateKeyIsReportedAsAUniqueConstraintViolation(): void
    {
        $connection = DB::connection('sconcur_mysql');

        $connection->table(self::TABLE)->insert([
            'id'    => 1,
            'owner' => 'first',
            'value' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $connection->table(self::TABLE)->insert([
            'id'    => 1,
            'owner' => 'second',
            'value' => 2,
        ]);
    }

    /**
     * The same insert on PDO, so the test above is not green for a reason that has
     * nothing to do with this driver.
     */
    #[Test]
    public function pdoReportsItTheSameWay(): void
    {
        $connection = DB::connection('mysql');

        $connection->table(self::TABLE)->insert([
            'id'    => 1,
            'owner' => 'first',
            'value' => 1,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        $connection->table(self::TABLE)->insert([
            'id'    => 1,
            'owner' => 'second',
            'value' => 2,
        ]);
    }
}
