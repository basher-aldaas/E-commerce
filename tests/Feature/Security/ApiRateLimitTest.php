<?php

namespace Tests\Feature\Security;

use Tests\Feature\Requirement1\ApiTestCase;

class ApiRateLimitTest extends ApiTestCase
{
    public function test_the_default_limit_is_sixty_requests_per_minute(): void
    {
        $this->actingAsApi($this->makeUser());

        $this->getJson('/api/v1/products')->assertOk()->assertHeader('X-RateLimit-Limit', '60');
    }

    public function test_requests_over_the_limit_get_429_in_the_standard_envelope(): void
    {
        config(['app.api_rate_limit_per_minute' => 2]);
        $this->actingAsApi($this->makeUser());

        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products')->assertOk();
        $response = $this->getJson('/api/v1/products');

        $this->assertEnvelope($response, 429, success: false);
        $response->assertHeader('Retry-After')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_each_user_has_a_separate_allowance(): void
    {
        config(['app.api_rate_limit_per_minute' => 1]);

        $this->actingAsApi($this->makeUser());
        $this->getJson('/api/v1/products')->assertOk();
        $this->getJson('/api/v1/products')->assertStatus(429);

        $this->actingAsApi($this->makeUser());
        $this->getJson('/api/v1/products')->assertOk();
    }

    public function test_guests_are_limited_by_ip_address(): void
    {
        config(['app.api_rate_limit_per_minute' => 1]);
        $payload = ['email' => 'nobody@example.test', 'password' => 'wrong-password'];

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/v1/auth/login', ['email' => 'other@example.test', 'password' => 'wrong-password'])
            ->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/v1/auth/login', $payload)->assertStatus(401);
    }

    public function test_the_stricter_login_limiter_still_applies_first(): void
    {
        $user = $this->makeUser();
        $wrong = ['email' => $user->email, 'password' => 'wrong-password'];

        $this->postJson('/api/v1/auth/login', $wrong)->assertStatus(401);
        $this->postJson('/api/v1/auth/login', $wrong)->assertStatus(401);

        $this->postJson('/api/v1/auth/login', $wrong)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too many login attempts. Please try again later.');
    }

    public function test_a_valid_login_works_under_the_general_limit(): void
    {
        $user = $this->makeUser();

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password123'])
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '60');
    }
}
