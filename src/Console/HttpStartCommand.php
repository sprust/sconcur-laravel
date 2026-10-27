<?php

declare(strict_types=1);

namespace SConcur\Laravel\Console;

use Illuminate\Console\Command;
use SConcur\Laravel\Http\HttpServerRunner;
use SConcur\Laravel\Servers\MasterGroups;
use SConcur\Laravel\Servers\ServerFlags;
use SConcur\Laravel\Servers\WorkerRoleEnum;

/**
 * Run a single SConcur HTTP server in the foreground, outside the master: a standalone
 * server in development. Under the master a worker of the `http` group runs
 * HttpServerRunner itself, without this command.
 *
 * Every flag HttpServer::fromArgs reads is declared below. With none given, the flags are
 * taken from the `http` group's own `server` block instead — the values a worker of the
 * master would have used.
 *
 * For a supervised, multi-worker setup use sconcur:servers:master:start.
 */
class HttpStartCommand extends Command
{
    public const string NAME = 'sconcur:servers:http:start';

    /** Options that belong to HttpServer rather than to this command. */
    protected const array RUNTIME_OPTIONS = [
        'address',
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
        {--maxRequests= : Stop after this many requests (0 = unlimited)}
        {--maxConcurrency= : Requests in flight at once (0 = unlimited)}
        {--maxRequestBody= : Request body limit, bytes}
        {--readHeaderTimeoutMs= : Header read timeout}
        {--readTimeoutMs= : Read timeout}
        {--writeTimeoutMs= : Write timeout}
        {--idleTimeoutMs= : Keep-alive idle timeout}
        {--handlerTimeoutMs= : How long one request may spend in the handler}
        {--shutdownTimeoutMs= : Graceful shutdown timeout}
        {--preemptionQuantumMs= : Preemption quantum while serving}';

    protected $description = 'Run a SConcur HTTP server in the foreground';

    public function handle(): int
    {
        new HttpServerRunner(
            serverArgs: $this->serverArgs(),
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
     * The `server` block of the `http` group, for a run with no flags of its own.
     *
     * @return list<string>
     */
    protected function configuredServerArgs(): array
    {
        return ServerFlags::fromBlock(MasterGroups::server(WorkerRoleEnum::Http));
    }
}
