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
class AuthController extends Controller
{
    public function __construct(
        private readonly AuthService $authService
    ) {}

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

    public function logout(): JsonResponse
    {
        $this->authService->logout();

        return Response::Success(
            data: [],
            message: 'Logout successful',
        );
    }
}
