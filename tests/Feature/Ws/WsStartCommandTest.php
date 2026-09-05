<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Console\WsStartCommand;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\InspectableStartCommand;
use SConcur\Laravel\Ws\WsOptions;
use SConcur\Laravel\Ws\WsPresenceOptions;

/**
 * The flags the command hands the server, and the two things it refuses to start on
 * quietly.
 *
 * Serving itself is not reachable from a test — run() binds a listener and stays there —
 * so what is held here is everything that happens before that.
 */
class WsStartCommandTest extends BaseTestCase
{
    private InspectableStartCommand $command;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new InspectableStartCommand();
    }

    /** A group's server block reaches the server through argv, one `--name=value` each. */
    #[Test]
    public function itTurnsTheOptionsIntoFlags(): void
    {
        $this->command->options = [
            'address'   => '0.0.0.0:28090',
            'reusePort' => '1',
        ];

        self::assertSame(
            ['--address=0.0.0.0:28090', '--reusePort=1'],
            $this->command->args(),
        );
    }

    /**
     * An empty value is kept, unlike in HttpStartCommand: `--path=` is how the master
     * says "accept any path", and dropping it would put the server back on the library's
     * default of `/` without a word.
     */
    #[Test]
    public function anEmptyPathSurvives(): void
    {
        $this->command->options = ['path' => ''];

        self::assertSame(['--path='], $this->command->args());
    }

    /** With nothing on argv the command falls back to its own group's block. */
    #[Test]
    public function withNoFlagsItReadsTheGroupItRuns(): void
    {
        config()->set('sconcur.master.groups', [
            [
                'name'       => 'ws',
                'workerArgs' => [WsStartCommand::NAME],
                'server'     => ['address' => '127.0.0.1:1', 'reusePort' => false],
            ],
        ]);

        self::assertSame(['--address=127.0.0.1:1', '--reusePort=0'], $this->command->args());
    }

    /**
     * A member list kept in one process is not incomplete under a pool, it is wrong:
     * every worker would answer with its own subscribers.
     */
    #[Test]
    public function itWarnsAboutAMemoryPresenceStoreUnderAPool(): void
    {
        $this->givenWsWorkers(3);

        $this->command->warnAbout($this->presenceOptions(WsPresenceOptions::STORE_MEMORY));

        self::assertStringContainsString('3 ws workers', $this->command->warnings[0]);
    }

    #[Test]
    public function oneWorkerOwnsTheWholeListAndNeedsNoWarning(): void
    {
        $this->givenWsWorkers(1);

        $this->command->warnAbout($this->presenceOptions(WsPresenceOptions::STORE_MEMORY));

        self::assertSame([], $this->command->warnings);
    }

    #[Test]
    public function aSharedStoreUnderAPoolIsFine(): void
    {
        $this->givenWsWorkers(3);

        $this->command->warnAbout($this->presenceOptions(WsPresenceOptions::STORE_CACHE));

        self::assertSame([], $this->command->warnings);
    }

    /** `auto` picks the shared store itself once there is more than one worker. */
    #[Test]
    public function autoNeedsNoWarningEither(): void
    {
        $this->givenWsWorkers(3);

        $this->command->warnAbout($this->presenceOptions(WsPresenceOptions::STORE_AUTO));

        self::assertSame([], $this->command->warnings);
    }

    private function givenWsWorkers(int $count): void
    {
        config()->set('sconcur.master.groups', [
            [
                'name'        => 'ws',
                'workerArgs'  => [WsStartCommand::NAME],
                'workerCount' => $count,
            ],
        ]);
    }

    private function presenceOptions(string $store): WsOptions
    {
        return WsOptions::fromArray([
            'app_key'    => 'k',
            'app_secret' => 's',
            'presence'   => ['store' => $store],
        ]);
    }
}
