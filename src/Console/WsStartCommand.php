<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Ws\WsGroupConfig;
use SConcur\Laravel\Ws\WsOptions;
use SConcur\Laravel\Ws\WsPresenceOptions;
use SConcur\Laravel\Ws\WsServerRunner;

/**
 * Run a single SConcur WebSocket server in the foreground: the worker script of the `ws`
 * master group, and a standalone server in development.
 *
 * Structured exactly like HttpStartCommand, and for the same reason: the group's `server`
 * block is forwarded to the worker's argv verbatim, and Symfony Console rejects a flag
 * the command does not declare — so every one of them is declared here even though
 * WsServer::fromArgs is what reads them.
 */
class WsStartCommand extends Command
{
    /** Artisan name, used by the master when it spawns this group's workers. */
    public const string NAME = 'sconcur:servers:ws:start';

    /** Options that belong to WsServer rather than to this command. */
    protected const array RUNTIME_OPTIONS = [
        'address',
        'reusePort',
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
        {--reusePort= : SO_REUSEPORT, so several processes share one port (1/0)}
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
        {--preemptionQuantumMs= : Preemption quantum while serving}
        {--masterPid= : Master pid, injected by the supervisor for orphan self-termination}';

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

        $this->warnAboutPresenceStore($options);

        $masterPid = $this->option('masterPid');

        new WsServerRunner(
            serverArgs: $this->serverArgs(),
            masterPid: $masterPid !== null ? (int) $masterPid : null,
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
            // `--path=` is how the master says "accept any path", and dropping it would
            // silently put the server back on the library's default of `/`.
            if (!is_string($value)) {
                continue;
            }

            $args[] = sprintf('--%s=%s', $name, $value);
        }

        return $args === [] ? $this->configuredServerArgs() : $args;
    }

    /**
     * The `server` block of the group this command is the worker script of, for a run
     * with no master to forward it.
     *
     * @return list<string>
     */
    protected function configuredServerArgs(): array
    {
        $args = [];

        foreach (WsGroupConfig::server(self::NAME) as $key => $value) {
            $args[] = sprintf(
                '--%s=%s',
                $key,
                is_bool($value) ? ($value ? '1' : '0') : (string) $value,
            );
        }

        return $args;
    }

    /**
     * A member list kept in one process is correct only while there is one process. Under
     * a pool it is not incomplete, it is wrong — every worker would answer with its own
     * subscribers — so the mismatch is reported rather than honoured in silence.
     */
    protected function warnAboutPresenceStore(WsOptions $options): void
    {
        $workerCount = WsGroupConfig::workerCount(self::NAME);

        if ($workerCount <= 1) {
            return;
        }

        if ($options->presence->resolveStore($workerCount) !== WsPresenceOptions::STORE_MEMORY) {
            return;
        }

        $this->reportWarning(
            sprintf(
                'sconcur.ws.presence.store is "memory" with %d ws workers: each of them will answer'
                . ' presence subscriptions with its own connections only. Use "cache" or "auto".',
                $workerCount,
            ),
        );
    }

    /**
     * Where a warning goes. Its own method so the decision above can be exercised without
     * an output component, which only exists once artisan is running the command.
     */
    protected function reportWarning(string $message): void
    {
        $this->components->warn($message);
    }
}
