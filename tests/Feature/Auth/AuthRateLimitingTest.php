<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AuthRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_link_is_limited_per_email_without_blocking_another_email(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post(route('password.email'), ['email' => 'first@example.test'])
                ->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'first@example.test'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Please wait a moment')
            ->assertSee('Back to Forgot Password');

        $this->post(route('password.email'), ['email' => 'second@example.test'])
            ->assertRedirect();
    }

    public function test_password_reset_link_has_an_ip_backstop(): void
    {
        Notification::fake();

        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->post(route('password.email'), ['email' => "request{$attempt}@example.test"])
                ->assertRedirect();
        }

        $this->post(route('password.email'), ['email' => 'request11@example.test'])
            ->assertTooManyRequests()
            ->assertSee('Back to Forgot Password');
    }

    public function test_password_reset_submission_is_limited_by_email_and_ip(): void
    {
        $payload = [
            'token' => 'invalid-token',
            'email' => 'reset@example.test',
            'password' => 'Str0ng!Passphrase',
            'password_confirmation' => 'Str0ng!Passphrase',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('password.store'), $payload)->assertRedirect();
        }

        $this->post(route('password.store'), $payload)
            ->assertRedirect(route('password.request'))
            ->assertHeader('Retry-After')
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Too many requests'));
    }

    public function test_email_verification_resend_is_limited_per_authenticated_user(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($user)
                ->post(route('verification.send'))
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('verification.send'))
            ->assertRedirect(route('profile.edit'))
            ->assertHeader('Retry-After')
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Too many requests'));
    }

    public function test_credential_checks_share_a_per_user_limit(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($user)
                ->post(route('password.confirm'), ['password' => 'incorrect'])
                ->assertSessionHasErrors('password');
        }

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'incorrect',
                'password' => 'Str0ng!Passphrase',
                'password_confirmation' => 'Str0ng!Passphrase',
            ])
            ->assertRedirect(route('profile.edit'))
            ->assertHeader('Retry-After')
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Too many requests'));
    }

    public function test_mfa_code_challenge_is_limited_by_pending_user(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withSession([
                TwoFactorService::PENDING_LOGIN_KEY => [
                    'user_id' => $user->id,
                    'remember' => false,
                    'intended' => route('dashboard', absolute: false),
                    'started_at' => now()->timestamp,
                ],
            ])->post(route('two-factor.challenge'), ['code' => 'INVALID-CODE'])
                ->assertSessionHasErrors('code');
        }

        $this->withSession([
            TwoFactorService::PENDING_LOGIN_KEY => [
                'user_id' => $user->id,
                'remember' => false,
                'intended' => route('dashboard', absolute: false),
                'started_at' => now()->timestamp,
            ],
        ])->post(route('two-factor.challenge'), ['code' => 'INVALID-CODE'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee('Back to Verification');
    }

    public function test_mfa_security_actions_are_limited_after_password_confirmation(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($user)
                ->withSession(['auth.password_confirmed_at' => time()])
                ->post(route('profile.two-factor.start'))
                ->assertRedirect(route('profile.two-factor.setup'));
        }

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('profile.two-factor.start'))
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee('Back to Profile');
    }

    public function test_mfa_setup_confirmation_uses_the_branded_rate_limit_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('profile.two-factor.start'))
            ->assertRedirect(route('profile.two-factor.setup'));

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('profile.two-factor.confirm'), ['code' => 'invalid'])
                ->assertSessionHasErrors('code');
        }

        $this->post(route('profile.two-factor.confirm'), ['code' => 'invalid'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertSee('Back to MFA Setup');
    }

    public function test_regular_html_throttles_redirect_to_a_safe_previous_page_with_a_warning(): void
    {
        Route::post('/__test-rate-limit-local', static fn () => response()->noContent())
            ->middleware(['web', 'throttle:1,1,__test-rate-limit-local']);
        Route::post('/__test-rate-limit-external', static fn () => response()->noContent())
            ->middleware(['web', 'throttle:1,1,__test-rate-limit-external']);

        $localReferer = route('password.request');
        $this->withHeader('Referer', $localReferer)
            ->post('/__test-rate-limit-local')
            ->assertNoContent();
        $this->withHeader('Referer', $localReferer)
            ->post('/__test-rate-limit-local')
            ->assertRedirect($localReferer)
            ->assertHeader('Retry-After')
            ->assertSessionHas('warning', fn (string $message): bool => str_contains($message, 'Too many requests'));

        $this->withHeader('Referer', 'https://evil.example.test/redirect')
            ->post('/__test-rate-limit-external')
            ->assertNoContent();
        $this->withHeader('Referer', 'https://evil.example.test/redirect')
            ->post('/__test-rate-limit-external')
            ->assertRedirect(route('login'));
    }

    public function test_json_rate_limit_responses_remain_http_429_with_retry_headers(): void
    {
        $payload = [
            'token' => 'invalid-token',
            'email' => 'json-reset@example.test',
            'password' => 'Str0ng!Passphrase',
            'password_confirmation' => 'Str0ng!Passphrase',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post(route('password.store'), $payload)->assertRedirect();
        }

        $this->postJson(route('password.store'), $payload)
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');
    }

    public function test_all_security_routes_use_the_expected_named_limiter(): void
    {
        $expectedMiddleware = [
            'password.email' => 'throttle:auth.password-reset-link',
            'password.store' => 'throttle:auth.password-reset',
            'two-factor.challenge.store' => 'throttle:auth.mfa-code',
            'verification.verify' => 'throttle:auth.credentials',
            'verification.send' => 'throttle:auth.email-security',
            'password.update' => 'throttle:auth.credentials',
            'profile.destroy' => 'throttle:auth.credentials',
            'profile.logout-other-devices' => 'throttle:auth.credentials',
            'profile.two-factor.start' => 'throttle:auth.security-action',
            'profile.two-factor.confirm' => 'throttle:auth.mfa-code',
            'profile.two-factor.recovery-codes' => 'throttle:auth.security-action',
            'profile.two-factor.destroy' => 'throttle:auth.mfa-code',
            'admin.users.two-factor.destroy' => 'throttle:auth.security-action',
        ];

        foreach ($expectedMiddleware as $routeName => $middleware) {
            $route = app('router')->getRoutes()->getByName($routeName);

            $this->assertNotNull($route, "Route [{$routeName}] is missing.");
            $this->assertContains($middleware, $route->gatherMiddleware(), "Route [{$routeName}] is not rate limited.");
        }
    }
}
