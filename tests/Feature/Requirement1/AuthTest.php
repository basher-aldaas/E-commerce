<?php

namespace Tests\Feature\Requirement1;

use Illuminate\Support\Facades\Mail;
use Modules\Users\Mail\WelcomeMail;

class AuthTest extends ApiTestCase
{
    public function test_register_returns_a_token_and_the_user_role(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'New User',
            'email' => 'new@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertLessThan(300, $response->status());
        $response->assertJsonPath('status', 1)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'new@example.test')
            ->assertJsonPath('data.user.roles', ['user']);
        $this->assertNotEmpty($response->json('data.access_token'));
        Mail::assertQueued(WelcomeMail::class);
    }

    public function test_register_rejects_a_duplicate_email(): void
    {
        $existing = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Copy',
            'email' => $existing->email,
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $this->assertEnvelope($response, 422, success: false);
    }

    public function test_login_returns_a_token_that_authenticates_requests(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $this->assertEnvelope($response, 200);
        $token = $response->json('data.access_token');
        $this->assertNotEmpty($token);

        $this->resetAuthState();
        $this->withToken($token)->getJson('/api/v1/products')->assertOk();
    }

    public function test_login_rejects_a_wrong_password(): void
    {
        $user = $this->makeUser();

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertEnvelope($response, 401, success: false);
    }

    public function test_login_is_rate_limited_after_two_failed_attempts(): void
    {
        $user = $this->makeUser();
        $wrong = ['email' => $user->email, 'password' => 'wrong-password'];

        $this->postJson('/api/v1/auth/login', $wrong)->assertStatus(401);
        $this->postJson('/api/v1/auth/login', $wrong)->assertStatus(401);

        $this->assertEnvelope($this->postJson('/api/v1/auth/login', $wrong), 429, success: false);
    }

    public function test_successful_login_clears_failed_attempts(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk();

        $this->resetAuthState();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertStatus(401);
    }

    public function test_logout_invalidates_the_token(): void
    {
        $this->actingAsApi($this->makeUser());

        $this->postJson('/api/v1/auth/logout')->assertOk();

        $this->resetAuthState();
        $this->getJson('/api/v1/products')->assertStatus(401);
    }

    public function test_protected_routes_reject_missing_and_invalid_tokens(): void
    {
        foreach (['/api/v1/products', '/api/v2/products', '/api/v1/orders', '/api/v1/categories', '/api/v1/notifications'] as $uri) {
            $this->assertEnvelope($this->asGuest()->getJson($uri), 401, success: false);
        }

        $this->resetAuthState();
        $this->withToken('not-a-real-token')->getJson('/api/v1/products')->assertStatus(401);
    }
}
