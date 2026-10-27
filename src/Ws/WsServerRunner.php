<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

use Illuminate\Contracts\Foundation\Application;
use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Features\WsServer\WsServer;
use SConcur\Laravel\Servers\MasterGroups;
use SConcur\Laravel\Servers\WorkerRoleEnum;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use Throwable;

/**
 * Builds a SConcur WsServer from the group's `server` block and serves the protocol
 * handler in the current process — the ws counterpart of HttpServerRunner.
 *
 * The flags come from the `ws` group: its role hands them over in a worker the master
 * forked, and WsStartCommand takes them from its own options or, without any, from the
 * same block for a standalone run.
 */
readonly class WsServerRunner
{
    /**
     * @param list<string> $serverArgs `--name=value` flags for WsServer::fromArgs
     */
    public function __construct(
        private array $serverArgs = [],
    ) {
    }

    public function run(Application $app): void
    {
        /** @var ConnectionHandler $handler */
        $handler = $app->make(ConnectionHandler::class);

        /** @var BusSubscriber $subscriber */
        $subscriber = $app->make(BusSubscriber::class);

        /** @var WsLogger $logger */
        $logger = $app->make(WsLogger::class);

        $this->warnAboutHandlerTimeout($logger);
        $this->warnAboutPresenceStore(
            logger: $logger,
            options: $app->make(WsOptions::class),
            workerCount: MasterGroups::workerCount(WorkerRoleEnum::Ws),
        );

        // A bus with no loop of its own is subscribed here, before serving starts; the
        // one that needs a coroutine is started by the first connection instead.
        $subscriber->boot();

        $this->makeServer($logger)->serve($handler(...));
    }

    protected function makeServer(WsLogger $logger): WsServer
    {
        return WsServer::fromArgs(
            argv: $this->serverArgs,
            onError: static function (Throwable $exception, Connection $connection) use ($logger): void {
                $logger->log('conn', 'connection ' . $connection->id . ' failed: ' . $exception->getMessage());
            },
        );
    }

    /**
     * handlerTimeoutMs is a deadline on the whole life of a connection here, not on one
     * frame — a ws handler runs until the client leaves. A non-zero value disconnects
     * every client on a timer, which looks like a network fault, so it is said out loud
     * rather than left to be discovered.
     */
    protected function warnAboutHandlerTimeout(WsLogger $logger): void
    {
        foreach ($this->serverArgs as $argument) {
            if (!str_starts_with($argument, '--handlerTimeoutMs=')) {
                continue;
            }

            $value = (int) substr($argument, strlen('--handlerTimeoutMs='));

            if ($value > 0) {
                $logger->log(
                    'server',
                    'handlerTimeoutMs is ' . $value . ': every connection will be dropped after that long.'
                    . ' A ws handler lives as long as its connection — set it to 0.',
                );
            }

            return;
        }
    }

    /**
     * A member list kept in one process is correct only while there is one process. Under
     * a pool it is not incomplete, it is wrong — every worker would answer with its own
     * subscribers — so the mismatch is said in the worker's log rather than honoured in
     * silence.
     */
    protected function warnAboutPresenceStore(WsLogger $logger, WsOptions $options, int $workerCount): void
    {
        if ($workerCount <= 1) {
            return;
        }

        if ($options->presence->resolveStore($workerCount) !== WsPresenceOptions::STORE_MEMORY) {
            return;
        }

        $logger->log(
            'server',
            sprintf(
                'sconcur.ws.presence.store is "memory" with %d ws workers: each of them will answer'
                . ' presence subscriptions with its own connections only. Use "cache" or "auto".',
                $workerCount,
            ),
        );
    }
}
