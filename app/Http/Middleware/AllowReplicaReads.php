<?php

namespace App\Http\Middleware;

use App\Support\Database\ReadRouting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware `reads.replica`: this route's reads may come from the read
 * replica, unless the signed-in user wrote something moments ago (then the
 * primary, so they always see their own change). Used on catalog reads only.
 */
class AllowReplicaReads
{
    public function __construct(private readonly ReadRouting $routing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = auth('api')->user();

        if ($user === null || ! $this->routing->hasRecentWrite((int) $user->getAuthIdentifier())) {
            $this->routing->allowReplica();
        }

        return $next($request);
    }
}
