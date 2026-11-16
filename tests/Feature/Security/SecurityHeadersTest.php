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

    public function test_the_web_interface_gets_a_strict_policy_without_unsafe_inline(): void
    {
        $response = $this->get('/')->assertOk();

        $this->assertBaselineHeaders($response);
        $policy = $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self';", $policy);
        $this->assertStringContainsString("style-src 'self';", $policy);
        $this->assertStringNotContainsString('unsafe-inline', $policy);
        $this->assertStringContainsString("connect-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("frame-ancestors 'none'", $policy);
    }

    public function test_the_web_interface_has_no_inline_script_style_or_handler(): void
    {
        $html = file_get_contents(public_path('app.html'));

        $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $html, 'inline <script>');
        $this->assertDoesNotMatchRegularExpression('/<style\b/i', $html, 'inline <style>');
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'inline event handler');
        $this->assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'style attribute');

        foreach (glob(public_path('assets/js/{,views/,views/admin/}*.js'), GLOB_BRACE) as $file) {
            $js = file_get_contents($file);
            $this->assertStringNotContainsString('innerHTML', $js, basename($file).' must build the DOM with DOM APIs');
            $this->assertStringNotContainsString('insertAdjacentHTML', $js, basename($file));
            $this->assertDoesNotMatchRegularExpression("/setAttribute\\(\\s*['\"](on[a-z]+|style)['\"]/", $js, basename($file));
        }
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
