<?php

return [
    'name' => 'Products',

    /*
    |--------------------------------------------------------------------------
    | Catalog cache
    |--------------------------------------------------------------------------
    |
    | Caches catalog data only (product rows with their category, and category
    | lists) for the v1 and v2 product endpoints. Live stock is never cached: it
    | is read from the Warehouse on every request, after the cache. Every product
    | or category write bumps a version number, so all old entries stop being used
    | at once; the TTL is a safety net on top of that.
    |
    | X-Cache-Bypass: 1 on a request skips the cache for that request only, so the
    | same request can be timed with and without it. Allowed outside production.
    |
    */
    'cache' => [
        'enabled' => (bool) env('PRODUCT_CACHE_ENABLED', true),
        'ttl' => (int) env('PRODUCT_CACHE_TTL', 300),
        // null = the default cache store (Redis in Docker).
        'store' => env('PRODUCT_CACHE_STORE'),
        'allow_bypass_header' => (bool) env('PRODUCT_CACHE_ALLOW_BYPASS', env('APP_ENV', 'production') !== 'production'),
    ],
];
