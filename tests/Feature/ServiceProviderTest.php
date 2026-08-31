<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature;

use Illuminate\Database\DatabaseManager;
use Illuminate\Queue\QueueManager;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Config\AsyncConfig;
use SConcur\Laravel\Database\CoroutineTransactionsManager;
use SConcur\Laravel\Database\Mysql\Connection as SconcurMysqlConnection;
use SConcur\Laravel\Events\AsyncDispatcher;
use SConcur\Laravel\Queue\Rabbitmq\Queue as SconcurQueue;
use SConcur\Laravel\Routing\AsyncRouter;
use SConcur\Laravel\Translation\AsyncTranslator;
use SConcur\Laravel\View\AsyncViewFactory;

/**
 * What the provider wires, and the fact that it wires it in every process — there is no
 * mode to detect and nothing to turn on. An earlier version guessed the process kind
 * from argv and was wrong in exactly the processes it existed for.
 */
class ServiceProviderTest extends BaseTestCase
{
    #[Test]
    public function itSwapsEveryAdapterIn(): void
    {
        $app = $this->getApp();

        self::assertInstanceOf(AsyncConfig::class, $app->make('config'));
        self::assertInstanceOf(AsyncDispatcher::class, $app->make('events'));
        self::assertInstanceOf(AsyncRouter::class, $app->make('router'));
        self::assertInstanceOf(AsyncTranslator::class, $app->make('translator'));
        self::assertInstanceOf(AsyncViewFactory::class, $app->make('view'));
    }

    /**
     * DatabaseManager::configure() hands every connection whatever `db.transactions`
     * resolves to, once — so the replacement has to be in place before the first
     * connection is built. Model::saveOrFail() opens a transaction, which puts this on
     * the path of an ordinary create, not only of an explicit DB::transaction().
     */
    #[Test]
    public function itReplacesTheTransactionsManager(): void
    {
        self::assertInstanceOf(CoroutineTransactionsManager::class, $this->getApp()->make('db.transactions'));
    }

    #[Test]
    public function itRegistersTheSconcurMysqlDriver(): void
    {
        config()->set('database.connections.probe', [
            'driver'   => 'sconcur_mysql',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'probe',
            'username' => 'user',
            'password' => '',
        ]);

        $connection = $this->getApp()->make(DatabaseManager::class)->connection('probe');

        self::assertInstanceOf(SconcurMysqlConnection::class, $connection);
    }

    /**
     * Nothing is opened by resolving either of these: the MySQL connection holds a DSN
     * and the pool sizes, the AMQP one raises its socket on the first command. That is
     * what makes it safe to register them unconditionally.
     */
    #[Test]
    public function itRegistersTheSconcurRabbitmqConnector(): void
    {
        config()->set('queue.connections.probe', [
            'driver' => 'sconcur_rabbitmq',
            'queue'  => 'probe',
            'dsn'    => 'amqp://user:pass@127.0.0.1:5672/%2f',
        ]);

        $queue = $this->getApp()->make(QueueManager::class)->connection('probe');

        self::assertInstanceOf(SconcurQueue::class, $queue);
    }
}
