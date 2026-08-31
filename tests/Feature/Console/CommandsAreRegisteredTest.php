<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

class CommandsAreRegisteredTest extends BaseTestCase
{
    /**
     * The names are also the contract between the master and its workers: a group's
     * workerArgs name one of these, so a rename here is a rename in every published
     * config.
     */
    #[Test]
    public function itRegistersEveryCommandTheMasterAndTheDocsName(): void
    {
        $commands = array_keys($this->getApp()->make(Kernel::class)->all());

        foreach ([
            'sconcur:servers:master:start',
            'sconcur:servers:master:stop',
            'sconcur:servers:master:status',
            'sconcur:servers:master:reload',
            'sconcur:servers:http:start',
            'sconcur:servers:rabbitmq:start',
            'sconcur:rabbitmq:declare',
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
     * The master forwards a group's `server` block to its workers' argv verbatim, and
     * Symfony Console rejects a flag the command does not declare. So every key the
     * published config may carry has to be a declared option, or the pool cannot start
     * at all.
     */
    #[Test]
    public function theHttpCommandDeclaresEveryServerFlagTheConfigMayCarry(): void
    {
        $definition = $this->getApp()->make(Kernel::class)->all()['sconcur:servers:http:start']->getDefinition();

        foreach ([
            'address',
            'reusePort',
            'maxRequests',
            'maxConcurrency',
            'maxRequestBody',
            'readHeaderTimeoutMs',
            'readTimeoutMs',
            'writeTimeoutMs',
            'idleTimeoutMs',
            'handlerTimeoutMs',
            'shutdownTimeoutMs',
            'masterPid',
        ] as $option) {
            self::assertTrue($definition->hasOption($option), 'missing option: ' . $option);
        }
    }
}
