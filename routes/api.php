<?php

use App\Http\Controllers\Api\IngestionController;
use App\Http\Controllers\ReadinessController;
use App\Http\Middleware\AuthenticateSource;
use Illuminate\Support\Facades\Route;

Route::get('/health/ready', ReadinessController::class);
Route::prefix('v1')->middleware(['throttle:ingestion-edge', AuthenticateSource::class])->group(function () {
    Route::post('/sales', [IngestionController::class, 'store']);
    Route::get('/sales/{reference}', [IngestionController::class, 'show'])->whereUuid('reference');
});
