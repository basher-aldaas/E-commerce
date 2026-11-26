<?php

namespace App\Http\Controllers\Realtime;

use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;

/**
 * What the web interface needs to open the WebSocket to Pusher: the public app
 * key and the cluster (both are meant for browsers; the secret never leaves the
 * server). `enabled` is false when BROADCAST_CONNECTION is not "pusher"; the
 * interface then works as before, without live updates.
 */
class BroadcastingConfigController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $pusher = config('broadcasting.connections.pusher');
        $enabled = config('broadcasting.default') === 'pusher' && filled($pusher['key'] ?? null);

        return Response::Success(
            data: [
                'enabled' => $enabled,
                'driver' => 'pusher',
                'key' => $enabled ? $pusher['key'] : null,
                'cluster' => $enabled ? ($pusher['options']['cluster'] ?? 'mt1') : null,
                'auth_endpoint' => '/broadcasting/auth',
            ],
            message: 'Broadcasting configuration',
        )->header('Cache-Control', 'no-store');
    }
}
