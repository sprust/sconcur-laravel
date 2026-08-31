<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SConcur\Laravel\Tests\Feature\Concurrency\BaseConcurrencyTestCase;
use stdClass;

/**
 * Integration tests against the live MySQL the compose file raises.
 *
 * The table is created and dropped per test rather than through RefreshDatabase: that
 * wraps each test in a transaction, and a transaction is the thing under test here. The
 * name is its own so a run cannot disturb the demo's tables in the same database.
 */
abstract class BaseDatabaseTestCase extends BaseConcurrencyTestCase
{
    protected const string TABLE = 'sconcur_test_rows';

    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists(self::TABLE);

        Schema::create(self::TABLE, static function (Blueprint $table): void {
            $table->id();
            $table->string('owner');
            $table->integer('value')->default(0);
        });
    }

    protected function tearDown(): void
    {
        // A test that left a transaction open would hold locks the drop then waits on
        // until the statement times out, and the failure would be reported against the
        // teardown rather than against whatever actually went wrong.
        foreach (['sconcur_mysql', 'mysql'] as $name) {
            $connection = DB::connection($name);

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        Schema::dropIfExists(self::TABLE);

        parent::tearDown();
    }

    /** @return list<stdClass> */
    protected function rows(): array
    {
        return array_values(DB::table(self::TABLE)->orderBy('id')->get()->all());
    }

    /** @return list<string> */
    protected function owners(): array
    {
        return array_map(static fn(stdClass $row): string => (string) $row->owner, $this->rows());
    }
}
