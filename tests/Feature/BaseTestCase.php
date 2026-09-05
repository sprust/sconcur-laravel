<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Testing\PendingCommand;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SConcur\Context\Context;
use SConcur\Laravel\SConcurServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

    /**
     * Drops the config overlay between tests.
     *
     * AsyncConfig keeps `config()->set` in the coroutine context, and outside a coroutine
     * that is the process root — which outlives the application testbench rebuilds per
     * test. A value set by one test would otherwise still be in force in the next, and
     * the base items it is read against would be a different array by then.
     */
    protected function tearDown(): void
    {
        Context::current()->forget('config.overlay');
        Context::current()->forget('config.overlay.paths');

        parent::tearDown();
    }

    /**
     * @param Application $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            SConcurServiceProvider::class,
            WorkbenchServiceProvider::class,
            ...parent::getPackageProviders($app),
        ];
    }

    protected function getApp(): Application
    {
        assert($this->app !== null);

        return $this->app;
    }

    /**
     * artisan() is typed PendingCommand|int — it returns the exit code once the command
     * has been run. Every caller here wants the pending one, to assert on before
     * running it.
     *
     * @param array<string, mixed> $arguments
     */
    protected function command(string $name, array $arguments = []): PendingCommand
    {
        $command = $this->artisan($name, $arguments);

        assert($command instanceof PendingCommand);

        return $command;
    }
}
