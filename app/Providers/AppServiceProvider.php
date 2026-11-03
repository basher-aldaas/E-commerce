<?php

namespace App\Providers;

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // `php artisan serve` only forwards a fixed allow-list of environment
        // variables to its child PHP server; everything else is dropped, so
        // environment overrides set outside .env are ignored and Laravel falls
        // back to the .env value. Forward the DB overrides so the dev server
        // honours them.
        ServeCommand::$passthroughVariables = array_merge(ServeCommand::$passthroughVariables, [
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
        ]);
    }
}
