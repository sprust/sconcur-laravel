<?php

declare(strict_types=1);

use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use SConcur\Laravel\Foundation\AsyncApplication;

/**
 * The one thing this file exists for: the application is an AsyncApplication, not the
 * framework's own. Without it `request`, `session`, `auth` and `cookie` stay
 * process-wide singletons, and two requests running as coroutines in one process read
 * each other's state.
 *
 * Everything else mirrors what Application::configure() does in a stock Laravel 12
 * skeleton — the builder takes an application instance, so there is nothing to
 * reimplement.
 */
$app = new AsyncApplication(dirname(__DIR__));

// The repository keeps a single .env at its root: docker compose reads it, and so does
// this application. Laravel would otherwise look for demo/.env, which would mean two
// files holding the same credentials.
$app->useEnvironmentPath(dirname(__DIR__, 2));

return (new ApplicationBuilder($app))
    ->withKernels()
    ->withEvents()
    ->withCommands()
    ->withProviders()
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
    )
    ->withMiddleware(static function (Middleware $middleware): void {
        // The demo posts from its own page with fetch() and carries no session, so CSRF
        // has nothing to validate against. Dropping the middleware keeps the page
        // honest about what it is: a probe, not an application with users.
        $middleware->validateCsrfTokens(except: ['*']);
    })
    ->withExceptions(static function (Exceptions $exceptions): void {
        //
    })
    ->create();
