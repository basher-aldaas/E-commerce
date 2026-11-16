<?php

use App\Exceptions\Handler;
use App\Http\Middleware\AllowReplicaReads;
use App\Http\Middleware\PrimaryReadsByDefault;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // This is an API-only app with no `login` web route. Laravel's default
        // guest redirect (ApplicationBuilder sets redirectGuestsTo(route('login')))
        // is resolved eagerly inside the Authenticate middleware for any request
        // that does not expect JSON (e.g. hitting an /api URL directly in the
        // browser), throwing RouteNotFoundException -> 500. Returning null here
        // lets unauthenticated requests surface as a clean 401 JSON response via
        // App\Exceptions\Handler instead.
        $middleware->redirectGuestsTo(fn () => null);

        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi();
        $middleware->prependToGroup('api', RejectMalformedJson::class);

        // Read replica (Phase 4): primary by default, catalog routes opt in.
        // `reads.replica` runs after the permission check (Authorize), so only
        // the controller's catalog queries can reach the replica.
        $middleware->appendToGroup('api', PrimaryReadsByDefault::class);
        $middleware->alias(['reads.replica' => AllowReplicaReads::class]);
        $middleware->appendToPriorityList(Authorize::class, AllowReplicaReads::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Handler::register($exceptions);
    })->create();
