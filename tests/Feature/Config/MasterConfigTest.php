<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Worker\MasterConfig;

class MasterConfigTest extends BaseTestCase
{
    #[Test]
    public function itBuildsAMasterConfigOutOfThePublishedArray(): void
    {
        $masterConfig = MasterConfig::fromArray((array) config('sconcur.master'));

        self::assertSame('workbench-master', $masterConfig->name());
        self::assertNotSame([], $masterConfig->groups());
    }

    /**
     * The group list is filtered before it reaches the master, and array_filter
     * preserves keys — so without array_values a disabled middle group leaves
     * [0 => http, 2 => tasks], which MasterConfig::parseGroups refuses outright. That
     * is the documented way to turn the consumer pool off, so it must not take the
     * master down with it.
     */
    #[Test]
    public function aDisabledMiddleGroupLeavesTheRemainingOnesAsAList(): void
    {
        $groups = (array) config('sconcur.master.groups');

        self::assertSame(array_keys($groups), range(0, count($groups) - 1));

        $names = array_map(static fn(array $group): string => (string) $group['name'], $groups);

        self::assertSame(['http', 'tasks'], $names);
    }

    #[Test]
    public function everyGroupSpawnsItsWorkersThroughArtisan(): void
    {
        foreach ((array) config('sconcur.master.groups') as $group) {
            self::assertSame(base_path('artisan'), $group['workerScript']);
            self::assertNotSame([], $group['workerArgs']);
        }
    }
}
