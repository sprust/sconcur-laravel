<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Console\WsStartCommand;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Ws\WsGroupConfig;

/**
 * The package's own config skeleton, evaluated the way an application's published copy
 * would be. It is read from disk rather than from config() because what is under test is
 * the file — the conditions in it, not the values the workbench happens to carry.
 */
class WsGroupConfigTest extends BaseTestCase
{
    protected function tearDown(): void
    {
        unset(
            $_SERVER['SCONCUR_WS_WORKER_COUNT'],
            $_SERVER['SCONCUR_WS_APP_KEY'],
            $_SERVER['SCONCUR_WS_PATH_PREFIX'],
        );

        parent::tearDown();
    }

    /** Below one leaves the group out entirely; zero would mean one worker per CPU. */
    #[Test]
    public function theGroupIsAbsentUntilItIsAskedFor(): void
    {
        $_SERVER['SCONCUR_WS_WORKER_COUNT'] = '0';

        self::assertNull($this->wsGroup());
    }

    #[Test]
    public function theGroupListStaysAListWithTheWsGroupIn(): void
    {
        $_SERVER['SCONCUR_WS_WORKER_COUNT'] = '2';

        $groups = $this->configuredGroups();

        self::assertSame(array_keys($groups), range(0, count($groups) - 1));
        self::assertContains('ws', array_map(static fn(array $group): string => $group['name'], $groups));
    }

    #[Test]
    public function theGroupRunsTheWsCommandThroughArtisan(): void
    {
        $_SERVER['SCONCUR_WS_WORKER_COUNT'] = '2';

        $group = $this->existingWsGroup();

        self::assertSame(base_path('artisan'), $group['workerScript']);
        self::assertSame(['sconcur:servers:ws:start'], $group['workerArgs']);
        self::assertSame(2, $group['workerCount']);
    }

    /**
     * handlerTimeoutMs bounds the whole life of a connection here, not one frame. Any
     * value above zero disconnects every client on a timer, which is why it is not
     * env-driven — there is no value of it a ws pool wants.
     */
    #[Test]
    public function theHandlerDeadlineIsOff(): void
    {
        $_SERVER['SCONCUR_WS_WORKER_COUNT'] = '2';

        self::assertSame(0, $this->existingWsGroup()['server']['handlerTimeoutMs']);
    }

    /** The key is in the path, so a wrong one is a 404 on the handshake. */
    #[Test]
    public function thePathCarriesTheAppKey(): void
    {
        $_SERVER['SCONCUR_WS_WORKER_COUNT'] = '2';
        $_SERVER['SCONCUR_WS_APP_KEY']      = 'abc123';

        self::assertSame('/app/abc123', $this->existingWsGroup()['server']['path']);
    }

    /**
     * The path the extension matches and the prefix the handler checks have to be the
     * same string. They were not: the group hard-coded /app while the handler read
     * path_prefix, so setting the prefix alone made the extension answer 404 to every
     * client and PHP never saw a thing.
     */
    #[Test]
    public function thePathFollowsTheConfiguredPrefix(): void
    {
        $_SERVER['SCONCUR_WS_WORKER_COUNT'] = '1';
        $_SERVER['SCONCUR_WS_APP_KEY']      = 'abc123';
        $_SERVER['SCONCUR_WS_PATH_PREFIX']  = '/ws';

        self::assertSame('/ws/abc123', $this->existingWsGroup()['server']['path']);
    }

    #[Test]
    public function theServerBlockIsReadOffTheGroupThatRunsTheCommand(): void
    {
        config()->set('sconcur.master.groups', [
            ['name' => 'http', 'workerArgs' => ['sconcur:servers:http:start'], 'server' => ['address' => 'wrong']],
            ['name' => 'ws', 'workerArgs' => [WsStartCommand::NAME], 'server' => ['address' => 'right']],
        ]);

        self::assertSame(['address' => 'right'], WsGroupConfig::server(WsStartCommand::NAME));
    }

    /** No group at all is not a failure: a standalone run just gets the library defaults. */
    #[Test]
    public function anAbsentGroupYieldsNothingAndOneWorker(): void
    {
        config()->set('sconcur.master.groups', []);

        self::assertSame([], WsGroupConfig::server(WsStartCommand::NAME));
        self::assertSame(1, WsGroupConfig::workerCount(WsStartCommand::NAME));
    }

    #[Test]
    public function aMalformedGroupListIsSteppedOver(): void
    {
        config()->set('sconcur.master.groups', ['not an array', ['name' => 'ws']]);

        self::assertSame([], WsGroupConfig::server(WsStartCommand::NAME));
    }

    /**
     * The group, with the test's own assertion that it is there — so a caller reading a
     * key off it is reading it off an array rather than off null.
     *
     * @return array<string, mixed>
     */
    private function existingWsGroup(): array
    {
        $group = $this->wsGroup();

        self::assertNotNull($group);

        return $group;
    }

    /**
     * @return null|array<string, mixed>
     */
    private function wsGroup(): ?array
    {
        foreach ($this->configuredGroups() as $group) {
            if ($group['name'] === 'ws') {
                return $group;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function configuredGroups(): array
    {
        $config = require __DIR__ . '/../../../config/sconcur.php';

        return $config['master']['groups'];
    }
}
