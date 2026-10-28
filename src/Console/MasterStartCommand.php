<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Database\DatabaseManager;
use Illuminate\Redis\RedisManager;
use SConcur\Laravel\Servers\MasterFactory;
use SConcur\Laravel\Servers\WatchdogEventForwarder;
use SConcur\Worker\WorkerMaster;

/**
 * Start the SConcur master supervisor in the foreground. Builds the master from
 * config('sconcur.master') in this process and runs it: every worker is a fork of this
 * process, so it starts with the application this command booted already in memory.
 *
 * The watchdog's reports go out as the WorkerWatchdogTriggered event whether anyone
 * listens or not: with no listener the dispatch costs nothing, and the master journals
 * and counts the kill on its own either way.
 */
class MasterStartCommand extends AbstractSconcurCommand
{
    protected $signature = 'sconcur:servers:master:start';

    protected $description = 'Start the SConcur master supervisor';

    public function handle(MasterFactory $masterFactory, WatchdogEventForwarder $watchdogEventForwarder): int
    {
        $workerMaster = $this->workerMaster(
            masterFactory: $masterFactory,
            watchdogEventForwarder: $watchdogEventForwarder,
        );

        $this->closeConnections();

        return $workerMaster->run();
    }

    /** The supervisor, with the watchdog's reports handed to the forwarder. */
    protected function workerMaster(
        MasterFactory $masterFactory,
        WatchdogEventForwarder $watchdogEventForwarder,
    ): WorkerMaster {
        return $masterFactory->make(
            config: $this->masterConfigArray(),
            onWatchdogEvent: $watchdogEventForwarder(...),
        );
    }

    /**
     * A fork does not copy a connection, it shares it: every worker would hold the same
     * socket, and two of them writing at once interleave their bytes. Whatever the
     * application connected while it booted is closed here, so each worker opens its own
     * on its first query.
     */
    protected function closeConnections(): void
    {
        $app = $this->getLaravel();

        if ($app->resolved('db')) {
            /** @var DatabaseManager $databaseManager */
            $databaseManager = $app->make('db');

            foreach (array_keys($databaseManager->getConnections()) as $name) {
                $databaseManager->purge($name);
            }
        }

        if ($app->resolved('redis')) {
            /** @var RedisManager $redisManager */
            $redisManager = $app->make('redis');

            foreach (array_keys($redisManager->connections()) as $name) {
                $redisManager->purge($name);
            }
        }
    }
}
