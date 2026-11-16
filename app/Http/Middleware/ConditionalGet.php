<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ETag / If-None-Match for JSON GET endpoints.
 *
 * The ETag is a hash of the representation actually being sent (the envelope
 * without its per-request `timestamp`). It is computed after the response is
 * built, including live Warehouse stock, so it changes whenever the data does:
 * a cache invalidation, a new product or a stock change can never be answered
 * with a stale 304. A 304 saves the transfer, not the server work.
 *
 * Responses are `private, no-cache`: they need a JWT, so shared caches must not
 * keep them, and the browser must revalidate (cheaply, with If-None-Match).
 */
class ConditionalGet
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethodCacheable()
            || $response->getStatusCode() !== Response::HTTP_OK
            || ! $response instanceof JsonResponse) {
            return $response;
        }

        $payload = $response->getData(true);
        unset($payload['timestamp']);

        $response->setEtag(hash('sha256', json_encode($payload)), weak: true);
        $response->headers->set('Cache-Control', 'private, no-cache');

        // Turns the response into a bodiless 304 when If-None-Match matches.
        $response->isNotModified($request);

        return $response;
    }
}
