<?php

declare(strict_types=1);

namespace SConcur\Laravel\Tests\Feature\Filesystem;

use Illuminate\Filesystem\Filesystem as IlluminateFilesystem;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use PHPUnit\Framework\Attributes\Test;
use SConcur\Laravel\Filesystem\Filesystem;
use SConcur\Laravel\Tests\Feature\BaseTestCase;

/**
 * The `files` binding is the application's decision: the framework resolves it on every
 * request, so installing the package leaves it alone until the config asks.
 */
class FilesystemBindingTest extends BaseTestCase
{
    #[Test]
    public function theFrameworksFilesystemStaysByDefault(): void
    {
        $files = $this->getApp()->make('files');

        self::assertSame(IlluminateFilesystem::class, $files::class);
    }

    #[Test]
    #[DefineEnvironment('putFilesOnTheFeature')]
    public function theConfigPutsFilesOnTheFeature(): void
    {
        self::assertInstanceOf(Filesystem::class, $this->getApp()->make('files'));
    }

    protected function putFilesOnTheFeature(Application $app): void
    {
        $app['config']->set('sconcur.filesystem.files', true);
    }
}
