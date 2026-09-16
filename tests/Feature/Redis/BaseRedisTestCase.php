<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Redis;

use Illuminate\Support\Facades\Redis;
use SConcur\Laravel\Redis\Connection;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * Integration tests against the live Redis the compose file raises.
 *
 * Both connections of the workbench point at databases of their own (phpunit.xml), and
 * both are emptied before every test: the cache store's flush empties a whole database,
 * and a key one test left behind must not be what the next one reads.
 */
abstract class BaseRedisTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['default', 'cache'] as $name) {
            $this->redis($name)->client()->flushDb(confirm: true);
        }
    }

    protected function redis(string $name = 'default'): Connection
    {
        $connection = Redis::connection($name);

        assert($connection instanceof Connection);

        return $connection;
    }
}
