<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\InspectableStartCommand;

/**
 * The flags the command hands the server.
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
            'address'        => '0.0.0.0:28090',
            'pingIntervalMs' => '1000',
        ];

        self::assertSame(
            ['--address=0.0.0.0:28090', '--pingIntervalMs=1000'],
            $this->command->args(),
        );
    }

    /**
     * An empty value is kept, unlike in HttpStartCommand: `--path=` is how the group
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
                'name'   => 'ws',
                'role'   => 'ws',
                'server' => ['address' => '127.0.0.1:1', 'path' => ''],
            ],
        ]);

        self::assertSame(['--address=127.0.0.1:1', '--path='], $this->command->args());
    }
}
