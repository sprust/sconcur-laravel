<?php

declare(strict_types=1);

namespace Demo\App\Providers;

use Demo\App\Telemetry\SconcurStatClient;
use Illuminate\Support\ServiceProvider;

class DemoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            SconcurStatClient::class,
            static fn(): SconcurStatClient => new SconcurStatClient(),
        );
    }
}
