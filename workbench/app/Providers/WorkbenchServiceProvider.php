<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Tasks\CountingTask;
use Workbench\App\Tasks\FailingTask;
use Workbench\App\Tasks\IdleTask;

class WorkbenchServiceProvider extends ServiceProvider
{
    /**
     * Counting and idle are singletons so a test can read the same instance the pool
     * ticked. Failing is deliberately not one: the registry resolves a task out of the
     * container, so a task registered as a singleton comes back identical however often
     * the registry is told to forget it — and the test for forget() needs one that does
     * not.
     */
    public function register(): void
    {
        $this->app->singleton(CountingTask::class);
        $this->app->singleton(IdleTask::class);
        $this->app->bind(FailingTask::class);
    }

    /**
     * The routes are registered here rather than left to testbench's route discovery,
     * which did not pick them up: what the tests need is one known path to send a
     * request at, and a provider saying so plainly beats depending on how the discovery
     * is configured.
     */
    public function boot(): void
    {
        $routes = realpath(__DIR__ . '/../../routes/api.php');

        assert($routes !== false);

        Route::group([], $routes);
    }
}
