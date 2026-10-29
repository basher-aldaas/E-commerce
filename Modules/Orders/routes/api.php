<?php

use Illuminate\Support\Facades\Route;
use Modules\Orders\Http\Controllers\OrderController;

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
