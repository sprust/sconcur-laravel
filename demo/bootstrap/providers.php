<?php

declare(strict_types=1);

use Demo\App\Providers\DemoServiceProvider;
use SConcur\Laravel\SConcurServiceProvider;

/*
| The package provider is named here rather than left to auto-discovery. Discovery reads
| vendor/composer/installed.json, and the root package is never in it — the application
| demonstrating this package is inside the package itself. An application that installs
| sconcur/laravel the ordinary way needs neither line.
*/
return [
    SConcurServiceProvider::class,
    DemoServiceProvider::class,
];
