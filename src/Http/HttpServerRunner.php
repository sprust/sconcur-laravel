<?php

declare(strict_types=1);

namespace SConcur\Laravel\Http;

use Illuminate\Contracts\Foundation\Application;
use Laminas\Diactoros\ResponseFactory;
use Laminas\Diactoros\ServerRequestFactory;
use Laminas\Diactoros\StreamFactory;
use Laminas\Diactoros\UploadedFileFactory;
use SConcur\Features\HttpServer\HttpServer;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * Builds a SConcur HttpServer from the flags its command collected and serves the
 * Laravel HTTP handler in the current process.
 *
 * The flags are the group's `server` block: the master forwards it to the worker's argv,
 * and HttpStartCommand falls back to the same block from config for a standalone run.
 *
 * When launched by the master, $masterPid is the master's pid (injected via the
 * --masterPid argv flag) so the worker self-terminates if the master dies; null
 * for a standalone foreground run.
 */
readonly class HttpServerRunner
{
    /**
     * @param list<string> $serverArgs `--name=value` flags for HttpServer::fromArgs
     */
    public function __construct(
        private array $serverArgs = [],
        private ?int $masterPid = null,
    ) {
    }

    public function run(Application $app): void
    {
        $serverRequestFactory = new ServerRequestFactory();
        $responseFactory      = new ResponseFactory();

        $psrHttpFactory = new PsrHttpFactory(
            serverRequestFactory: $serverRequestFactory,
            streamFactory: new StreamFactory(),
            uploadedFileFactory: new UploadedFileFactory(),
            responseFactory: $responseFactory,
        );

        $handler = new LaravelHttpHandler(
            app: $app,
            httpFoundationFactory: new HttpFoundationFactory(),
            psrHttpFactory: $psrHttpFactory,
        );

        $this->makeServer($serverRequestFactory, $responseFactory)
            ->serve($handler(...));
    }

    private function makeServer(
        ServerRequestFactory $serverRequestFactory,
        ResponseFactory $responseFactory,
    ): HttpServer {
        $argv = $this->serverArgs;

        if ($this->masterPid !== null) {
            $argv[] = '--masterPid=' . $this->masterPid;
        }

        return HttpServer::fromArgs(
            argv: $argv,
            serverRequestFactory: $serverRequestFactory,
            responseFactory: $responseFactory,
        );
    }
}
