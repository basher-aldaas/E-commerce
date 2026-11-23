<?php

namespace Modules\Products\Http\Middleware;

use App\Support\ServerTiming;
use Closure;
use Illuminate\Http\Request;
use Modules\Products\Services\CatalogCache;
use Symfony\Component\HttpFoundation\Response;

class CatalogCacheHeaders
{
    public function __construct(
        private readonly CatalogCache $cache,
        private readonly ServerTiming $timing,
    ) {}

    //هاد الميدلوير بحطلي معلومات عن الكاش ومكان قراءة الداتا primary,replica  بال هيدر يعني مشابه للريسبونس
    public function handle(Request $request, Closure $next): Response
    {
        //هون تصفير حالة الكاش يعني ما بخزن hit دائما بل حسب شو بلاقي بالكاش hit ,miss,babas  وبخزنه بهاد المتغير
        //بالاحرى انا ما صفرت الكاش بل صفرت اعدادات الكاتالوغ
        $this->cache->reset();
        $this->timing->reset();

        $response = $next($request);

        if ($this->cache->status() !== null) {
            $response->headers->set('X-Cache', $this->cache->status());//hit , miss ,bypass
        }

        if ($this->cache->source() !== null) {
            $response->headers->set('X-Read-Source', $this->cache->source());
        }

        if (($timing = $this->timing->header()) !== null) {
            $response->headers->set('Server-Timing', $timing);
        }

        return $response;
    }
}
