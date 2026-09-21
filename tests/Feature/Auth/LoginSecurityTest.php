<?php

namespace Tests\Feature\Auth;

use App\Models\AuthAuditLog;
use App\Models\User;
use App\Services\Auth\LoginRateLimiter;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_inactive_and_wrong_password_failures_are_indistinguishable(): void
    {
        $inactive = User::factory()->create(['is_active' => false]);
        $active = User::factory()->create();

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
        $invalidMessage = $invalidResponse->getSession()->get('errors')->first('email');

        $wrongPasswordResponse = $this->post('/login', [
            'email' => $active->email,
            'password' => 'incorrect',
            'remember' => '1',
        ]);
        $wrongPasswordMessage = $wrongPasswordResponse->getSession()->get('errors')->first('email');

        $this->assertSame(trans('auth.failed'), $inactiveMessage);
        $this->assertSame($inactiveMessage, $invalidMessage);
        $this->assertSame($inactiveMessage, $wrongPasswordMessage);
        $this->assertGuest();
        $inactiveResponse->assertCookieMissing(auth()->guard('web')->getRecallerName());
        $invalidResponse->assertCookieMissing(auth()->guard('web')->getRecallerName());
        $wrongPasswordResponse->assertCookieMissing(auth()->guard('web')->getRecallerName());
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

        for ($attempt = 1; $attempt <= 5; $attempt++) {
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

    public function test_distinct_email_threshold_forces_turnstile_independent_of_attempt_counts(): void
    {
        // Isolated at the LoginRateLimiter level (rather than the full HTTP
        // login flow) so this specifically exercises the new distinct-email
        // tracker, without the pre-existing per-identity attempt counters
        // (which also increase alongside it in a "one try per email"
        // pattern) muddying which mechanism actually triggered Turnstile.
        config()->set('auth_security.login.distinct_email.threshold', 3);
        $limiter = app(LoginRateLimiter::class);
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.9']);

        $this->assertFalse($limiter->requiresTurnstile($request, 'first@example.test'));

        $limiter->hit($request, 'first@example.test');
        $limiter->hit($request, 'second@example.test');
        $this->assertFalse($limiter->requiresTurnstile($request, 'third@example.test'));

        $limiter->hit($request, 'third@example.test');
        $this->assertTrue($limiter->requiresTurnstile($request, 'fourth@example.test'));
    }

    public function test_distributed_failures_across_emails_and_ips_activate_the_global_brake(): void
    {
        config()->set('auth_security.login.global.attempts', 3);
        $limiter = app(LoginRateLimiter::class);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$attempt])
                ->post('/login', [
                    'email' => "distributed{$attempt}@example.test",
                    'password' => 'incorrect',
                ])
                ->assertSessionHasErrors('email');
        }

        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.100']);

        $this->assertSame(
            ['combination' => 0, 'email' => 0, 'ip' => 0, 'subnet' => 0],
            $limiter->attempts($request, 'next@example.test'),
        );
        $this->assertTrue($limiter->requiresTurnstile($request, 'next@example.test'));
    }

    public function test_successful_login_does_not_increment_the_global_failure_counter(): void
    {
        config()->set('auth_security.login.global.attempts', 1);
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.101']);
        $this->assertFalse(app(LoginRateLimiter::class)->requiresTurnstile($request, 'next@example.test'));
    }

    public function test_successful_users_behind_one_ip_do_not_trigger_distinct_failed_email_defense(): void
    {
        config()->set('auth_security.login.distinct_email.threshold', 3);
        Notification::fake();

        foreach (User::factory()->count(3)->create() as $user) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.200'])
                ->post('/login', ['email' => $user->email, 'password' => 'password'])
                ->assertRedirect(route('dashboard', absolute: false));

            $this->post('/logout')->assertRedirect(route('login'));
        }

        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '198.51.100.200']);
        $this->assertFalse(app(LoginRateLimiter::class)->requiresTurnstile($request, 'next@example.test'));
    }

    public function test_global_anomaly_audit_is_written_once_per_window(): void
    {
        config()->set('auth_security.login.global.attempts', 3);
        $limiter = app(LoginRateLimiter::class);
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.102']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $limiter->hit($request, "audit{$attempt}@example.test");
        }

        $this->assertDatabaseCount('auth_audit_logs', 1);
        $audit = AuthAuditLog::query()->where('event', 'global_login_anomaly_detected')->sole();
        $this->assertSame(['count' => 3], $audit->metadata);
    }

    public function test_constant_time_dummy_hash_is_computed_for_nonexistent_and_inactive_users(): void
    {
        // Pinned to the production-cost (12) dummy rather than the cost-4 one
        // phpunit.xml injects: the elapsed floor below is what proves bcrypt
        // actually ran on the miss path instead of the request returning
        // early, which is the whole timing-enumeration defence.
        config()->set('auth_security.dummy_hash', '$2y$12$e8p2xPjG.oQZkGgJ7K7Ie.4gPZ9Z7V1X2Y3Z4A5B6C7D8E9F0G1H2');
        $inactive = User::factory()->create(['is_active' => false]);

        $startedAt = microtime(true);
        $unknown = $this->post('/login', [
            'email' => 'nonexistent_account@adasi.co.id',
            'password' => 'arbitrary_password',
        ]);
        $unknownElapsedMs = (microtime(true) - $startedAt) * 1000;

        $startedAt = microtime(true);
        $inactiveResponse = $this->post('/login', [
            'email' => $inactive->email,
            'password' => 'password',
        ]);
        $inactiveElapsedMs = (microtime(true) - $startedAt) * 1000;

        $unknown->assertSessionHasErrors('email');
        $inactiveResponse->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->assertGreaterThan(100, $unknownElapsedMs, 'Unknown account short-circuited without a dummy hash comparison.');
        $this->assertGreaterThan(100, $inactiveElapsedMs, 'Inactive account short-circuited without a dummy hash comparison.');
    }

    public function test_zero_width_email_variants_share_a_single_rate_limit_bucket(): void
    {
        $user = User::factory()->create(['email' => 'homograph@example.test']);

        // Each variant renders as the same address but would hash to its own
        // limiter key without the zero-width strip and NFC pass, handing the
        // attacker a fresh attempt budget per variant.
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/login', [
                'email' => 'homograph'.str_repeat("\u{200B}", $attempt).'@example.test',
                'password' => 'incorrect',
            ])->assertSessionHasErrors('email');
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'incorrect'])
            ->assertTooManyRequests();
    }

    public function test_subnet_limiter_blocks_excessive_failures_within_same_network(): void
    {
        // Each attempt uses a distinct IP and a distinct email, so neither the
        // per-IP nor the per-account limiter can account for the block — only
        // the /24 aggregate can.
        config()->set('auth_security.login.subnet.attempts', 5);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->post('/login', [
                    'email' => "user{$attempt}@adasi.co.id",
                    'password' => 'wrong',
                ])->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->post('/login', [
                'email' => 'fresh@adasi.co.id',
                'password' => 'wrong',
            ])
            ->assertTooManyRequests();
    }

    public function test_subnet_limiter_does_not_block_a_different_network(): void
    {
        config()->set('auth_security.login.subnet.attempts', 5);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.'.$attempt])
                ->post('/login', [
                    'email' => "user{$attempt}@adasi.co.id",
                    'password' => 'wrong',
                ])->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->post('/login', ['email' => 'other@adasi.co.id', 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
    }

    public function test_tarpit_delay_is_applied_on_repeated_failures(): void
    {
        // Enabled explicitly: phpunit.xml turns the tarpit off so the rest of
        // the suite does not pay real seconds of sleep.
        config()->set('auth_security.login.tarpit.enabled', true);
        config()->set('auth_security.login.tarpit.threshold', 3);
        config()->set('auth_security.login.tarpit.delay_step_ms', 50);
        config()->set('auth_security.login.tarpit.max_delay_ms', 100);

        $user = User::factory()->create();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        }

        $startedAt = microtime(true);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertGreaterThanOrEqual(40, $elapsedMs);
    }

    public function test_tarpit_delay_is_not_applied_below_the_threshold(): void
    {
        config()->set('auth_security.login.tarpit.enabled', true);
        config()->set('auth_security.login.tarpit.threshold', 3);
        config()->set('auth_security.login.tarpit.delay_step_ms', 2000);
        config()->set('auth_security.login.tarpit.max_delay_ms', 2000);

        $user = User::factory()->create();

        $startedAt = microtime(true);
        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email');
        $elapsedMs = (microtime(true) - $startedAt) * 1000;

        $this->assertLessThan(2000, $elapsedMs, 'First failure must not be tarpitted.');
    }
}
