<?php

declare(strict_types=1);

use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Configuration\Middleware;
use SConcur\Laravel\Foundation\AsyncApplication;

/** @var string $APP_BASE_PATH testbench sets it before requiring this file */

return (new ApplicationBuilder(new AsyncApplication($APP_BASE_PATH)))
    ->withProviders()
    ->withMiddleware(static function (Middleware $middleware): void {
        //
    })
    ->withCommands()
    ->create();
