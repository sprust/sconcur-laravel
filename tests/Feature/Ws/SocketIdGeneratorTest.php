<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\SocketIdGenerator;

class SocketIdGeneratorTest extends BaseTestCase
{
    /** The client echoes the socket id back into signatures, so the shape is protocol. */
    #[Test]
    public function itProducesTwoIntegersSeparatedByADot(): void
    {
        self::assertMatchesRegularExpression('/^\d+\.\d+$/', new SocketIdGenerator()->next());
    }

    #[Test]
    public function itNeverRepeatsWithinAProcess(): void
    {
        $generator = new SocketIdGenerator(processId: 42);

        $ids = [];

        for ($index = 0; $index < 100; $index++) {
            $ids[] = $generator->next();
        }

        self::assertCount(100, array_unique($ids));
    }

    /**
     * The pid half is what makes an id unique across the pool with no coordination: the
     * counter covers the process, the pid covers everything else.
     */
    #[Test]
    public function theFirstHalfIsTheWorkerPid(): void
    {
        self::assertSame('42.1', new SocketIdGenerator(processId: 42)->next());
    }
}
