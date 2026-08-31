<?php

declare(strict_types=1);

namespace SConcur\Laravel\Routing;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\Routing;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use SConcur\Context\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coroutine-safe router.
 *
 * Stores the current route and request in the per-coroutine context instead of
 * shared instance properties, so concurrent requests do not overwrite each other's
 * "current route".
 *
 * Ported from yangusik/laravel-spawn (AsyncRouter).
 */
class AsyncRouter extends Router
{
    private const string CTX_CURRENT_ROUTE   = 'router.current';
    private const string CTX_CURRENT_REQUEST = 'router.currentRequest';

    private bool $async = false;

    public function bootCompleted(): void
    {
        $this->async = true;
    }

    public function dispatch(Request $request): Response
    {
        if ($this->async) {
            Context::current()->set(self::CTX_CURRENT_REQUEST, $request, replace: true);
        } else {
            $this->currentRequest = $request;
        }

        return $this->dispatchToRoute($request);
    }

    public function current()
    {
        if ($this->async) {
            return Context::current()->find(self::CTX_CURRENT_ROUTE);
        }

        return $this->current;
    }

    public function getCurrentRequest(): mixed
    {
        if ($this->async) {
            return Context::current()->find(self::CTX_CURRENT_REQUEST);
        }

        return $this->currentRequest;
    }

    protected function findRoute($request): Route
    {
        $this->events->dispatch(new Routing($request));

        $route = $this->routes->match($request);

        if ($this->async) {
            Context::current()->set(self::CTX_CURRENT_ROUTE, $route, replace: true);
        } else {
            $this->current = $route;
        }

        $route->setContainer($this->container);

        $this->container->instance(Route::class, $route);

        return $route;
    }
}
