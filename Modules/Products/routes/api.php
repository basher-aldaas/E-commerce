<?php

use App\Http\Middleware\ConditionalGet;
use Illuminate\Support\Facades\Route;
use Modules\Products\Http\Middleware\CatalogCacheHeaders;
use Modules\Products\Http\Controllers\ProductController;
use Modules\Products\Http\Controllers\CategoryController;
use Modules\Products\Http\Controllers\V2\ProductController as ProductControllerV2;

Route::prefix('v1')->middleware('auth:api')->group(function () {
    Route::post('/products', [ProductController::class, 'store'])->middleware('can:create_product');
    Route::put('/products/{product}', [ProductController::class, 'update'])->middleware('can:update_product');    // Admin only. No DELETE: order items reference products; hide one with is_active=false.


//توابع نسخة اولى فيها فلاتر وتخزين بالكاش  وETag و عرض الهيدرز  Server-Timing,X-Read-Source,X-Cache
    Route::middleware([ConditionalGet::class, CatalogCacheHeaders::class])->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->middleware('can:view_products');
        Route::get('/products/{product}', [ProductController::class, 'show'])->middleware('can:view_products');
        Route::get('/categories', [CategoryController::class, 'index'])->middleware('can:view_categories');
    });
    Route::post('/categories', [CategoryController::class, 'store'])->middleware('can:create_category');
});

//توابع نسخة ثانية فيها فلاتر وتخزين بالكاش  وETag و عرض الهيدرز  Server-Timing,X-Read-Source,X-Cache
Route::prefix('v2')->middleware(['auth:api', ConditionalGet::class, CatalogCacheHeaders::class])->group(function () {
    Route::get('/products', [ProductControllerV2::class, 'index'])->middleware('can:view_products');
    Route::get('/products/{product}', [ProductControllerV2::class, 'show'])->middleware('can:view_products');
});
