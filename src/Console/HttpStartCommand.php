<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Http\HttpServerRunner;

/**
 * Run a single SConcur HTTP server in the foreground: the worker script of a master
 * group (workerScript = artisan, workerArgs = [this command]), and a standalone server
 * in development.
 *
 * The server settings come from argv, which is how the master configures its workers: a
 * group's `server` block is forwarded to their argv verbatim. Symfony Console rejects
 * flags a command does not declare, so every one of them is declared below even though
 * HttpServer::fromArgs is what reads them.
 *
 * Run standalone there is no master to forward anything, so the flags are taken from the
 * group's own `server` block instead — the same values, by the same path the master
 * would have used.
 *
 * For a supervised, multi-worker setup use sconcur:servers:master:start.
 */
class HttpStartCommand extends Command
{
    /** Artisan name, used by the master when it spawns this group's workers. */
    public const string NAME = 'sconcur:servers:http:start';

    /** Options that belong to HttpServer rather than to this command. */
    protected const array RUNTIME_OPTIONS = [
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
        'preemptionQuantumMs',
    ];

    protected $signature = self::NAME . '
        {--address= : Listen address, host:port}
        {--reusePort= : SO_REUSEPORT, so several processes share one port (1/0)}
        {--maxRequests= : Stop after this many requests (0 = unlimited)}
        {--maxConcurrency= : Requests in flight at once (0 = unlimited)}
        {--maxRequestBody= : Request body limit, bytes}
        {--readHeaderTimeoutMs= : Header read timeout}
        {--readTimeoutMs= : Read timeout}
        {--writeTimeoutMs= : Write timeout}
        {--idleTimeoutMs= : Keep-alive idle timeout}
        {--handlerTimeoutMs= : How long one request may spend in the handler}
        {--shutdownTimeoutMs= : Graceful shutdown timeout}
        {--preemptionQuantumMs= : Preemption quantum while serving}
        {--masterPid= : Master pid, injected by the supervisor for orphan self-termination}';

    protected $description = 'Run a SConcur HTTP server in the foreground';

    public function handle(): int
    {
        $masterPid = $this->option('masterPid');

        new HttpServerRunner(
            serverArgs: $this->serverArgs(),
            masterPid: $masterPid !== null ? (int) $masterPid : null,
        )->run($this->getLaravel());

        return self::SUCCESS;
    }

    /**
     * The server flags as HttpServer::fromArgs wants them: a list of `--name=value`,
     * booleans as the literal 1/0.
     *
     * @return list<string>
     */
    protected function serverArgs(): array
    {
        $args = [];

        foreach (self::RUNTIME_OPTIONS as $name) {
            $value = $this->option($name);

            if (!is_string($value) || $value === '') {
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
     * The group is found by what it runs rather than by name, so renaming it in the
     * config does not quietly leave a standalone server on library defaults.
     *
     * @return list<string>
     */
    protected function configuredServerArgs(): array
    {
        $args = [];

        foreach ((array) config('sconcur.master.groups', []) as $group) {
            if (!is_array($group) || !in_array(self::NAME, (array) ($group['workerArgs'] ?? []), true)) {
                continue;
            }

            foreach ((array) ($group['server'] ?? []) as $key => $value) {
                $args[] = sprintf(
                    '--%s=%s',
                    $key,
                    is_bool($value) ? ($value ? '1' : '0') : (string) $value,
                );
            }

            break;
        }

        return $args;
    }
}
