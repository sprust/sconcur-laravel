<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Ws;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use SConcur\Features\WsServer\WsServer;
use SConcur\Laravel\Tests\Feature\BaseTestCase;
use SConcur\Laravel\Tests\Feature\Ws\Support\InspectableRunner;
use SConcur\Laravel\Ws\WsLogger;
use SConcur\Laravel\Ws\WsOptions;
use SConcur\Laravel\Ws\WsPresenceOptions;

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
        $server = new InspectableRunner(serverArgs: ['--address=127.0.0.1:29999', '--pingIntervalMs=1000'])
            ->build($this->logger());

        self::assertSame('127.0.0.1:29999', $this->read($server, 'address'));
        self::assertSame(1000, $this->read($server, 'pingIntervalMs'));
    }

    /**
     * No pid is handed over: under a master the server takes the one of the master that
     * forked it (WorkerContext), and without one there is nothing to watch.
     */
    #[Test]
    public function theMasterPidIsLeftToTheServer(): void
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

    /**
     * A member list kept in one process is not incomplete under a pool, it is wrong:
     * every worker would answer with its own subscribers.
     */
    #[Test]
    public function itWarnsAboutAMemoryPresenceStoreUnderAPool(): void
    {
        $this->warnAboutPresence(WsPresenceOptions::STORE_MEMORY, workerCount: 3);

        self::assertStringContainsString('3 ws workers', (string) file_get_contents($this->log));
    }

    #[Test]
    public function oneWorkerOwnsTheWholeListAndNeedsNoWarning(): void
    {
        $this->warnAboutPresence(WsPresenceOptions::STORE_MEMORY, workerCount: 1);

        self::assertSame('', (string) file_get_contents($this->log));
    }

    #[Test]
    public function aSharedStoreUnderAPoolIsFine(): void
    {
        $this->warnAboutPresence(WsPresenceOptions::STORE_CACHE, workerCount: 3);

        self::assertSame('', (string) file_get_contents($this->log));
    }

    /** `auto` picks the shared store itself once there is more than one worker. */
    #[Test]
    public function autoNeedsNoWarningEither(): void
    {
        $this->warnAboutPresence(WsPresenceOptions::STORE_AUTO, workerCount: 3);

        self::assertSame('', (string) file_get_contents($this->log));
    }

    private function warnAboutPresence(string $store, int $workerCount): void
    {
        new InspectableRunner()->warnAboutPresence(
            logger: $this->logger(),
            options: WsOptions::fromArray([
                'app_key'    => 'k',
                'app_secret' => 's',
                'presence'   => ['store' => $store],
            ]),
            workerCount: $workerCount,
        );
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
