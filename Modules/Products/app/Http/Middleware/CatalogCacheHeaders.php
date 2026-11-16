<?php

namespace Modules\Products\Http\Middleware;

use App\Support\ServerTiming;
use Closure;
use Illuminate\Http\Request;
use Modules\Products\Services\CatalogCache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the catalog cache observable on every catalog response:
 *
 *   X-Cache: HIT | MISS | BYPASS | SKIP | OFF | ERROR
 *   Server-Timing: catalog;dur=0.9;desc="HIT", stock;dur=3.2
 *
 * `catalog` is the time spent getting product/category data (from Redis or from
 * MySQL), `stock` the live GetStock call to the Warehouse.
 */
class CatalogCacheHeaders
{
    public function __construct(
        private readonly CatalogCache $cache,
        private readonly ServerTiming $timing,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // The services are scoped, but a long-running process (tests, Octane) can
        // serve several requests with one instance: start each request clean.
        $this->cache->reset();
        $this->timing->reset();

        $response = $next($request);

        if ($this->cache->status() !== null) {
            $response->headers->set('X-Cache', $this->cache->status());
        }

        if (($timing = $this->timing->header()) !== null) {
            $response->headers->set('Server-Timing', $timing);
        }

        return $response;
    }
}
