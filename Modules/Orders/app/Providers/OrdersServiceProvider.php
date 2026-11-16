<?php

namespace Modules\Orders\Providers;

use Modules\Orders\Console\ReconcileOrderReservations;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class OrdersServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Orders';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'orders';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ReconcileOrderReservations::class,
    ];

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    /**
     * Define module schedules.
     * 
     * @param $schedule
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        // withoutOverlapping: skip a run while the previous one is still going (the
        // lock expires after 10 minutes if a run dies). onOneServer: only one
        // scheduler may run it per minute, even if several are started later.
        // Both use the shared cache store's atomic locks.
        $schedule->command('warehouse:reconcile')
            ->everyMinute()
            ->withoutOverlapping(10)
            ->onOneServer();
    }
}
