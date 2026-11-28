<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Requirement 5: three app instances run behind Nginx. Every response names the
 * instance that produced it (X-Instance: app1 / app2 / app3), so the load test
 * can count how the requests were spread and a stopped instance visibly drops
 * out of the rotation.
 */
class InstanceHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        // النسخ الثلاثة بتكتب بنفس ملف السجل: كل سطر بيحمل اسم النسخة يلي كتبتو
        Log::shareContext(['instance' => (string) config('app.instance')]);

        $response = $next($request);

        $response->headers->set('X-Instance', (string) config('app.instance'));

        return $response;
    }
}
