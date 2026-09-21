<?php

declare(strict_types=1);

namespace SConcur\Laravel\Http;

use Closure;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Context\Context;
use SConcur\Laravel\Foundation\ScopedService;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * PSR-7 request handler that bridges to the Laravel HTTP kernel.
 *
 * The request is published into the coroutine context, so AsyncApplication
 * resolves 'request' per-fiber and concurrent requests do not share it. Full
 * isolation of auth/session/router lands in later stages
 * (see .ai/plans/bridge/fiber-safe-laravel-bridge.md).
 *
 * A StreamedResponse built from chunks (see StreamedChunks) goes out as a lazy body the
 * server drains chunk by chunk, and the kernel is terminated once the body has been read.
 * Every other response — a StreamedResponse whose callback prints included — is converted
 * whole by PsrHttpFactory, and the kernel is terminated after that.
 */
readonly class LaravelHttpHandler
{
    public function __construct(
        private Application $app,
        private HttpFoundationFactory $httpFoundationFactory,
        private PsrHttpFactory $psrHttpFactory,
    ) {
    }

    /**
     * The status, the headers and the cookies go through PsrHttpFactory on an empty
     * response, and the body is read chunk by chunk as the server sends it. No
     * Content-Length: the size is not known, and without one the server sends the body
     * chunked.
     *
     * @param Closure(): iterable<mixed> $chunksFactory
     */
    private function streamedResponse(
        Kernel $kernel,
        Request $laravelRequest,
        StreamedResponse $response,
        Closure $chunksFactory,
    ): ResponseInterface {
        $head = new Response(status: $response->getStatusCode());

        $head->headers = clone $response->headers;

        $head->setProtocolVersion($response->getProtocolVersion());

        $app = $this->app;

        return $this->psrHttpFactory->createResponse($head)
            ->withoutHeader('Content-Length')
            ->withBody(
                new IterableResponseBody(
                    chunksFactory: $chunksFactory,
                    onFinished: static function () use ($kernel, $laravelRequest, $response): void {
                        $kernel->terminate($laravelRequest, $response);
                    },
                    reportException: static function (Throwable $exception) use ($app): void {
                        $app->make(ExceptionHandler::class)->report($exception);
                    },
                ),
            );
    }

    /**
     * @throws BindingResolutionException
     */
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $laravelRequest = Request::createFromBase(
            $this->httpFoundationFactory->createRequest($request)
        );

        // Publish the request into this coroutine's context; AsyncApplication
        // resolves 'request' from here instead of the shared container binding.
        Context::current()->set(ScopedService::REQUEST->value, $laravelRequest, replace: true);

        /** @var Kernel $kernel */
        $kernel = $this->app->make(Kernel::class);

        $response = $kernel->handle($laravelRequest);

        $chunksFactory = $response instanceof StreamedResponse
            ? StreamedChunks::of($response)
            : null;

        if ($response instanceof StreamedResponse && $chunksFactory !== null) {
            return $this->streamedResponse(
                kernel: $kernel,
                laravelRequest: $laravelRequest,
                response: $response,
                chunksFactory: $chunksFactory,
            );
        }

        // Converted before the kernel is terminated: for a StreamedResponse the conversion
        // is what runs the callback, and the callback may still need what terminate() ends.
        $psrResponse = $this->psrHttpFactory->createResponse($response);

        $kernel->terminate($laravelRequest, $response);

        return $psrResponse;
    }
}
