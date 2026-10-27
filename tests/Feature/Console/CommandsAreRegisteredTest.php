<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

class CommandsAreRegisteredTest extends BaseTestCase
{
    /**
     * The names are what the docs, the makefile and supervisor configs call, so a rename
     * here is a rename everywhere they are written down.
     */
    #[Test]
    public function itRegistersEveryCommandTheMasterAndTheDocsName(): void
    {
        $commands = array_keys($this->getApp()->make(Kernel::class)->all());

        foreach ([
            'sconcur:servers:master:start',
            'sconcur:servers:master:stop',
            'sconcur:servers:master:status',
            'sconcur:servers:http:start',
            'sconcur:servers:rabbitmq:start',
            'sconcur:rabbitmq:declare',
            'sconcur:servers:ws:start',
            'sconcur:tasks:start',
            'sconcur:tasks:stop',
            'sconcur:tasks:restart',
            'sconcur:extension:load',
            'sconcur:extension:status',
        ] as $name) {
            self::assertContains($name, $commands);
        }
    }

    /**
     * A standalone server takes its flags from the command line, and every key of the
     * group's `server` block has to be one of them, or that key cannot be overridden from
     * there.
     */
    #[Test]
    public function theHttpCommandDeclaresEveryServerFlagTheConfigCarries(): void
    {
        $this->assertDeclaresEveryKey('sconcur:servers:http:start', 'http');
    }

    #[Test]
    public function theWsCommandDeclaresEveryServerFlagTheConfigCarries(): void
    {
        $this->assertDeclaresEveryKey('sconcur:servers:ws:start', 'ws');
    }

    #[Test]
    public function theRabbitmqCommandDeclaresEveryServerFlagTheConfigCarries(): void
    {
        $this->assertDeclaresEveryKey('sconcur:servers:rabbitmq:start', 'rabbitmq');
    }

    private function assertDeclaresEveryKey(string $command, string $role): void
    {
        $definition = $this->getApp()->make(Kernel::class)->all()[$command]->getDefinition();

        $server = $this->publishedGroup($role)['server'];

        self::assertNotSame([], $server);

        foreach (array_keys($server) as $option) {
            self::assertTrue($definition->hasOption($option), 'missing option: ' . $option);
        }
    }

    /**
     * The group of the package's own config skeleton, every optional pool switched on.
     *
     * @return array<string, mixed>
     */
    private function publishedGroup(string $role): array
    {
        $_SERVER['SCONCUR_RABBITMQ_WORKER_COUNT'] = '1';
        $_SERVER['SCONCUR_WS_WORKER_COUNT']       = '1';

        try {
            $config = require __DIR__ . '/../../../config/sconcur.php';
        } finally {
            unset($_SERVER['SCONCUR_RABBITMQ_WORKER_COUNT'], $_SERVER['SCONCUR_WS_WORKER_COUNT']);
        }

        foreach ($config['master']['groups'] as $group) {
            if ($group['role'] === $role) {
                return $group;
            }
        }

        self::fail('no group runs the ' . $role . ' role');
    }
}
