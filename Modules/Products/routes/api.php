<?php

use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Controllers\ProductController;
use Modules\Products\Http\Controllers\CategoryController;
use Modules\Products\Http\Controllers\InventoryController;
use Modules\Products\Http\Controllers\V2\ProductController as ProductControllerV2;

Route::prefix('v1')->middleware('auth:api')->group(function () {

    // Products
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('can:create_product');

    Route::get('/products', [ProductController::class, 'index'])
        ->middleware('can:view_products');

    Route::get('/products/{product}', [ProductController::class, 'show'])
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

/*
|--------------------------------------------------------------------------
| Products API v2
|--------------------------------------------------------------------------
|
| v2 adds filtering/search on the products listing, returns only active
| products by default, and uses a richer response shape (see V2\ProductResource).
| v1 above is left unchanged for existing clients.
|
*/
Route::prefix('v2')->middleware('auth:api')->group(function () {

    Route::get('/products', [ProductControllerV2::class, 'index'])
        ->middleware('can:view_products');

    Route::get('/products/{product}', [ProductControllerV2::class, 'show'])
        ->middleware('can:view_products');
});
