<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use App\Services\Warehouse\WarehouseHealthProbe;
use Illuminate\Http\JsonResponse;

/** Admin diagnostics: the Warehouse's health right now (see WarehouseHealthProbe). */
class WarehouseHealthController extends Controller
{
    public function __invoke(WarehouseHealthProbe $probe): JsonResponse
    {
        return Response::Success(
            data: $probe->check()->toArray(),
            message: 'Warehouse health checked',
        )->header('Cache-Control', 'no-store');
    }
}
