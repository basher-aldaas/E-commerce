<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductController;
use Modules\Products\Http\Controllers\CategoryController;
use Modules\Products\Http\Controllers\InventoryController;

Route::prefix('v1')->middleware('auth:api')->group(function () {

    // Products
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('can:create_product');

    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('can:view_products');

    // Categories
    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('can:create_category');

    Route::get('/categories', [CategoryController::class, 'index'])
        ->middleware('can:view_categories');

    // Inventory
    Route::patch('/inventory/{product}', [InventoryController::class, 'update'])
        ->middleware('can:manage_inventory');
});
