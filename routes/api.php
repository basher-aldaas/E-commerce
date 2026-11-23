<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every API endpoint is registered by its own module, in routes/api.php inside each module,
| under the /api/v1 and /api/v2 prefixes. Authentication is JWT (auth:api).
|
| The one exception is system diagnostics, which belong to no module.
|
*/

use App\Http\Controllers\System\WarehouseHealthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/system')
    ->middleware(['auth:api', 'can:view_system_status'])
    ->group(function () {
        Route::get('/warehouse', WarehouseHealthController::class);
    });
