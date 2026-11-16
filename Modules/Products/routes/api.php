<?php

use App\Http\Middleware\ConditionalGet;
use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Middleware\CatalogCacheHeaders;
use Modules\Products\Http\Controllers\ProductController;
use Modules\Products\Http\Controllers\CategoryController;
use Modules\Products\Http\Controllers\V2\ProductController as ProductControllerV2;

Route::prefix('v1')->middleware('auth:api')->group(function () {

    // Products
    Route::post('/products', [ProductController::class, 'store'])
        ->middleware('can:create_product');

    // Admin only. No DELETE: order items reference products; hide one with is_active=false.
    Route::put('/products/{product}', [ProductController::class, 'update'])
        ->middleware('can:update_product');

    // Catalog reads: Redis catalog cache (X-Cache, Server-Timing) + ETag/304, and
    // the only reads allowed on the MySQL read replica (reads.replica).
    Route::middleware([ConditionalGet::class, CatalogCacheHeaders::class, 'reads.replica'])->group(function () {
        Route::get('/products', [ProductController::class, 'index'])
            ->middleware('can:view_products');

        Route::get('/products/{product}', [ProductController::class, 'show'])
            ->middleware('can:view_products');

        Route::get('/categories', [CategoryController::class, 'index'])
            ->middleware('can:view_categories');
    });

    // Categories
    Route::post('/categories', [CategoryController::class, 'store'])
        ->middleware('can:create_category');
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
Route::prefix('v2')->middleware(['auth:api', ConditionalGet::class, CatalogCacheHeaders::class, 'reads.replica'])->group(function () {

    Route::get('/products', [ProductControllerV2::class, 'index'])
        ->middleware('can:view_products');

    Route::get('/products/{product}', [ProductControllerV2::class, 'show'])
        ->middleware('can:view_products');
});
