<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Servers\MasterGroups;
use SConcur\Laravel\Servers\ServerFlags;
use SConcur\Laravel\Servers\WorkerRoleEnum;
use SConcur\Laravel\Ws\WsOptions;
use SConcur\Laravel\Ws\WsServerRunner;

/**
 * Run a single SConcur WebSocket server in the foreground, outside the master: a standalone
 * server in development. Under the master a worker of the `ws` group runs WsServerRunner
 * itself, without this command.
 *
 * Structured exactly like HttpStartCommand: every flag WsServer::fromArgs reads is
 * declared, and with none given the server is built from the `ws` group's own `server`
 * block — the values a worker of the master would have used.
 */
class WsStartCommand extends Command
{
    public const string NAME = 'sconcur:servers:ws:start';

    /** Options that belong to WsServer rather than to this command. */
    protected const array RUNTIME_OPTIONS = [
        'address',
        'path',
        'handshakeTimeoutMs',
        'idleTimeoutMs',
        'writeTimeoutMs',
        'pingIntervalMs',
        'maxMessageBytes',
        'maxConcurrency',
        'handlerTimeoutMs',
        'maxConnections',
        'shutdownTimeoutMs',
        'preemptionQuantumMs',
    ];

    protected $signature = self::NAME . '
        {--address= : Listen address, host:port}
        {--path= : The path the upgrade is accepted on; empty accepts any}
        {--handshakeTimeoutMs= : How long the upgrade headers may take}
        {--idleTimeoutMs= : Idle timeout between inbound messages (0 = off)}
        {--writeTimeoutMs= : How long sending one message may take}
        {--pingIntervalMs= : Server keepalive ping cadence (0 = off)}
        {--maxMessageBytes= : Inbound message size limit}
        {--maxConcurrency= : Connections served at once (0 = unlimited)}
        {--handlerTimeoutMs= : Deadline on a whole connection; 0, or clients drop on a timer}
        {--maxConnections= : Stop after this many served connections (0 = unlimited)}
        {--shutdownTimeoutMs= : Graceful shutdown timeout}
        {--preemptionQuantumMs= : Preemption quantum while serving}';

    protected $description = 'Run a SConcur WebSocket server in the foreground';

    public function handle(): int
    {
        $options = WsOptions::fromArray((array) config('sconcur.ws', []));

        if (!$options->isConfigured()) {
            $this->components->error(
                'config("sconcur.ws") has no app_key/app_secret. Publish the package config'
                . ' (vendor:publish --tag=sconcur-laravel) and set SCONCUR_WS_APP_KEY/SCONCUR_WS_APP_SECRET.',
            );

            return self::FAILURE;
        }

        new WsServerRunner(
            serverArgs: $this->serverArgs(),
        )->run($this->getLaravel());

        return self::SUCCESS;
    }

    /**
     * The server flags as WsServer::fromArgs wants them: a list of `--name=value`,
     * booleans as the literal 1/0.
     *
     * @return list<string>
     */
    protected function serverArgs(): array
    {
        $args = [];

        foreach (self::RUNTIME_OPTIONS as $name) {
            $value = $this->option($name);

            // Unlike HttpStartCommand, an empty value is kept rather than skipped:
            // `--path=` is how the group says "accept any path", and dropping it would
            // silently put the server back on the library's default of `/`.
            if (!is_string($value)) {
                continue;
            }

            $args[] = sprintf('--%s=%s', $name, $value);
        }

        return $args === [] ? $this->configuredServerArgs() : $args;
    }

    /**
     * The `server` block of the `ws` group, for a run with no flags of its own.
     *
     * @return list<string>
     */
    protected function configuredServerArgs(): array
    {
        return ServerFlags::fromBlock(MasterGroups::server(WorkerRoleEnum::Ws));
    }
}
