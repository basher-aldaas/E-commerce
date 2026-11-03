<?php

namespace Modules\Users\Http\Controllers;

use App\Constants\HttpStatusConstants;
use App\Http\Controllers\Controller;
use App\Http\Responses\Response;
use Illuminate\Http\JsonResponse;
use Modules\Users\Http\Requests\LoginRequest;
use Modules\Users\Http\Requests\RegisterRequest;
use Modules\Users\Services\AuthService;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

    #[OA\Post(
        path: '/api/v1/auth/register',
        tags: ['Auth'],
        summary: 'Register a new user account',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name', 'email', 'password', 'password_confirmation'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255, example: 'John Doe'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'john@example.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', minLength: 8, example: 'password123'),
                    new OA\Property(property: 'password_confirmation', type: 'string', format: 'password', example: 'password123'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 201,
                description: 'User registered successfully',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthResult'),
                    new OA\Property(property: 'message', type: 'string', example: 'User registered successfully'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 201),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
        ],
    )]
    public function register(RegisterRequest $request): JsonResponse
    {
        $result = $this->authService->register(
            $request->validated()
        );

        return Response::Success(
            data: $result,
            message: 'User registered successfully',
            code: HttpStatusConstants::HTTP_201_CREATED
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/login',
        tags: ['Auth'],
        summary: 'Log in and obtain a JWT',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['email', 'password'],
                properties: [
                    new OA\Property(property: 'email', type: 'string', format: 'email', example: 'user@system.com'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', example: 'password123'),
                ],
            ),
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login successful',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', ref: '#/components/schemas/AuthResult'),
                    new OA\Property(property: 'message', type: 'string', example: 'Login successful'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Invalid email or password', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 429, description: 'Too many login attempts', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
            new OA\Response(response: 422, description: 'Validation failed', content: new OA\JsonContent(ref: '#/components/schemas/ValidationErrorEnvelope')),
        ],
    )]
    public function login(LoginRequest $request): JsonResponse
    {
        $key = Str::lower($request->input('email')).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, 2)) {
            return Response::Error(
                data: [],
                message: 'Too many login attempts. Please try again later.',
                code: HttpStatusConstants::HTTP_429_TOO_MANY_REQUESTS
            );
        }

        $result = $this->authService->login(
            $request->validated()
        );

        if ($result === null) {
            RateLimiter::hit($key, 60);

            return Response::Error(
                data: [],
                message: 'Invalid email or password',
                code: HttpStatusConstants::HTTP_401_UNAUTHORIZED
            );
        }

        RateLimiter::clear($key);

        return Response::Success(
            data: $result,
            message: 'Login successful',
        );
    }

    #[OA\Post(
        path: '/api/v1/auth/logout',
        tags: ['Auth'],
        summary: 'Invalidate the current JWT',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout successful',
                content: new OA\JsonContent(properties: [
                    new OA\Property(property: 'status', type: 'integer', example: 1),
                    new OA\Property(property: 'data', type: 'array', items: new OA\Items(), example: []),
                    new OA\Property(property: 'message', type: 'string', example: 'Logout successful'),
                    new OA\Property(property: 'status_code', type: 'integer', example: 200),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time'),
                ]),
            ),
            new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/ErrorEnvelope')),
        ],
    )]
    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return Response::Success(
            data: [],
            message: 'Logout successful',
        );
    }
}
