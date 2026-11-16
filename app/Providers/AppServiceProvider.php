<?php

namespace App\Providers;

use App\Grpc\Warehouse\WarehouseClient;
use App\Services\Warehouse\DisabledWarehouseGateway;
use App\Services\Warehouse\GrpcWarehouseGateway;
use App\Services\Warehouse\WarehouseGateway;
use App\Support\Database\ReadRouting;
use Grpc\ChannelCredentials;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One read-routing decision per request (read replica, read-your-writes).
        $this->app->scoped(ReadRouting::class);

        //اذا خدمة المستودع غير مفعلة لا يحاول الاتصال بال grpc
        $this->app->singleton(WarehouseGateway::class, function () {
            if (! config('warehouse.enabled')) {
                return new DisabledWarehouseGateway();
            }

            return new GrpcWarehouseGateway(
                new WarehouseClient(config('warehouse.target'), [
                    'credentials' => ChannelCredentials::createInsecure(),
                ] + config('warehouse.channel', [])),
                config('warehouse.read_timeout_ms'),
                config('warehouse.write_timeout_ms'),
                config('warehouse.release_timeout_ms'),
                config('warehouse.connect_timeout_ms'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Read replica (Phase 4): every connection reads the PRIMARY from the
        // moment it is created: HTTP requests (including the JWT user lookup and
        // permission checks, which run before most middleware), console commands,
        // the scheduler and future queue workers. Only routes marked
        // `reads.replica` switch to the replica (App\Support\Database\ReadRouting).
        Event::listen(ConnectionEstablished::class, fn (ConnectionEstablished $event) => $event->connection->useWriteConnectionWhenReading(true));
        foreach (DB::getConnections() as $connection) {
            $connection->useWriteConnectionWhenReading(true);
        }

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

        // General API limit: per user when authenticated, otherwise per IP address.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(
            (int) config('app.api_rate_limit_per_minute')
        )->by($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }
}
