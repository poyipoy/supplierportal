<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_auth_response_has_security_headers_and_no_store(): void
    {
        $response = $this->get('/login');

        $response->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $this->assertStringContainsString("default-src 'self'", $response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_hsts_is_only_added_for_production_https(): void
    {
        config()->set('app.env', 'production');

        $this->get('http://adasi-portal.test/login')->assertHeaderMissing('Strict-Transport-Security');
        $this->get('https://adasi-portal.test/login')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_mfa_setup_and_recovery_pages_are_not_cacheable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('profile.two-factor.start'));

        $response = $this->get(route('profile.two-factor.setup'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_json_response_is_not_given_html_only_headers(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->getJson(route('notifications.unread-count'))
            ->assertOk()
            ->assertHeaderMissing('X-Frame-Options')
            ->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }
}
