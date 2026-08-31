<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase;
use SConcur\Laravel\SConcurServiceProvider;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class BaseTestCase extends TestCase
{
    use WithWorkbench;

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
}
