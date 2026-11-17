<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\IntegrationController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(auth()->check() ? '/dashboard' : '/login'));
Route::middleware('guest')->group(function () {
    Route::view('/login', 'auth.login')->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
});
Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/account', [AccountController::class, 'index']);
    Route::put('/account/password', [AccountController::class, 'password'])->middleware('throttle:6,1');
    Route::get('/team/{user}/edit', [TeamController::class, 'edit']);
    Route::patch('/team/{user}', [TeamController::class, 'update']);
    Route::post('/team/{user}/status', [TeamController::class, 'status']);
    Route::put('/team/{user}/password', [TeamController::class, 'password'])->middleware('throttle:6,1');
    Route::get('/dashboard', [WorkspaceController::class, 'index'])->name('dashboard');
    Route::post('/branches', [WorkspaceController::class, 'branch']);
    Route::post('/products', [WorkspaceController::class, 'product']);
    Route::get('/products/{product}/edit', [ProductController::class, 'edit']);
    Route::patch('/products/{product}', [ProductController::class, 'update']);
    Route::get('/inventory/transfers', [InventoryController::class, 'transfers']);
    Route::post('/inventory/transfers', [InventoryController::class, 'transfer']);
    Route::get('/inventory/{stock}/adjust', [InventoryController::class, 'editAdjustment']);
    Route::post('/inventory/{stock}/adjust', [InventoryController::class, 'adjust']);
    Route::post('/inventory/receive', [WorkspaceController::class, 'stock']);
    Route::post('/sales', [WorkspaceController::class, 'sale']);
    Route::post('/sales/{sale}/return', [WorkspaceController::class, 'reverse']);
    Route::post('/imports', [WorkspaceController::class, 'import']);
    Route::post('/team', [WorkspaceController::class, 'team']);
    Route::get('/reports', [ReportController::class, 'index']);
    Route::get('/sales/{sale}', [ReportController::class, 'sale']);
    Route::get('/reports/sales.csv', [WorkspaceController::class, 'export']);
    Route::get('/integrations', [IntegrationController::class, 'index']);
    Route::post('/integrations/sources', [IntegrationController::class, 'store'])->middleware('throttle:20,1');
    Route::post('/integrations/sources/{source}/rotate', [IntegrationController::class, 'rotate'])->middleware('throttle:20,1');
    Route::post('/integrations/sources/{source}/revoke', [IntegrationController::class, 'revoke']);
    Route::post('/integrations/events/{event}/retry', [IntegrationController::class, 'retry'])->middleware('throttle:30,1');
    Route::get('/{page}', [WorkspaceController::class, 'index'])->whereIn('page', ['sales', 'inventory', 'branches', 'products', 'imports', 'activity', 'team']);
});
