<?php

namespace Tests\Feature\Auth;

use App\Models\AuthAuditLog;
use App\Models\AuthRememberToken;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\Auth\LoginRateLimiter;
use App\Services\Auth\TwoFactorService;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuthUpgradeHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_bcrypt_user_is_transparently_rehashed_to_argon2id_on_login(): void
    {
        Notification::fake();
        $bcryptHash = Hash::driver('bcrypt')->make('Str0ng!LegacyPass');
        $this->assertStringStartsWith('$2y$', $bcryptHash);

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['password' => $bcryptHash]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Str0ng!LegacyPass',
        ])->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $updatedHash = (string) $user->fresh()->password;
        $this->assertStringStartsWith('$argon2id$', $updatedHash);
        $this->assertTrue(Hash::check('Str0ng!LegacyPass', $updatedHash));
        $this->assertDatabaseCount('auth_password_histories', 0);

        $this->post('/logout');
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Str0ng!LegacyPass',
        ])->assertRedirect(route('dashboard', absolute: false));
        $this->assertAuthenticatedAs($user);
    }

    public function test_legacy_bcrypt_mfa_user_upgrades_to_argon2id_and_completes_challenge(): void
    {
        Notification::fake();
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->authenticator()->generateSecretKey(32);
        $bcryptHash = Hash::driver('bcrypt')->make('Str0ng!MfaLegacy');

        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update([
            'password' => $bcryptHash,
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'Str0ng!MfaLegacy',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertStringStartsWith('$argon2id$', (string) $user->fresh()->password);

        $code = $twoFactor->authenticator()->getCurrentOtp($secret);
        $this->post('/two-factor-challenge', ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_history_prevents_reusing_recent_passwords_but_allows_older_ones(): void
    {
        config()->set('auth_security.password.history_count', 3);
        $user = User::factory()->create(['password' => Hash::make('Initial!Pass001')]);

        $this->actingAs($user)
            ->from('/profile/security')
            ->put(route('password.update'), [
                'current_password' => 'Initial!Pass001',
                'password' => 'Second!Pass002',
                'password_confirmation' => 'Second!Pass002',
            ])->assertRedirect('/profile/security')->assertSessionHasNoErrors();

        $this->actingAs($user->fresh())
            ->from('/profile/security')
            ->put(route('password.update'), [
                'current_password' => 'Second!Pass002',
                'password' => 'Third!Pass003',
                'password_confirmation' => 'Third!Pass003',
            ])->assertRedirect('/profile/security')->assertSessionHasNoErrors();

        // History window is 3 (Third, Second, Initial): reusing Initial must fail.
        $this->actingAs($user->fresh())
            ->from('/profile/security')
            ->put(route('password.update'), [
                'current_password' => 'Third!Pass003',
                'password' => 'Initial!Pass001',
                'password_confirmation' => 'Initial!Pass001',
            ])->assertRedirect('/profile/security')
            ->assertSessionHasErrors('password', null, 'updatePassword');

        // Rotate once more to Fourth so Initial drops outside the 3-password window.
        $this->actingAs($user->fresh())
            ->from('/profile/security')
            ->put(route('password.update'), [
                'current_password' => 'Third!Pass003',
                'password' => 'Fourth!Pass004',
                'password_confirmation' => 'Fourth!Pass004',
            ])->assertRedirect('/profile/security')->assertSessionHasNoErrors();

        $this->actingAs($user->fresh())
            ->from('/profile/security')
            ->put(route('password.update'), [
                'current_password' => 'Fourth!Pass004',
                'password' => 'Initial!Pass001',
                'password_confirmation' => 'Initial!Pass001',
            ])->assertRedirect('/profile/security')->assertSessionHasNoErrors();
    }

    public function test_three_device_remember_tokens_are_isolated_on_single_session_revocation(): void
    {
        config()->set('session.driver', 'database');
        config()->set('auth_security.session.max_concurrent_sessions', 5);
        Notification::fake();

        $user = User::factory()->create();
        $recallerName = Auth::guard('web')->getRecallerName();

        $deviceA = $this->loginFreshDevice($user, '198.51.100.1', 'Mozilla/5.0 (Windows NT 10.0) Chrome/125.0');
        $cookieA = $deviceA['recaller'];
        $sessionA = $deviceA['session_id'];

        $deviceB = $this->loginFreshDevice($user, '198.51.100.2', 'Mozilla/5.0 (Macintosh) Firefox/126.0');
        $cookieB = $deviceB['recaller'];
        $sessionB = $deviceB['session_id'];

        $deviceC = $this->loginFreshDevice($user, '198.51.100.3', 'Mozilla/5.0 (iPhone) Safari/604.1');
        $cookieC = $deviceC['recaller'];

        $this->assertSame(3, AuthRememberToken::query()->where('user_id', $user->id)->count());

        // Device A revokes Device B's session.
        $this->asFreshDevice()
            ->withCookie(config('session.cookie'), $sessionA)
            ->delete(route('profile.sessions.revoke'), ['session_token' => Crypt::encryptString($sessionB)])
            ->assertRedirect();

        // Device B's recaller cookie is rejected.
        $this->asFreshDevice()
            ->withCookie($recallerName, $cookieB)
            ->get('/dashboard')
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // Device C (untouched) and Device A both still recall cleanly.
        $this->asFreshDevice()
            ->withCookie($recallerName, $cookieC)
            ->get(route('profile.edit'))
            ->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->asFreshDevice()
            ->withCookie($recallerName, $cookieA)
            ->get(route('profile.edit'))
            ->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_concurrent_eviction_revokes_only_evicted_devices_remember_token(): void
    {
        config()->set('session.driver', 'database');
        config()->set('auth_security.session.max_concurrent_sessions', 2);
        Notification::fake();

        $user = User::factory()->create();
        $recallerName = Auth::guard('web')->getRecallerName();

        $deviceA = $this->loginFreshDevice($user, '198.51.100.11', 'DeviceA');
        $this->travel(1)->minute();
        $deviceB = $this->loginFreshDevice($user, '198.51.100.12', 'DeviceB');
        $this->travel(1)->minute();
        $deviceC = $this->loginFreshDevice($user, '198.51.100.13', 'DeviceC');

        // Oldest session (Device A) evicted; B and C remain valid.
        $this->assertDatabaseMissing('sessions', ['id' => $deviceA['session_id']]);
        $this->assertDatabaseHas('sessions', ['id' => $deviceB['session_id']]);
        $this->assertDatabaseHas('sessions', ['id' => $deviceC['session_id']]);

        $this->asFreshDevice()->withCookie($recallerName, $deviceA['recaller'])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        $this->asFreshDevice()->withCookie($recallerName, $deviceB['recaller'])->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);

        $this->asFreshDevice()->withCookie($recallerName, $deviceC['recaller'])->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_known_devices_can_be_listed_and_revoked_with_immediate_session_termination(): void
    {
        config()->set('session.driver', 'database');
        Notification::fake();

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $recallerName = Auth::guard('web')->getRecallerName();
        $deviceCookieName = (string) config('auth_security.known_device.cookie_name');

        $deviceA = $this->loginFreshDevice($user, '198.51.100.21', 'Mozilla/5.0 (Windows NT 10.0) Chrome/125.0');
        $deviceB = $this->loginFreshDevice($user, '198.51.100.22', 'Mozilla/5.0 (Macintosh) Firefox/126.0');
        $otherDevice = $this->loginFreshDevice($otherUser, '198.51.100.23', 'Mozilla/5.0 (Linux) Chrome/125.0');

        $hashA = hash('sha256', $deviceA['known_device']);
        $hashB = hash('sha256', $deviceB['known_device']);
        $otherHash = hash('sha256', $otherDevice['known_device']);

        $securityPage = $this->asFreshDevice()
            ->withCookie(config('session.cookie'), $deviceA['session_id'])
            ->withCookie($deviceCookieName, $deviceA['known_device'])
            ->get(route('profile.security'))
            ->assertOk()
            ->assertSee('Chrome on Windows')
            ->assertSee('Firefox on macOS')
            ->assertDontSee($hashA, false)
            ->assertDontSee($hashB, false);

        // Cannot revoke current device.
        $this->delete(route('profile.known-devices.revoke'), ['device_token' => Crypt::encryptString($hashA)])
            ->assertRedirect()
            ->assertSessionHas('warning', __('security.cannot_revoke_current_device'));
        $this->assertDatabaseHas('auth_known_devices', ['user_id' => $user->id, 'device_hash' => $hashA]);

        // Cannot revoke another user's known device (IDOR guard).
        $this->delete(route('profile.known-devices.revoke'), ['device_token' => Crypt::encryptString($otherHash)])
            ->assertRedirect()
            ->assertSessionHas('status', 'device-not-found');
        $this->assertDatabaseHas('auth_known_devices', ['user_id' => $otherUser->id, 'device_hash' => $otherHash]);

        // Revoking Device B removes known device, remember token, and active session.
        $this->delete(route('profile.known-devices.revoke'), ['device_token' => Crypt::encryptString($hashB)])
            ->assertRedirect()
            ->assertSessionHas('status', 'device-revoked');

        $this->assertDatabaseMissing('auth_known_devices', ['user_id' => $user->id, 'device_hash' => $hashB]);
        $this->assertDatabaseMissing('sessions', ['id' => $deviceB['session_id']]);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'known_device_revoked']);

        $this->asFreshDevice()->withCookie($recallerName, $deviceB['recaller'])->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();

        // Device A remains authenticated.
        $this->asFreshDevice()->withCookie($recallerName, $deviceA['recaller'])->get(route('profile.edit'))->assertOk();
        $this->assertAuthenticatedAs($user);
    }

    public function test_confirm_password_failure_is_audited_and_route_is_named_for_forced_password_change(): void
    {
        $user = User::factory()->create(['must_change_password' => true]);

        $this->assertSame('/confirm-password', route('password.confirm.store', absolute: false));

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => 'Wrong!Pass123'])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'password_confirmation_failed',
        ]);
    }

    public function test_enforce_auth_session_security_blocks_non_active_account_status_even_if_is_active(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_REJECTED,
        ]);

        $this->actingAs($user)
            ->get(route('profile.edit'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'account_deactivated',
        ]);
    }

    public function test_mfa_challenge_enforces_ip_rate_limit_across_accounts(): void
    {
        config()->set('auth_security.rate_limits.mfa_code.ip', ['attempts' => 6, 'decay_seconds' => 300]);
        $secret = app(TwoFactorService::class)->authenticator()->generateSecretKey(32);

        $users = User::factory()->count(2)->create();
        foreach ($users as $user) {
            $user->forceFill([
                'two_factor_secret' => $secret,
                'two_factor_confirmed_at' => now(),
            ])->saveQuietly();
        }

        for ($i = 0; $i < 6; $i++) {
            $target = $users[$i % 2];
            $this->withSession([
                TwoFactorService::PENDING_LOGIN_KEY => [
                    'user_id' => $target->id,
                    'remember' => false,
                    'intended' => '/dashboard',
                    'started_at' => now()->timestamp,
                ],
            ])->post(route('two-factor.challenge.store'), ['code' => '000000'])
                ->assertSessionHasErrors('code');
        }

        $this->withSession([
            TwoFactorService::PENDING_LOGIN_KEY => [
                'user_id' => $users[0]->id,
                'remember' => false,
                'intended' => '/dashboard',
                'started_at' => now()->timestamp,
            ],
        ])->post(route('two-factor.challenge.store'), ['code' => '000000'])
            ->assertTooManyRequests();
    }

    public function test_repeated_lockouts_alert_admins_only_once_per_window(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        config()->set('auth_security.login.repeated_lockout_alert.threshold', 3);

        $request = Request::create('/login', 'POST', ['email' => 'victim@example.test'], [], [], ['REMOTE_ADDR' => '198.51.100.77']);

        for ($i = 0; $i < 5; $i++) {
            event(new Lockout($request));
        }

        $this->assertSame(1, AuthAuditLog::query()->where('event', 'repeated_lockouts_detected')->count());
        Notification::assertSentToTimes($admin, SystemNotification::class, 1);
    }

    public function test_distinct_failed_email_tracking_is_bounded_above_threshold(): void
    {
        config()->set('auth_security.login.distinct_email.threshold', 3);
        $limiter = app(LoginRateLimiter::class);
        $request = Request::create('/login', 'POST', [], [], [], ['REMOTE_ADDR' => '203.0.113.88']);

        for ($i = 1; $i <= 30; $i++) {
            $limiter->hit($request, "spray{$i}@example.test");
        }

        $this->assertTrue($limiter->requiresTurnstile($request, 'another@example.test'));
    }

    /**
     * @return array{session_id: string, recaller: string, known_device: string}
     */
    private function loginFreshDevice(User $user, string $ip, string $userAgent): array
    {
        $this->asFreshDevice();
        $response = $this->withServerVariables([
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
        ])->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $recallerName = Auth::guard('web')->getRecallerName();
        $sessionCookieName = (string) config('session.cookie');
        $knownDeviceName = (string) config('auth_security.known_device.cookie_name');

        return [
            'session_id' => (string) $response->getCookie($sessionCookieName)->getValue(),
            'recaller' => (string) $response->getCookie($recallerName)->getValue(),
            'known_device' => (string) $response->getCookie($knownDeviceName)->getValue(),
        ];
    }

    private function asFreshDevice(): static
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $this;
    }
}
