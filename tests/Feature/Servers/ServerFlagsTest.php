<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Servers;

use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Features\Amqp\Consumer\QueueConsumer;
use SConcur\Features\HttpServer\HttpServer;
use SConcur\Features\WsServer\WsServer;
use SConcur\Laravel\Servers\ServerFlags;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * A group's `server` block reaches the library through fromArgs(), which refuses a flag it
 * does not know. So every key the package's config skeleton carries has to be one the
 * library still reads — a key the library dropped would keep every worker of the pool
 * from starting.
 */
class ServerFlagsTest extends BaseTestCase
{
    #[Test]
    public function itWritesBooleansAsDigitsAndListsAsJson(): void
    {
        self::assertSame(
            [
                '--address=0.0.0.0:1',
                '--requeueOnFailure=0',
                '--queues=[{"name":"default","coroutineCount":2}]',
            ],
            ServerFlags::fromBlock([
                'address'          => '0.0.0.0:1',
                'requeueOnFailure' => false,
                'queues'           => [['name' => 'default', 'coroutineCount' => 2]],
            ]),
        );
    }

    #[Test]
    public function theHttpServerTakesEveryKeyOfTheHttpGroup(): void
    {
        $server = HttpServer::fromArgs(
            argv: $this->flags('http'),
            serverRequestFactory: new ServerRequestFactory(),
            responseFactory: new ResponseFactory(),
        );

        self::assertInstanceOf(HttpServer::class, $server);
    }

    #[Test]
    public function theWsServerTakesEveryKeyOfTheWsGroup(): void
    {
        self::assertInstanceOf(WsServer::class, WsServer::fromArgs($this->flags('ws')));
    }

    #[Test]
    public function theQueueConsumerTakesEveryKeyOfTheRabbitmqGroup(): void
    {
        self::assertInstanceOf(QueueConsumer::class, QueueConsumer::fromArgs($this->flags('rabbitmq')));
    }

    /**
     * The flags of a group of the package's own config skeleton, every optional pool
     * switched on.
     *
     * @return list<string>
     */
    private function flags(string $role): array
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
                return ServerFlags::fromBlock($group['server']);
            }
        }

        self::fail('no group runs the ' . $role . ' role');
    }
}
