<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Config;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use RuntimeException;
use SConcur\Laravel\Servers\MasterFactory;
use SConcur\Laravel\Servers\WorkerRoleEnum;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Worker\AccessLogTarget;
use SConcur\Worker\LogTarget;
use SConcur\Worker\RestartPolicy;
use SConcur\Worker\WorkerGroupConfig;
use SConcur\Worker\WorkerMaster;

class MasterConfigTest extends BaseTestCase
{
    #[Test]
    public function itBuildsAMasterOutOfThePublishedArray(): void
    {
        $workerMaster = $this->workerMaster((array) config('sconcur.master'));

        self::assertSame('workbench-master', $this->read($workerMaster, 'name'));
        self::assertSame(LogTarget::Stdout, $this->read($workerMaster, 'logTo'));
        self::assertNotSame([], $this->groupConfigs($workerMaster));
    }

    /**
     * The group list is filtered before it reaches the master, and array_filter
     * preserves keys — so without array_values a disabled middle group leaves
     * [0 => http, 2 => tasks]. That is the documented way to turn the consumer pool
     * off, so it must not take the master down with it.
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
    public function everyGroupNamesAKnownRole(): void
    {
        foreach ((array) config('sconcur.master.groups') as $group) {
            self::assertNotNull(WorkerRoleEnum::tryFrom((string) $group['role']));
        }
    }

    /** The settings at the top are every group's defaults; a group's own key wins. */
    #[Test]
    public function aGroupFallsBackOnTheMasterSettings(): void
    {
        $groups = $this->groupConfigs($this->workerMaster([
            'runtimeDir'        => sys_get_temp_dir(),
            'restartPolicy'     => 'always',
            'shutdownTimeoutMs' => 7000,
            'readyTimeoutMs'    => 2000,
            'accessLog'         => 'off',
            'env'               => [
                'APP_ROLE'  => 'master',
                'LOG_LEVEL' => 'info',
            ],
            'groups'            => [
                [
                    'name'        => 'http',
                    'role'        => 'http',
                    'workerCount' => 3,
                ],
                [
                    'name'              => 'tasks',
                    'role'              => 'tasks',
                    'workerCount'       => 1,
                    'restartPolicy'     => 'on-failure',
                    'shutdownTimeoutMs' => 30000,
                    'env'               => [
                        'APP_ROLE' => 'tasks',
                        'TICK'     => 1,
                    ],
                ],
            ],
        ]));

        self::assertSame(3, $groups[0]->workerCount);
        self::assertSame(RestartPolicy::Always, $groups[0]->restartPolicy);
        self::assertSame(7000, $groups[0]->shutdownTimeoutMs);
        self::assertSame(2000, $groups[0]->readyTimeoutMs);
        self::assertSame(AccessLogTarget::Off, $groups[0]->accessLog);

        self::assertSame(RestartPolicy::OnFailure, $groups[1]->restartPolicy);
        self::assertSame(30000, $groups[1]->shutdownTimeoutMs);

        // `env` adds up: the group's variables go on top of the master-wide ones, its own
        // value wins, and every value reaches the library as a string.
        self::assertSame(['APP_ROLE' => 'master', 'LOG_LEVEL' => 'info'], $groups[0]->env);
        self::assertSame(['APP_ROLE' => 'tasks', 'LOG_LEVEL' => 'info', 'TICK' => '1'], $groups[1]->env);
    }

    /**
     * A config published before the key existed has no `accessLog`, and gets what the
     * skeleton sets rather than the library's `stdout`, which drops lines under load.
     */
    #[Test]
    public function withoutAnAccessLogKeyTheAccessLogGoesToAFile(): void
    {
        $groups = $this->groupConfigs($this->workerMaster([
            'runtimeDir' => sys_get_temp_dir(),
            'groups'     => [
                [
                    'name' => 'http',
                    'role' => 'http',
                ],
            ],
        ]));

        self::assertSame(AccessLogTarget::File, $groups[0]->accessLog);
    }

    #[Test]
    public function aGroupWithAnUnknownRoleIsRefusedByName(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/group "cron" has no known role/');

        $this->workerMaster([
            'runtimeDir' => sys_get_temp_dir(),
            'groups'     => [
                [
                    'name' => 'cron',
                    'role' => 'cron',
                ],
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function workerMaster(array $config): WorkerMaster
    {
        return $this->getApp()->make(MasterFactory::class)->make($config);
    }

    /**
     * @return list<WorkerGroupConfig>
     */
    private function groupConfigs(WorkerMaster $workerMaster): array
    {
        /** @var list<WorkerGroupConfig> */
        return $this->read($workerMaster, 'groups');
    }

    private function read(WorkerMaster $workerMaster, string $property): mixed
    {
        return new ReflectionProperty($workerMaster, $property)->getValue($workerMaster);
    }
}
