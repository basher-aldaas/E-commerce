<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;
use Modules\Orders\Http\Controllers\PerfOrdersController;
use Modules\Orders\Http\Controllers\ReservationDiagnosticsController;

// Admin diagnostics (read-only), next to the model they read.
Route::prefix('v1/system')
    ->middleware(['auth:api', 'can:view_system_status'])
    ->group(function () {
        Route::get('/reservations', [ReservationDiagnosticsController::class, 'index']);
    });

// Performance demonstration (Requirement 3, N+1). Not used by the store.
Route::prefix('v1/perf')
    ->middleware(['auth:api', 'can:view_system_status'])
    ->group(function () {
        Route::get('/orders', [PerfOrdersController::class, 'index']);
    });

Route::prefix('v1')
    ->middleware('auth:api')
    ->group(function () {
        Route::get('/orders/{orderId}', [OrderController::class, 'show'])->middleware('can:view_orders');
        Route::get('/orders', [OrderController::class, 'index'])
            ->middleware( 'can:view_orders');
        Route::post('/orders/{orderId}/status', [OrderController::class, 'updateStatus'])
            ->middleware( 'can:update_order_status');
                Route::post('/orders', [OrderController::class, 'store'])->middleware('can:create_order');
                Route::get('/orders/{orderId}/track', [OrderController::class, 'trackStatus',])->middleware('can:track_order_status');
    });
