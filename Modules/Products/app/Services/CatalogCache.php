<?php

namespace Modules\Products\Services;

use App\Support\Database\ReadRouting;
use App\Support\ServerTiming;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-through cache for catalog data (products and categories). Stock is never
 * part of a cached value: callers attach live Warehouse stock after reading.
 *
 * Keys are "catalog:{version}:{key}". flush() increments the version, so after any
 * product or category write every older entry is simply never read again and
 * expires by its TTL. Scoped per request: status() reports what happened to this
 * request's lookup (HIT, MISS, BYPASS, SKIP, OFF or ERROR) for the X-Cache header.
 */
class CatalogCache
{
    //رقم يدل على انو كم مرة حملت الالاصناف او المنتجات عالكاش
    public const VERSION_KEY = 'catalog:version';
    //متغير بحطه بالبوستمكمان لتجاوز الكاش والاستعلام من القاعدة فورا
    public const BYPASS_HEADER = 'X-Cache-Bypass';

    // متغير يحتفظ بنتيجة عملية الكاش hit,miss,bypass,skip,off,error
    private ?string $status = null;

    public function __construct(
        private readonly ServerTiming $timing,  //قياس زمن تنفيذ العمليات
        private readonly ReadRouting $routing,// لتوجيه القراءة الى قاعدة البيانات المناسبة الاساسية او الريبليكا
    ) {}

    /** Forget the previous request's outcome (the middleware calls this first). */
    public function reset(): void
    {
        $this->status = null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    //مسؤل عن قراءة البيانات من الريدس واذا ما لقتها بتجيبها من الداتا
    //دالة الريميمبر هي اساسية في اللارافلي كاشينغ وبتشوف اذا كان المفتاح موجود وما نتهت صلاحيته جيب من الكاش والا نفذ الاستعلام على الداتا وخزن نتيجته بالكاش
    public function remember(string $key, Closure $load): mixed
    {
        if (! config('products.cache.enabled')) {
            return $this->uncached('OFF', $load);
        }

        if ($this->bypassRequested()) {
            return $this->uncached('BYPASS', $load);
        }

        $started = hrtime(true);
        $hit = true;

        try {
            $value = $this->store()->remember(
                'catalog:'.$this->version().':'.$key,
                (int) config('products.cache.ttl'),
                function () use ($load, &$hit) {
                    $hit = false;

                    // A refill is stored for the whole TTL, so it must read the
                    // primary: a lagging replica would put stale data in Redis.
                    return $this->routing->onPrimary($load);
                }
            );
        } catch (Throwable $e) {
            // A cache outage must never take the catalog down: read the database.
            Log::error('Catalog cache unavailable; reading the database', ['key' => $key, 'error' => $e->getMessage()]);

            return $this->uncached('ERROR', $load);
        }

        $this->status = $hit ? 'HIT' : 'MISS';
        $this->timing->add('catalog', (hrtime(true) - $started) / 1e6, $this->status);

        return $value;
    }

    /** For a read that must not be cached at all (it depends on live stock). */
    public function skip(Closure $load): mixed
    {
        return $this->uncached('SKIP', $load);
    }

    /** Invalidates every catalog entry at once. Called on every product or category write. */
    public function flush(): void
    {
        try {
            $this->store()->increment(self::VERSION_KEY);
        } catch (Throwable $e) {
            // The write itself already succeeded; old entries now live until their TTL.
            Log::error('Catalog cache could not be invalidated', ['error' => $e->getMessage()]);
        }
    }

    public function status(): ?string
    {
        return $this->status;
    }

    private function uncached(string $status, Closure $load): mixed
    {
        $this->status = $status;

        return $this->timing->measure('catalog', $load, $status);
    }

    private function bypassRequested(): bool
    {
        return config('products.cache.allow_bypass_header')
            && request()->header(self::BYPASS_HEADER) === '1';
    }

    private function version(): int
    {
        $store = $this->store();
        $version = $store->get(self::VERSION_KEY);

        if ($version === null) {
            $store->forever(self::VERSION_KEY, 1);

            return 1;
        }

        return (int) $version;
    }

    private function store(): Repository
    {
        return Cache::store(config('products.cache.store'));
    }
}
