<?php

namespace Tests\Feature\Auth;

use App\Models\AuthRememberToken;
use App\Models\User;
use App\Services\Auth\KnownDeviceService;
use App\Services\Auth\SessionReconnectService;
use App\Services\Auth\TwoFactorService;
use App\Support\Auth\SessionLifecycle;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Verifies the ERP session lifecycle endpoints (`session.status` and `session.heartbeat`),
 * read-only passive polling via `StartPortalSession`, and strict Remember-Me refusal on
 * passive polls and JSON requests.
 */
class SessionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_live_session_returns_its_current_token_and_timings_without_caching(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('session.status'));

        $response->assertOk()
            ->assertJson([
                'authenticated' => true,
                'user_id' => $user->id,
            ])
            ->assertJsonPath('csrf_token', session()->token())
            ->assertJsonStructure([
                'authenticated',
                'user_id',
                'csrf_token',
                'idle_timeout_seconds',
                'idle_remaining_seconds',
                'absolute_timeout_seconds',
                'absolute_remaining_seconds',
                'heartbeat_interval_seconds',
            ]);
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_guest_gets_json_401_instead_of_a_redirect(): void
    {
        $this->getJson(route('session.status'))
            ->assertUnauthorized()
            ->assertExactJson([
                'authenticated' => false,
                'reason' => 'session_ended',
            ])
            ->assertHeaderMissing('Location');
    }

    public function test_a_remember_cookie_does_not_resurrect_session_on_session_status_or_passive_polls(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'remember_token' => 'plain-remember-token']);

        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'plain-remember-token'))
            ->getJson(route('session.status', ['passive' => 1]))
            ->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reason' => 'idle_timeout',
            ])
            ->assertJsonMissingPath('csrf_token');

        $this->assertGuest();
        $this->assertNotSame('plain-remember-token', $user->fresh()->remember_token);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'session_timeout',
        ]);
        $this->assertDatabaseMissing('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'login_success',
        ]);

        // Passive badge poll must also refuse silent Remember-Me resurrection.
        $user->forceFill(['remember_token' => 'second-remember-token'])->saveQuietly();
        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'second-remember-token'))
            ->getJson(route('notifications.unread-count'))
            ->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reason' => 'idle_timeout',
            ]);
        $this->assertGuest();

        // Top-level HTML browser navigation with a valid unrevoked Remember-Me cookie still works.
        $user->forceFill(['remember_token' => 'html-remember-token'])->saveQuietly();
        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'html-remember-token'))
            ->get('/dashboard')
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_revoked_or_deactivated_session_is_not_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('session.status'))->assertOk();

        $user->forceFill(['auth_session_version' => $user->auth_session_version + 1])->saveQuietly();
        $this->getJson(route('session.status'))
            ->assertUnauthorized()
            ->assertJson(['authenticated' => false, 'reason' => 'session_revoked'])
            ->assertJsonMissingPath('csrf_token');
        $this->assertGuest();

        $inactive = User::factory()->create(['is_active' => false]);
        $this->actingAs($inactive)->getJson(route('session.status'))
            ->assertUnauthorized()
            ->assertJson(['authenticated' => false, 'reason' => 'account_deactivated'])
            ->assertJsonMissingPath('csrf_token');
        $this->assertGuest();
    }

    public function test_a_background_poll_refuses_remembered_mfa_while_html_pending_challenge_preserves_safe_return(): void
    {
        [$user, $secret] = $this->mfaUser();
        $user->forceFill(['remember_token' => 'mfa-poll-remember-token'])->saveQuietly();

        // Passive badge poll must never silently stage MFA or keep a session alive via Remember-Me.
        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'mfa-poll-remember-token'))
            ->getJson(route('notifications.unread-count'))
            ->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reason' => 'idle_timeout',
            ]);
        $this->assertGuest();
        $this->assertNotSame('mfa-poll-remember-token', $user->fresh()->remember_token);
        $this->assertNull(session(TwoFactorService::PENDING_LOGIN_KEY));

        // Top-level HTML visit with a valid Remember-Me cookie still stages the MFA code challenge.
        $user->forceFill(['remember_token' => 'mfa-html-remember-token'])->saveQuietly();
        $page = route('profile.edit', absolute: false);
        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'mfa-html-remember-token'))
            ->get($page)
            ->assertRedirect(route('two-factor.challenge'));
        $this->assertGuest();

        // Passive status checks do not mutate the pending challenge's intended URL.
        $this->getJson(route('session.status', ['passive' => 1, 'return' => '/dashboard']))
            ->assertUnauthorized()
            ->assertExactJson(['authenticated' => false, 'reason' => 'session_ended']);
        $this->assertSame($page, session(TwoFactorService::PENDING_LOGIN_KEY)['intended']);

        // Active reconnect check returns the same-origin challenge URL and updates safe return.
        $this->getJson(route('session.status', ['return' => url($page)]))
            ->assertUnauthorized()
            ->assertExactJson(['authenticated' => false, 'two_factor_url' => route('two-factor.challenge')]);
        $this->assertSame($page, session(TwoFactorService::PENDING_LOGIN_KEY)['intended']);

        $this->get(route('two-factor.challenge'))->assertOk();
        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])->assertRedirect($page);
        $this->assertAuthenticatedAs($user);
    }

    public function test_the_return_address_cannot_leave_the_portal(): void
    {
        [$user] = $this->mfaUser();
        $this->withSession([
            TwoFactorService::PENDING_LOGIN_KEY => [
                'user_id' => $user->id,
                'remember' => true,
                'intended' => route('dashboard', absolute: false),
                'started_at' => now()->timestamp,
            ],
        ])->getJson(route('session.status', ['return' => 'https://evil.example.test/phish']))
            ->assertUnauthorized()
            ->assertExactJson(['authenticated' => false, 'two_factor_url' => route('two-factor.challenge')]);

        $this->assertSame(route('dashboard', absolute: false), session(TwoFactorService::PENDING_LOGIN_KEY)['intended']);
    }

    public function test_an_expired_pending_challenge_is_not_offered(): void
    {
        [$user] = $this->mfaUser();
        $this->withSession([TwoFactorService::PENDING_LOGIN_KEY => [
            'user_id' => $user->id,
            'remember' => true,
            'intended' => '/dashboard',
            'started_at' => now()->subSeconds((int) config('auth_security.two_factor.pending_lifetime_seconds', 600) + 1)->timestamp,
        ]])->getJson(route('session.status'))
            ->assertUnauthorized()
            ->assertExactJson(['authenticated' => false, 'reason' => 'session_ended']);
    }

    public function test_the_authenticated_shell_exposes_session_lifecycle_config_and_modal(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('statusUrl', false)
            ->assertSee('reconnectUrl', false)
            ->assertSee('heartbeatUrl', false)
            ->assertSee('idle_remaining_seconds', false)
            ->assertSee('data-session-expired-host', false)
            ->assertSee('data-session-modal-login', false);
    }

    public function test_one_click_server_side_reconnect_restores_idle_expired_session_in_place_without_reload_even_for_mfa_users(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);
        config()->set('auth_security.session.idle_timeout_minutes', 30);
        config()->set('auth_security.session.absolute_timeout_minutes', 480);
        Notification::fake();

        $this->freezeTime();
        [$user, $secret] = $this->mfaUser();
        $user->forceFill(['role' => 'purchasing'])->saveQuietly();

        // Complete full MFA login so known-device and reconnect grant cookies are issued.
        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $login = $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertRedirect();

        $sessionCookieName = config('session.cookie');
        $knownDeviceCookieName = (string) config('auth_security.known_device.cookie_name', 'adasi_known_device');
        $sessionId = $login->getCookie($sessionCookieName)?->getValue();
        $grantCookie = $login->getCookie(SessionReconnectService::COOKIE_NAME)?->getValue();
        $knownDeviceCookie = $login->getCookie($knownDeviceCookieName)?->getValue();

        $this->assertNotNull($sessionId);
        $this->assertNotNull($grantCookie);
        $this->assertNotNull($knownDeviceCookie);

        // Simulate sitting idle for 3 hours (180 minutes: past both 30m idle and 120m SESSION_LIFETIME,
        // while still inside the 480m / 8h AUTH_ABSOLUTE_TIMEOUT window).
        $this->travel(180)->minutes();
        DB::table('sessions')->where('id', $sessionId)->delete();

        Auth::forgetGuards();
        $statusResponse = $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie)
            ->withHeader(SessionLifecycle::PASSIVE_CHECK_HEADER, 'passive')
            ->getJson(route('session.status', ['passive' => 1]));

        $statusResponse->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reconnectable' => true,
                'reason' => 'idle_timeout',
            ]);

        // 1-click server-side in-place Reconnect (POST /session/reconnect) without page reload or password/OTP.
        Auth::forgetGuards();
        $reconnectResponse = $this->withCredentials()
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                SessionLifecycle::RECONNECT_HEADER => '1',
            ])
            ->postJson(route('session.reconnect'));

        $reconnectResponse->assertOk()
            ->assertJson([
                'ok' => true,
                'authenticated' => true,
                'user_id' => $user->id,
                'idle_timeout_seconds' => 30 * 60,
                'idle_remaining_seconds' => 30 * 60,
                // Original 8h (480m) absolute cap minus 180m elapsed = 300m remaining (never reset!).
                'absolute_remaining_seconds' => 300 * 60,
            ]);

        $freshToken = $reconnectResponse->json('csrf_token');
        $this->assertIsString($freshToken);
        $this->assertNotSame('', $freshToken);
        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $user->id,
            'event' => 'session_reconnected',
        ]);
    }

    public function test_server_side_reconnect_restores_sso_session_proof_so_badge_polls_succeed_on_first_click(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);
        config()->set('auth_security.session.idle_timeout_minutes', 1);
        config()->set('auth_security.session.absolute_timeout_minutes', 480);
        config()->set('sso.enabled', true);
        config()->set('sso.client_id', '01a15707-14f7-7198-8f93-9f05479b1f49');
        Notification::fake();

        $this->freezeTime();
        $user = User::factory()->create(['role' => 'purchasing']);

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $sessionCookieName = config('session.cookie');
        $knownDeviceCookieName = (string) config('auth_security.known_device.cookie_name', 'adasi_known_device');
        $sessionId = $login->getCookie($sessionCookieName)?->getValue();
        $grantCookie = $login->getCookie(SessionReconnectService::COOKIE_NAME)?->getValue();
        $knownDeviceCookie = $login->getCookie($knownDeviceCookieName)?->getValue();

        // Simulate sitting idle for 10 minutes with AUTH_IDLE_TIMEOUT=1 and SSO_LOGIN_ENABLED=true.
        $this->travel(10)->minutes();

        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie)
            ->withHeader(SessionLifecycle::PASSIVE_CHECK_HEADER, 'passive')
            ->getJson(route('session.status', ['passive' => 1]))
            ->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reconnectable' => true,
                'reason' => 'idle_timeout',
            ]);

        // 1st click on Reconnect must restore BOTH the web guard session AND sso.verified_at/sso.client_id.
        Auth::forgetGuards();
        $reconnect = $this->withCredentials()
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                SessionLifecycle::RECONNECT_HEADER => '1',
            ])
            ->postJson(route('session.reconnect'));

        $reconnect->assertOk()
            ->assertJson([
                'ok' => true,
                'authenticated' => true,
                'user_id' => $user->id,
            ]);

        $newSessionId = $reconnect->getCookie($sessionCookieName)?->getValue();
        $this->assertNotNull($newSessionId);

        // Immediate post-reconnect badge polls (`updateBadges()` on `adasi:session-restored`)
        // must pass `EnsureSsoSession` with 200 OK instead of triggering a second 401 modal.
        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $newSessionId)
            ->withHeader(SessionLifecycle::PASSIVE_CHECK_HEADER, 'passive')
            ->getJson(route('notifications.unread-count'))
            ->assertOk();

        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $newSessionId)
            ->withHeader(SessionLifecycle::PASSIVE_CHECK_HEADER, 'passive')
            ->getJson(route('conversations.unread-count'))
            ->assertOk();
    }

    public function test_server_side_reconnect_enforces_custom_header_absolute_cap_revocation_and_manual_reauth(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);
        config()->set('auth_security.session.idle_timeout_minutes', 30);
        config()->set('auth_security.session.absolute_timeout_minutes', 60);
        Notification::fake();

        $this->freezeTime();
        $user = User::factory()->create(['role' => 'admin']);

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $sessionCookieName = config('session.cookie');
        $knownDeviceCookieName = (string) config('auth_security.known_device.cookie_name', 'adasi_known_device');
        $sessionId = $login->getCookie($sessionCookieName)?->getValue();
        $grantCookie = $login->getCookie(SessionReconnectService::COOKIE_NAME)?->getValue();
        $knownDeviceCookie = $login->getCookie($knownDeviceCookieName)?->getValue();

        // 1. Missing X-Adasi-Reconnect header or cross-origin Origin is rejected with 403.
        $this->postJson(route('session.reconnect'))->assertForbidden();
        $this->withHeaders([
            'X-Requested-With' => 'XMLHttpRequest',
            SessionLifecycle::RECONNECT_HEADER => '1',
            'Origin' => 'https://evil.example.test',
        ])->postJson(route('session.reconnect'))->assertForbidden();
        $this->withoutHeader('Origin');

        // 2. Manual "Sign In Again" (?reauth=1) terminates session, revokes grant, and preserves url.intended.
        $reauthPage = route('profile.edit', ['reauth' => 1], absolute: false);
        $this->withCookie($sessionCookieName, $sessionId)
            ->get($reauthPage)
            ->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertSame(route('profile.edit', absolute: false), session('url.intended'));

        // Reconnect with the old grant cookie after manual reauth is refused because its session_id was revoked.
        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                SessionLifecycle::RECONNECT_HEADER => '1',
            ])
            ->postJson(route('session.reconnect'))
            ->assertUnauthorized()
            ->assertJson([
                'ok' => false,
                'authenticated' => false,
                'reconnectable' => false,
                'reason' => 'session_revoked',
            ]);

        // 3. Issue a fresh login and advance past the 60m AUTH_ABSOLUTE_TIMEOUT -> reconnect is refused.
        $login2 = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $grantCookie2 = $login2->getCookie(SessionReconnectService::COOKIE_NAME)?->getValue();
        $knownDeviceCookie2 = $login2->getCookie($knownDeviceCookieName)?->getValue();

        $this->travel(61)->minutes();
        Auth::forgetGuards();
        $this->flushSession();

        $this->withCredentials()
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie2)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie2)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                SessionLifecycle::RECONNECT_HEADER => '1',
            ])
            ->postJson(route('session.reconnect'))
            ->assertUnauthorized()
            ->assertJson([
                'ok' => false,
                'authenticated' => false,
                'reconnectable' => false,
                'reason' => 'absolute_timeout',
            ]);

        // 4. Trusted device removal invalidates its reconnect grant immediately.
        $this->travelBack();
        $login3 = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();
        $grantCookie3 = $login3->getCookie(SessionReconnectService::COOKIE_NAME)?->getValue();
        $knownDeviceCookie3 = $login3->getCookie($knownDeviceCookieName)?->getValue();
        $deviceHash3 = hash('sha256', (string) $knownDeviceCookie3);

        app(KnownDeviceService::class)->revoke($user, $deviceHash3);
        Auth::forgetGuards();
        $this->flushSession();

        $this->withCredentials()
            ->withCookie(SessionReconnectService::COOKIE_NAME, $grantCookie3)
            ->withCookie($knownDeviceCookieName, $knownDeviceCookie3)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                SessionLifecycle::RECONNECT_HEADER => '1',
            ])
            ->postJson(route('session.reconnect'))
            ->assertUnauthorized()
            ->assertJson([
                'ok' => false,
                'authenticated' => false,
                'reconnectable' => false,
                'reason' => 'session_revoked',
            ]);
    }

    public function test_passive_polls_do_not_slide_database_last_activity_and_idle_timeout_fires_on_schedule(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);
        config()->set('auth_security.session.idle_timeout_minutes', 30);
        config()->set('auth_security.session.absolute_timeout_minutes', 480);
        Notification::fake();

        $this->freezeTime();
        $user = User::factory()->create(['role' => 'purchasing']);

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect();

        $sessionCookieName = config('session.cookie');
        $sessionId = $login->getCookie($sessionCookieName)?->getValue();
        $this->assertNotNull($sessionId);

        $initialRow = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertNotNull($initialRow);
        $initialDbActivity = (int) $initialRow->last_activity;
        $initialSessionActivity = (int) session(SessionLifecycle::LAST_ACTIVITY_KEY);

        // Travel 15 minutes and trigger background polls + passive status checks
        // with the real session cookie and a fresh guard (like a browser worker request).
        $this->travel(15)->minutes();

        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->getJson(route('notifications.unread-count'))
            ->assertOk();

        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->getJson(route('conversations.unread-count'))
            ->assertOk();

        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->withHeader(SessionLifecycle::PASSIVE_CHECK_HEADER, 'passive')
            ->getJson(route('session.status', ['passive' => 1]))
            ->assertOk()
            ->assertJsonPath('authenticated', true)
            ->assertJsonPath('idle_remaining_seconds', 15 * 60);

        // Database last_activity and auth_last_activity_at must NOT have moved.
        $afterPollRow = DB::table('sessions')->where('id', $sessionId)->first();
        $this->assertSame($initialDbActivity, (int) $afterPollRow->last_activity);
        $this->assertSame($initialSessionActivity, (int) session(SessionLifecycle::LAST_ACTIVITY_KEY));

        // Travel another 15 minutes (30 minutes total idle -> reaches AUTH_IDLE_TIMEOUT).
        $this->travel(15)->minutes();

        Auth::forgetGuards();
        $expiredResponse = $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->withHeader(SessionLifecycle::PASSIVE_CHECK_HEADER, 'passive')
            ->getJson(route('session.status', ['passive' => 1]));

        $expiredResponse->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reason' => 'idle_timeout',
            ]);
        $this->assertGuest();

        $audit = DB::table('auth_audit_logs')
            ->where('user_id', $user->id)
            ->where('event', 'session_timeout')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('idle_timeout', json_decode((string) $audit->metadata, true)['reason'] ?? null);
    }

    public function test_idle_timeout_revokes_only_the_expired_sessions_per_device_remember_token(): void
    {
        config()->set('session.driver', 'database');
        config()->set('session.lifetime', 120);
        config()->set('auth_security.session.idle_timeout_minutes', 20);
        Notification::fake();

        $this->freezeTime();
        $user = User::factory()->create(['role' => 'admin']);

        $login = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ])->assertRedirect();

        $sessionCookieName = config('session.cookie');
        $sessionId = $login->getCookie($sessionCookieName)?->getValue();
        $this->assertNotNull($sessionId);

        // Another device's remember token must remain unaffected when this session times out.
        AuthRememberToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', 'other-device-plain-token'),
            'session_id' => 'other-device-session-id',
            'device_hash' => 'other-device-hash',
            'last_used_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);

        $this->assertDatabaseHas('auth_remember_tokens', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
        ]);

        $this->travel(20)->minutes();

        Auth::forgetGuards();
        $this->withCredentials()
            ->withCookie($sessionCookieName, $sessionId)
            ->getJson(route('session.status', ['passive' => 1]))
            ->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reason' => 'idle_timeout',
            ]);

        $this->assertDatabaseMissing('auth_remember_tokens', [
            'user_id' => $user->id,
            'session_id' => $sessionId,
        ]);
        $this->assertDatabaseHas('auth_remember_tokens', [
            'user_id' => $user->id,
            'session_id' => 'other-device-session-id',
        ]);
    }

    public function test_session_heartbeat_slides_idle_timeout_respects_absolute_cap_and_requires_csrf(): void
    {
        config()->set('session.lifetime', 120);
        config()->set('auth_security.session.idle_timeout_minutes', 30);
        config()->set('auth_security.session.absolute_timeout_minutes', 60);

        $this->freezeTime();
        $user = User::factory()->create(['role' => 'admin']);
        $start = now()->timestamp;

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => (int) $user->auth_session_version,
                SessionLifecycle::ABSOLUTE_STARTED_KEY => $start,
                SessionLifecycle::LAST_ACTIVITY_KEY => $start,
            ]);

        // Advance 20 minutes (still within 30m idle) -> heartbeat extends idle window.
        $this->travel(20)->minutes();
        $heartbeat = $this->postJson(route('session.heartbeat'));
        $heartbeat->assertOk()
            ->assertJson([
                'ok' => true,
                'idle_timeout_seconds' => 30 * 60,
                'idle_remaining_seconds' => 30 * 60,
                'absolute_remaining_seconds' => 40 * 60,
            ]);
        $this->assertStringContainsString('no-store', (string) $heartbeat->headers->get('Cache-Control'));
        $this->assertSame(now()->timestamp, session(SessionLifecycle::LAST_ACTIVITY_KEY));

        // Advance another 25 minutes (45m total, 25m since heartbeat -> still valid, capped by 15m absolute left).
        $this->travel(25)->minutes();
        $this->postJson(route('session.heartbeat'))
            ->assertOk()
            ->assertJson([
                'ok' => true,
                'idle_remaining_seconds' => 15 * 60,
                'absolute_remaining_seconds' => 15 * 60,
            ]);

        // Advance 15 minutes (60m total -> reaches absolute timeout despite recent heartbeat).
        $this->travel(15)->minutes();
        $this->postJson(route('session.heartbeat'))
            ->assertUnauthorized()
            ->assertJson([
                'authenticated' => false,
                'reason' => 'absolute_timeout',
            ]);
        $this->assertGuest();

        // CSRF enforcement on session.heartbeat.
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $freshUser = User::factory()->create(['role' => 'admin']);
        $this->actingAs($freshUser)
            ->withSession(['_token' => 'expected-csrf'])
            ->postJson(route('session.heartbeat'))
            ->assertStatus(419);

        $this->withHeader('X-CSRF-TOKEN', 'expected-csrf')
            ->postJson(route('session.heartbeat'))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public function test_non_polling_request_extends_idle_activity_window(): void
    {
        config()->set('auth_security.session.idle_timeout_minutes', 30);
        $this->freezeTime();
        $user = User::factory()->create(['role' => 'admin']);
        $start = now()->timestamp;

        $this->actingAs($user)
            ->withSession([
                'auth_session_version' => (int) $user->auth_session_version,
                SessionLifecycle::ABSOLUTE_STARTED_KEY => $start,
                SessionLifecycle::LAST_ACTIVITY_KEY => $start,
            ]);

        $this->travel(10)->minutes();
        $this->get(route('profile.edit'))->assertOk();
        $this->assertSame(now()->timestamp, session(SessionLifecycle::LAST_ACTIVITY_KEY));
    }

    #[DataProvider('portalRoleProvider')]
    public function test_every_portal_role_and_forced_password_change_account_can_send_heartbeat(string $role, bool $mustChangePassword): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'must_change_password' => $mustChangePassword,
        ]);

        $this->actingAs($user)
            ->postJson(route('session.heartbeat'))
            ->assertOk()
            ->assertJsonPath('ok', true);
    }

    public static function portalRoleProvider(): array
    {
        return [
            'admin' => ['admin', false],
            'purchasing' => ['purchasing', false],
            'supplier' => ['supplier', false],
            'qc' => ['qc', false],
            'finance' => ['finance', false],
            'ga' => ['ga', false],
            'forced password change' => ['purchasing', true],
        ];
    }

    /** @return array{0: string, 1: string} */
    private function recallerCookie(User $user, string $rememberToken): array
    {
        $guard = Auth::guard('web');

        return [
            $guard->getRecallerName(),
            $user->getAuthIdentifier().'|'.$rememberToken.'|'.$guard->hashPasswordForCookie($user->fresh()->getAuthPassword()),
        ];
    }

    private function asFreshDevice(): static
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        // JSON test requests only send cookies with credentials, like a same-origin fetch.
        return $this->withCredentials();
    }

    /** @return array{0: User, 1: string} */
    private function mfaUser(): array
    {
        $secret = app(TwoFactorService::class)->authenticator()->generateSecretKey(32);
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => null,
        ])->saveQuietly();

        return [$user, $secret];
    }
}
