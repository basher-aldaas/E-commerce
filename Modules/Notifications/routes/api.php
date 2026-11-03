<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationsController;

Route::middleware(['auth:api'])->prefix('v1')->group(function () {
    Route::apiResource('notifications', NotificationsController::class)->names('notifications');
});
