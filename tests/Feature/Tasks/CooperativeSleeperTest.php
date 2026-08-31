<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Tasks;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tasks\CooperativeSleeper;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

class CooperativeSleeperTest extends BaseTestCase
{
    #[Test]
    public function itWaitsTheRequestedTime(): void
    {
        $startedAt = microtime(true);

        new CooperativeSleeper(chunkMs: 5)->sleep(0.05);

        self::assertGreaterThanOrEqual(0.04, microtime(true) - $startedAt);
    }

    /**
     * The interrupt is what keeps a stop from waiting out a task's full interval: the
     * pause is cut into chunks precisely so it can be asked, between them, whether there
     * is anything left to wait for.
     */
    #[Test]
    public function anInterruptEndsTheWaitEarly(): void
    {
        $startedAt = microtime(true);

        new CooperativeSleeper(chunkMs: 5)->sleep(10.0, static fn(): bool => true);

        self::assertLessThan(1.0, microtime(true) - $startedAt);
    }

    #[Test]
    public function anInterruptThatTurnsTrueMidWaitEndsIt(): void
    {
        $calls = 0;

        $startedAt = microtime(true);

        new CooperativeSleeper(chunkMs: 5)->sleep(10.0, static function () use (&$calls): bool {
            $calls++;

            return $calls > 3;
        });

        self::assertLessThan(1.0, microtime(true) - $startedAt);
        self::assertSame(4, $calls);
    }
}
