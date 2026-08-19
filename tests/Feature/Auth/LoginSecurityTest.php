<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_account_is_indistinguishable_from_invalid_credentials_and_never_authenticated(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);

        $inactiveResponse = $this->post('/login', [
            'email' => $inactive->email,
            'password' => 'password',
            'remember' => '1',
        ]);
        $inactiveMessage = $inactiveResponse->getSession()->get('errors')->first('email');

        $invalidResponse = $this->post('/login', [
            'email' => 'unknown@example.test',
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertSame(trans('auth.failed'), $inactiveMessage);
        $this->assertSame($inactiveMessage, $invalidResponse->getSession()->get('errors')->first('email'));
        $this->assertGuest();
        $inactiveResponse->assertCookieMissing(auth()->guard('web')->getRecallerName());
    }

    public function test_email_is_normalized_before_authentication(): void
    {
        $user = User::factory()->create(['email' => 'normalized@example.test']);

        $this->post('/login', [
            'email' => '  NORMALIZED@EXAMPLE.TEST ',
            'password' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_combination_limiter_blocks_the_sixth_attempt_and_dispatches_lockout(): void
    {
        Event::fake([Lockout::class]);
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $response = $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);

        $response->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('Please wait a moment')
            ->assertSee('Back to Sign In');
        Event::assertDispatchedTimes(Lockout::class, 1);
    }

    public function test_email_limiter_aggregates_failures_across_ip_addresses(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.10.0.'.$attempt])
                ->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $response = $this->withServerVariables(['REMOTE_ADDR' => '10.10.1.1'])
            ->post('/login', ['email' => $user->email, 'password' => 'incorrect']);

        $response->assertTooManyRequests()
            ->assertSee('Back to Sign In');
    }

    public function test_ip_limiter_aggregates_failures_across_email_addresses(): void
    {
        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->post('/login', [
                'email' => "missing{$attempt}@example.test",
                'password' => 'incorrect',
            ]);
        }

        $response = $this->post('/login', [
            'email' => 'another@example.test',
            'password' => 'incorrect',
        ]);

        $response->assertTooManyRequests()
            ->assertSee('Back to Sign In');
    }

    public function test_json_login_lockout_remains_an_http_429_response(): void
    {
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->postJson('/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertJsonPath('message', 'Too Many Attempts.');
    }

    public function test_turnstile_is_required_after_threshold_and_valid_token_allows_login(): void
    {
        config()->set('auth_security.turnstile.site_key', 'site-key');
        config()->set('auth_security.turnstile.secret_key', 'secret-key');
        Http::fake(['*' => Http::response(['success' => true])]);
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $missingToken = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $missingToken->assertSessionHasErrors('email');
        Http::assertNothingSent();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'cf-turnstile-response' => 'valid-test-token',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        Http::assertSentCount(1);
    }

    public function test_invalid_turnstile_token_is_rejected_and_audited(): void
    {
        config()->set('auth_security.turnstile.site_key', 'site-key');
        config()->set('auth_security.turnstile.secret_key', 'secret-key');
        Http::fake(['*' => Http::response(['success' => false])]);
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'cf-turnstile-response' => 'invalid-test-token',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'email_attempted' => $user->email,
            'event' => 'captcha_failed',
        ]);
    }

    public function test_turnstile_provider_server_error_fails_open_but_is_audited(): void
    {
        config()->set('auth_security.turnstile.site_key', 'site-key');
        config()->set('auth_security.turnstile.secret_key', 'secret-key');
        Http::fake(['*' => Http::response([], 503)]);
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'cf-turnstile-response' => 'provider-timeout-token',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_audit_logs', [
            'email_attempted' => $user->email,
            'event' => 'captcha_provider_error',
        ]);
    }

    public function test_turnstile_connection_error_fails_open_but_is_audited(): void
    {
        config()->set('auth_security.turnstile.site_key', 'site-key');
        config()->set('auth_security.turnstile.secret_key', 'secret-key');
        Http::fake(Http::failedConnection('provider timeout'));
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'cf-turnstile-response' => 'provider-timeout-token',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_audit_logs', [
            'email_attempted' => $user->email,
            'event' => 'captcha_provider_error',
        ]);
    }

    public function test_empty_turnstile_configuration_does_not_break_login(): void
    {
        config()->set('auth_security.turnstile.site_key', null);
        config()->set('auth_security.turnstile.secret_key', null);
        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'incorrect']);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_forgot_password_response_does_not_disclose_account_existence(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->post('/forgot-password', ['email' => 'unknown@example.test']);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
        $known->assertSessionHasNoErrors();
        $unknown->assertSessionHasNoErrors();
    }
}
