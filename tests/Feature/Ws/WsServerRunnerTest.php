<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SConcur\Features\WsServer\WsServer;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\InspectableRunner;
use SConcur\Laravel\Ws\WsLogger;

/**
 * What the runner does with the flags before it starts serving.
 */
class WsServerRunnerTest extends BaseTestCase
{
    private string $log;

    protected function setUp(): void
    {
        parent::setUp();

        $this->log = tempnam(sys_get_temp_dir(), 'ws-runner-');
    }

    protected function tearDown(): void
    {
        @unlink($this->log);

        parent::tearDown();
    }

    #[Test]
    public function itBuildsTheServerOutOfTheCollectedFlags(): void
    {
        $server = new InspectableRunner(serverArgs: ['--address=127.0.0.1:29999', '--reusePort=1'])
            ->build($this->logger());

        self::assertSame('127.0.0.1:29999', $this->read($server, 'address'));
        self::assertTrue($this->read($server, 'reusePort'));
    }

    /** The master injects its pid so an orphaned worker stands down; it must reach argv. */
    #[Test]
    public function itPassesTheMasterPidOn(): void
    {
        $server = new InspectableRunner(serverArgs: [], masterPid: 4242)->build($this->logger());

        self::assertSame(4242, $this->read($server, 'masterPid'));
    }

    #[Test]
    public function withoutAMasterThereIsNoPid(): void
    {
        $server = new InspectableRunner(serverArgs: [])->build($this->logger());

        self::assertNull($this->read($server, 'masterPid'));
    }

    /**
     * handlerTimeoutMs bounds the whole life of a connection here, so anything above zero
     * disconnects every client on a timer — which looks like a network fault unless the
     * worker says so.
     */
    #[Test]
    public function itSaysSoWhenTheHandlerDeadlineWouldDropClients(): void
    {
        new InspectableRunner(serverArgs: ['--handlerTimeoutMs=60000'])->warn($this->logger());

        self::assertStringContainsString('handlerTimeoutMs is 60000', (string) file_get_contents($this->log));
    }

    #[Test]
    public function zeroIsWhatItExpectsAndItStaysQuiet(): void
    {
        new InspectableRunner(serverArgs: ['--handlerTimeoutMs=0'])->warn($this->logger());

        self::assertSame('', (string) file_get_contents($this->log));
    }

    #[Test]
    public function anAbsentFlagIsQuietToo(): void
    {
        new InspectableRunner(serverArgs: ['--address=127.0.0.1:29999'])->warn($this->logger());

        self::assertSame('', (string) file_get_contents($this->log));
    }

    private function logger(): WsLogger
    {
        return new WsLogger(stream: $this->log);
    }

    private function read(WsServer $server, string $property): mixed
    {
        return new ReflectionClass($server)->getProperty($property)->getValue($server);
    }
}
