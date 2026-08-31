<?php

declare(strict_types=1);

use Demo\App\Http\Controllers\DemoController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DemoController::class, 'index'])->name('demo.index');
