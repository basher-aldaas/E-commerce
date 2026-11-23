<?php

namespace Modules\Products\Services;

use App\Support\ServerTiming;
use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDOException;
use Throwable;

class CatalogCache
{
    //رقم يدل على انو كم مرة حملت الالاصناف او المنتجات عالكاش
    //يعني وقت بحمل اول مرة المنتجات عالكاش بحط catalog:version 1 وقت بعدل الادمن المنتجات ما بروح بدور عالمفتيح تبع عالمنتجات يلي بالكاش واحذفن مباششرة لانو محتمل يكون عندي الاف المفتيح
    //للك اعمل نسخة ثانية وcatalog:version 2 والمنتجات وقت بخزنهن عالكاش بصيرو v2  والمفتيح القديمة بينتهو بس يخلص ال ttl
    public const VERSION_KEY = 'catalog:version';
    // آخر GTID من الـ Primary بعد آخر كتابة على الكتالوج
    public const GTID_KEY = 'catalog:gtid';
    //متغير بحطه بالبوستمكمان لتجاوز الكاش والاستعلام من القاعدة فورا
    public const BYPASS_HEADER = 'X-Cache-Bypass';

    // متغير يحتفظ بنتيجة عملية الكاش hit,miss,bypass,skip,off,error
    private ?string $status = null;

    // مصدر القراءة من القاعدة: replica, primary, primary-fallback (null عند hit)
    private ?string $source = null;

    public function __construct(
        private readonly ServerTiming $timing,  //قياس زمن تنفيذ العمليات
    ) {}

    public function reset(): void
    {
        $this->status = null;
        $this->source = null;
    }

    //مسؤل عن قراءة البيانات من الريدس واذا ما لقتها بتجيبها من الداتا
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
        $loadFailure = null;

        try {
            $value = $this->store()->remember(
                'catalog:'.$this->version().':'.$key,
                (int) config('products.cache.ttl'),
                function () use ($load, &$hit, &$loadFailure) {
                    $hit = false;

                    try {
                        return $this->readDatabase($load);
                    } catch (Throwable $e) {
                        $loadFailure = $e;

                        throw $e;
                    }
                }
            );
        } catch (Throwable $e) {
            if ($e === $loadFailure) {
                throw $e;
            }

            Log::error('Catalog cache unavailable; reading the database', ['key' => $key, 'error' => $e->getMessage()]);

            return $this->uncached('ERROR', $load);
        }

        $this->status = $hit ? 'HIT' : 'MISS';
        $this->timing->add('catalog', (hrtime(true) - $started) / 1e6, $this->status);

        return $value;
    }

    public function skip(Closure $load): mixed
    {
        return $this->uncached('SKIP', $load);
    }

    //منزيد رقم الاصدار بدل البحث عن المفاتيح وحذغ ال gtid key  يلي موجود عندي لانو صار في تعديل عالداتا الاساية
    public function flush(): void
    {
        try {
            $this->store()->forget(self::GTID_KEY);
            $this->store()->increment(self::VERSION_KEY);
        } catch (Throwable $e) {
            Log::error('Catalog cache could not be invalidated', ['error' => $e->getMessage()]);
        }
    }

    public function status(): ?string
    {
        return $this->status;
    }

    public function source(): ?string
    {
        return $this->source;
    }

    //في حال كانت الكاش واقفة off or bypass =1  قراء من الداتا بيز
    private function uncached(string $status, Closure $load): mixed
    {
        $this->status = $status;

        return $this->timing->measure('catalog', fn () => $this->readDatabase($load), $status);
    }

    private function readDatabase(Closure $load): mixed
    {
        $replica = config('database.read_connection');
        //اذا كانت اتصال القراءة هوة ريبليكا وكانت الgiid  يلي بالاساسية نفسها بالريبليكا
        if ($replica !== config('database.default') && ($gtid = $this->latestCatalogWrite()) !== null) {
            try {
                //التحقق من انو الريبليكا نفذت جميع الترانزيكشن تبع الاساسية
                $current = (bool) DB::connection($replica)
                    ->selectOne('SELECT GTID_SUBSET(?, @@GLOBAL.gtid_executed) AS applied', [$gtid])
                    ->applied;

                if ($current) {
                    $value = DB::usingConnection($replica, $load);
                    $this->source = 'replica';

                    return $value;
                }
            } catch (PDOException $e) {
                Log::warning('Read replica unavailable; reading the primary', ['error' => $e->getMessage()]);
                $this->source = 'primary-fallback';

                return $load();
            }
        }

        // No replica configured, or it has not applied the latest catalog write yet.
        $this->source = 'primary';

        return $load();
    }

  //بجيب اخر gtid  تم كتابته في البرايمري وبخزنه بالكاش للتأكد انو primary  ما عليها تعديلات ما موجودة بالريبليكا
    private function latestCatalogWrite(): ?string
    {
        try {
            return (string) $this->store()->rememberForever(
                self::GTID_KEY,
                fn () => DB::connection(config('database.default'))
                    ->selectOne('SELECT @@GLOBAL.gtid_executed AS gtid')
                    ->gtid
            );
        } catch (Throwable $e) {
            Log::warning('Latest catalog write unknown; reading the primary', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function bypassRequested(): bool
    {
        return config('products.cache.allow_bypass_header')
            && request()->header(self::BYPASS_HEADER) === '1';
    }

    //بجيب رقم اخر فرجين عندي
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
