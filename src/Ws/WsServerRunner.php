<?php

declare(strict_types=1);

namespace SConcur\Laravel\Ws;

use Illuminate\Contracts\Foundation\Application;
use SConcur\Features\WsServer\Dto\Connection;
use SConcur\Features\WsServer\WsServer;
use SConcur\Laravel\Ws\Bus\BusSubscriber;
use Throwable;

/**
 * Builds a SConcur WsServer from the flags its command collected and serves the protocol
 * handler in the current process — the ws counterpart of HttpServerRunner.
 *
 * The flags are the group's `server` block: the master forwards it to the worker's argv,
 * and WsStartCommand falls back to the same block from config for a standalone run.
 */
readonly class WsServerRunner
{
    /**
     * @param list<string> $serverArgs `--name=value` flags for WsServer::fromArgs
     */
    public function __construct(
        private array $serverArgs = [],
        private ?int $masterPid = null,
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

        // A bus with no loop of its own is subscribed here, before serving starts; the
        // one that needs a coroutine is started by the first connection instead.
        $subscriber->boot();

        $this->makeServer($logger)->serve($handler(...));
    }

    private function makeServer(WsLogger $logger): WsServer
    {
        $argv = $this->serverArgs;

        if ($this->masterPid !== null) {
            $argv[] = '--masterPid=' . $this->masterPid;
        }

        return WsServer::fromArgs(
            argv: $argv,
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
    private function warnAboutHandlerTimeout(WsLogger $logger): void
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
}
