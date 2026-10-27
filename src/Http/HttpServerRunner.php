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
 * Builds a SConcur HttpServer from the group's `server` block and serves the Laravel HTTP
 * handler in the current process.
 *
 * The flags come from the `http` group: its role hands them over in a worker the master
 * forked, and HttpStartCommand takes them from its own options or, without any, from the
 * same block for a standalone run. Under a master the server finds the master's pid by
 * itself (WorkerContext) and stands down once the master is gone.
 */
readonly class HttpServerRunner
{
    /**
     * @param list<string> $serverArgs `--name=value` flags for HttpServer::fromArgs
     */
    public function __construct(
        private array $serverArgs = [],
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
        return HttpServer::fromArgs(
            argv: $this->serverArgs,
            serverRequestFactory: $serverRequestFactory,
            responseFactory: $responseFactory,
        );
    }
}
