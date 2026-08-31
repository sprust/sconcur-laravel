<?php

declare(strict_types=1);

use Demo\App\Http\Controllers\ConcurrencyController;
use Demo\App\Http\Controllers\DemoController;
use Demo\App\Http\Controllers\HeartbeatController;
use Demo\App\Http\Controllers\JobController;
use Demo\App\Http\Controllers\NoteController;
use Demo\App\Http\Controllers\ScalingController;
use Demo\App\Http\Controllers\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::get('health', [DemoController::class, 'health']);

Route::get('concurrent', ConcurrencyController::class);

Route::get('notes', [NoteController::class, 'index']);
Route::post('notes', [NoteController::class, 'store']);
Route::post('notes/bulk', [NoteController::class, 'bulk']);

Route::get('jobs', [JobController::class, 'index']);
Route::post('jobs', [JobController::class, 'store']);

Route::get('heartbeats', [HeartbeatController::class, 'index']);

Route::get('scaling', [ScalingController::class, 'index']);
Route::post('scaling', [ScalingController::class, 'store']);

Route::get('telemetry', [TelemetryController::class, 'index']);
