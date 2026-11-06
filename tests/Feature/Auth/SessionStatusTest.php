<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * The session-ended dialog's "Reconnect" asks session.status first. It may restore the page
 * only from what the browser already holds (live session or remember-me cookie), never by
 * itself, and never past revocation, deactivation or the MFA code.
 */
class SessionStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_live_session_returns_its_current_token_without_caching(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson(route('session.status'));

        $response->assertOk()
            ->assertJson(['authenticated' => true, 'user_id' => $user->id])
            ->assertJsonPath('csrf_token', session()->token());
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $response->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_guest_gets_json_401_instead_of_a_redirect(): void
    {
        $this->getJson(route('session.status'))
            ->assertUnauthorized()
            ->assertExactJson(['authenticated' => false])
            ->assertHeaderMissing('Location');
    }

    public function test_a_valid_remember_cookie_restores_the_session_without_a_password(): void
    {
        $user = User::factory()->create(['remember_token' => 'plain-remember-token']);

        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'plain-remember-token'))
            ->getJson(route('session.status'))
            ->assertOk()
            ->assertJson(['authenticated' => true, 'user_id' => $user->id]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_revoked_or_deactivated_session_is_not_restored(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->getJson(route('session.status'))->assertOk();

        $user->forceFill(['auth_session_version' => $user->auth_session_version + 1])->saveQuietly();
        $this->getJson(route('session.status'))->assertUnauthorized()->assertJsonMissingPath('csrf_token');
        $this->assertGuest();

        $inactive = User::factory()->create(['is_active' => false]);
        $this->actingAs($inactive)->getJson(route('session.status'))
            ->assertUnauthorized()->assertJsonMissingPath('csrf_token');
        $this->assertGuest();
    }

    public function test_a_background_poll_keeps_a_remembered_mfa_user_on_the_code_challenge_back_to_the_page(): void
    {
        [$user, $secret] = $this->mfaUser();
        $user->forceFill(['remember_token' => 'mfa-remember-token'])->saveQuietly();
        $page = route('profile.edit', absolute: false);

        // The badge poll is usually the first request after the session expired.
        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'mfa-remember-token'))
            ->getJson(route('notifications.unread-count'))
            ->assertUnauthorized();
        $this->assertGuest();
        $this->assertNotSame('mfa-remember-token', $user->fresh()->remember_token, 'the cookie is rotated, as for a page load');
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'remember_mfa_required']);

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
        $user->forceFill(['remember_token' => 'mfa-remember-token'])->saveQuietly();
        $this->asFreshDevice()
            ->withCookie(...$this->recallerCookie($user, 'mfa-remember-token'))
            ->getJson(route('notifications.unread-count'))
            ->assertUnauthorized();

        $this->getJson(route('session.status', ['return' => 'https://evil.example.test/phish']))->assertUnauthorized();

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
            ->assertExactJson(['authenticated' => false]);
    }

    public function test_the_authenticated_shell_tells_the_dialog_where_to_ask(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('profile.edit'))
            ->assertOk()
            ->assertSee('statusUrl', false);
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
