<?php

namespace Tests\Feature\Security;

use Tests\Feature\Requirement1\ApiTestCase;

class SecurityHeadersTest extends ApiTestCase
{
    private function assertBaselineHeaders($response): void
    {
        $response->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
    }

    public function test_json_api_responses_get_a_strict_policy(): void
    {
        $this->actingAsApi($this->makeUser());

        $response = $this->getJson('/api/v1/products')->assertOk();

        $this->assertBaselineHeaders($response);
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_error_responses_also_get_the_headers(): void
    {
        $response = $this->asGuest()->getJson('/api/v1/products')->assertStatus(401);

        $this->assertBaselineHeaders($response);
    }

    public function test_the_web_interface_keeps_its_inline_scripts_working(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertBaselineHeaders($response);
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
    }

    public function test_swagger_ui_stays_available_with_a_policy_it_can_run_under(): void
    {
        $response = $this->get('/api/documentation')->assertOk();

        $this->assertBaselineHeaders($response);
        $this->assertStringContainsString("script-src 'self' 'unsafe-inline'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }
}
