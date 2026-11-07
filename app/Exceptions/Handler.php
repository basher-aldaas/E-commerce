<?php

namespace App\Exceptions;

use App\Constants\HttpStatusConstants;
use App\Http\Responses\Response;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Centralised API exception handling.
 *
 * Converts every uncaught exception on an API request into the project's
 * standard JSON envelope (see {@see Response}) with a correct HTTP status,
 * so the frontend always receives a predictable error shape.
 */
class Handler
{
    /**
     * Register the API exception renderers on the application.
     */
    public static function register(Exceptions $exceptions): void
    {
        // Always answer API requests with JSON, never an HTML error page.
        $exceptions->shouldRenderJsonWhen(
            fn ($request, Throwable $e): bool => self::wantsJson($request)
        );

        $exceptions->render(function (Throwable $e, $request): ?JsonResponse {
            if (! self::wantsJson($request)) {
                return null; // Let Laravel render the default page for web requests.
            }

            return self::toResponse($e);
        });
    }

    protected static function wantsJson($request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    /**
     * Map a thrown exception to the standard JSON error response.
     */
    protected static function toResponse(Throwable $e): JsonResponse
    {
        // Our own API exceptions already know how to render themselves.
        if ($e instanceof ApiException) {
            return $e->render();
        }

        // 422 — validation failures (e.g. thrown manually inside services).
        if ($e instanceof ValidationException) {
            return Response::Validation(
                data: $e->errors(),
                message: 'Validation failed',
                code: HttpStatusConstants::HTTP_422_UNPROCESSABLE_ENTITY
            );
        }

        // 401 — missing / invalid token.
        if ($e instanceof AuthenticationException) {
            return Response::Error(
                data: [],
                message: 'Unauthenticated.',
                code: HttpStatusConstants::HTTP_401_UNAUTHORIZED
            );
        }

        // 403 — authenticated but not allowed (Gate / policy / can middleware).
        if ($e instanceof AuthorizationException || $e instanceof AccessDeniedHttpException) {
            return Response::Error(
                data: [],
                message: $e->getMessage() ?: 'This action is unauthorized.',
                code: HttpStatusConstants::HTTP_403_FORBIDDEN
            );
        }

        // 404 — unknown model or route.
        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return Response::Error(
                data: [],
                message: 'Resource not found.',
                code: HttpStatusConstants::HTTP_404_NOT_FOUND
            );
        }

        // Any other HTTP exception (abort(), 405, 429, ...) keeps its own status.
        if ($e instanceof HttpExceptionInterface) {
            return Response::Error(
                data: [],
                message: $e->getMessage() ?: 'HTTP error.',
                code: $e->getStatusCode()
            )->withHeaders($e->getHeaders()); // e.g. Retry-After on a 429.
        }

        // 500 — anything unexpected. Only leak details while debugging.
        return Response::Error(
            data: config('app.debug') ? [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ] : [],
            message: 'Server Error',
            code: HttpStatusConstants::HTTP_500_INTERNAL_SERVER_ERROR
        );
    }
}