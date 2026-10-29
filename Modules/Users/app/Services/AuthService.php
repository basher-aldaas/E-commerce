<?php

namespace Modules\Users\Services;

    use Illuminate\Support\Facades\Hash;
    use Modules\Users\Models\User;

class AuthService
{

    public function register(array $data): array
    {
        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);

        $user->assignRole('user');
        $token = auth('api')->login($user);

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values(),
            ],
        ];
    }

    public function login(array $credentials): ?array
    {
        $token = auth('api')->attempt($credentials);

        if (! $token) {
            return null;
        }

        $user = auth('api')->user();

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values(),
            ],
        ];
    }

    public function logout(): void
    {
        auth('api')->logout();
    }

}
