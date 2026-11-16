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
     * The web interface (public/app.html + public/assets) has no inline scripts,
     * styles or handlers: everything is built with DOM APIs from same-origin
     * files, so no 'unsafe-inline' is needed. Product images may be absolute https
     * URLs; blob: is the local preview of an image chosen for upload.
     */
    private const APP_POLICY = "default-src 'self'; "
        ."script-src 'self'; "
        ."style-src 'self'; "
        ."img-src 'self' data: blob: https:; "
        ."font-src 'self'; "
        ."connect-src 'self'; "
        ."object-src 'none'; "
        ."frame-ancestors 'none'; "
        ."base-uri 'self'; "
        ."form-action 'self'";

    /**
     * Swagger UI (vendor view) ships inline scripts and styles, so it alone keeps
     * 'unsafe-inline', and only on its own path.
     */
    private const SWAGGER_POLICY = "default-src 'self'; "
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
        $headers->set('X-Frame-Options', 'DENY'); // يمنع تضمين موقعي داخل فريم لموقع اخرليحميني من هجوم clickjacking
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        $isHtml = str_contains((string) $headers->get('Content-Type'), 'text/html');
        $headers->set('Content-Security-Policy', match (true) {
            ! $isHtml => self::API_POLICY,
            $request->is('api/documentation*') => self::SWAGGER_POLICY,
            default => self::APP_POLICY,
        });

        // HSTS is ignored over plain HTTP, and must not affect local development.
        if ($request->secure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
