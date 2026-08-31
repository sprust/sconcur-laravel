<?php

declare(strict_types=1);

namespace SConcur\Laravel\Http;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SConcur\Context\Context;
use SConcur\Laravel\Foundation\ScopedService;
use Symfony\Bridge\PsrHttpMessage\Factory\HttpFoundationFactory;
use Symfony\Bridge\PsrHttpMessage\Factory\PsrHttpFactory;

/**
 * PSR-7 request handler that bridges to the Laravel HTTP kernel.
 *
 * The request is published into the coroutine context, so AsyncApplication
 * resolves 'request' per-fiber and concurrent requests do not share it. Full
 * isolation of auth/session/router lands in later stages
 * (see docs/fiber-safe-laravel-bridge.ru.md).
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

        $kernel->terminate($laravelRequest, $response);

        return $this->psrHttpFactory->createResponse($response);
    }
}
