<?php

namespace Modules\Products\Providers;

use App\Support\ServerTiming;
use Illuminate\Console\Scheduling\Schedule;
use Modules\Products\Services\CatalogCache;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ProductsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Products';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'products';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    // protected array $commands = [];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // One instance per request, so the X-Cache / Server-Timing headers report
        // what this request's lookups did.
        $this->app->scoped(ServerTiming::class);
        $this->app->scoped(CatalogCache::class);
    }

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
