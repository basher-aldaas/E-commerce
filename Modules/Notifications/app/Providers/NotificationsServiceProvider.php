<?php

namespace Modules\Notifications\Providers;

use Modules\Notifications\Console\ConsumeOrderCreatedEmails;
use Modules\Notifications\Console\ReplayOrderEmailDeadLetters;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Illuminate\Console\Scheduling\Schedule;

class NotificationsServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'Notifications';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'notifications';

    /**
     * Command classes to register.
     *
     * @var string[]
     */
    protected array $commands = [
        ConsumeOrderCreatedEmails::class,
        ReplayOrderEmailDeadLetters::class,
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
    // protected function configureSchedules(Schedule $schedule): void
    // {
    //     $schedule->command('inspire')->hourly();
    // }
}
