<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\TaskPoolLock;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * What keeps a second pool from starting beside the first.
 *
 * flock and not a cache lock: the kernel releases it when the process dies, SIGKILL
 * included, so there is no stale lock to clean up after a crash.
 */
class TaskPoolLockTest extends BaseTestCase
{
    protected string $path;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/sconcur-tasks-' . bin2hex(random_bytes(6)) . '.lock';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    #[Test]
    public function itIsAcquiredWhenNobodyHoldsIt(): void
    {
        $lock = new TaskPoolLock($this->path);

        self::assertTrue($lock->acquire());
        self::assertSame('', $lock->failure());

        $lock->release();
    }

    #[Test]
    public function aSecondLockOnTheSamePathIsRefused(): void
    {
        $first = new TaskPoolLock($this->path);

        self::assertTrue($first->acquire());

        $second = new TaskPoolLock($this->path);

        self::assertFalse($second->acquire());
        self::assertStringContainsString('another process holds', $second->failure());

        $first->release();
    }

    #[Test]
    public function aReleasedLockCanBeTakenAgain(): void
    {
        $first = new TaskPoolLock($this->path);

        self::assertTrue($first->acquire());

        $first->release();

        $second = new TaskPoolLock($this->path);

        self::assertTrue($second->acquire());

        $second->release();
    }

    /**
     * An unwritable path is told apart from a held lock on purpose: reported as "another
     * pool holds it", it would have the supervisor restarting for ever while every log
     * line blamed a pool that does not exist.
     */
    #[Test]
    public function anUnusablePathIsNotReportedAsAHeldLock(): void
    {
        $lock = new TaskPoolLock('/proc/self/cannot-create-here/tasks.lock');

        self::assertFalse($lock->acquire());
        self::assertStringNotContainsString('another process holds', $lock->failure());
    }

    #[Test]
    public function itCreatesTheDirectoryItNeeds(): void
    {
        $nested = sys_get_temp_dir() . '/sconcur-lock-' . bin2hex(random_bytes(4)) . '/deep/tasks.lock';

        $lock = new TaskPoolLock($nested);

        self::assertTrue($lock->acquire());
        self::assertFileExists($nested);

        $lock->release();
    }
}
