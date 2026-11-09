<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    /** JSON responses never load anything, so they can forbid everything. */
    private const API_POLICY = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'; form-action 'none'";

    /**
     * public/app.html and Swagger UI use inline scripts, styles and handlers, so
     * HTML pages need 'unsafe-inline'. Product images may be absolute https URLs.
     */
    private const HTML_POLICY = "default-src 'self'; "
        ."script-src 'self' 'unsafe-inline'; "
        ."style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: https:; "
        ."font-src 'self' data:; "
        ."connect-src 'self'; "
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'";

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $isHtml = str_contains((string) $headers->get('Content-Type'), 'text/html');
        $headers->set('Content-Security-Policy', $isHtml ? self::HTML_POLICY : self::API_POLICY);

        // HSTS is ignored over plain HTTP, and must not affect local development.
        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
