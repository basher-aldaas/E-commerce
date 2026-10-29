<?php

use Illuminate\Support\Facades\Route;
use Modules\Users\Http\Controllers\AuthController;

Route::prefix('v1')
    ->group(function () {
        Route::prefix('auth')->controller(AuthController::class)->group(function () {
                Route::post('/register', 'register');
                Route::post('/login', 'login');
                Route::post('/logout', 'logout')->middleware('auth:api');
            });
    });


