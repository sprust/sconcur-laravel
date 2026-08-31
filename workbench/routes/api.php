<?php

declare(strict_types=1);

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;

/**
 * Routes the adapter tests drive a request through. Kept trivial: what is under test is
 * the router and the request scoping around them, not what they answer.
 */
Route::get('workbench/ping', static fn(): JsonResponse => response()->json([
    'ok'     => true,
    'locale' => app()->getLocale(),
]))->name('workbench.ping');
