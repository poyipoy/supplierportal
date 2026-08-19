<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\PasswordConfirmationContinuationService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mfa_login_is_staged_without_authentication_or_password_in_session(): void
    {
        [$user, $secret] = $this->mfaUser();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('two-factor.challenge'));
        $response->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY, function (array $pending) use ($user): bool {
            return $pending['user_id'] === $user->id
                && $pending['remember'] === true
                && ! array_key_exists('password', $pending);
        });
        $this->assertGuest();

        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $this->post('/two-factor-challenge', ['code' => $code])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'login_success']);
    }

    public function test_totp_code_cannot_be_replayed(): void
    {
        [$user, $secret] = $this->mfaUser();
        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect();
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $replay = $this->post('/two-factor-challenge', ['code' => $code]);

        $replay->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_pending_challenge_expires_after_ten_minutes(): void
    {
        [$user] = $this->mfaUser();

        $this->withSession([
            TwoFactorService::PENDING_LOGIN_KEY => [
                'user_id' => $user->id,
                'remember' => false,
                'intended' => '/dashboard',
                'started_at' => now()->subMinutes(11)->timestamp,
            ],
        ])->get('/two-factor-challenge')
            ->assertRedirect(route('login'))
            ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY);
    }

    public function test_pending_challenge_does_not_preserve_an_external_intended_url(): void
    {
        [$user] = $this->mfaUser();

        $response = $this->withSession(['url.intended' => 'https://evil.example/phishing'])
            ->post('/login', ['email' => $user->email, 'password' => 'password']);

        $response->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY, fn (array $pending): bool => $pending['intended'] === route('dashboard', absolute: false)
        );
        $response->assertSessionMissing('url.intended');
    }

    public function test_two_factor_challenge_is_throttled_after_five_failed_codes(): void
    {
        [$user] = $this->mfaUser();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->post('/two-factor-challenge', ['code' => 'INVALID-CODE'])
                ->assertSessionHasErrors('code');
        }

        $this->post('/two-factor-challenge', ['code' => 'INVALID-CODE'])
            ->assertTooManyRequests();

        $this->assertGuest();
    }

    public function test_enrollment_encrypts_secret_and_produces_eight_single_use_recovery_codes(): void
    {
        $user = User::factory()->create();

        $start = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('profile.two-factor.start'));
        $start->assertRedirect(route('profile.two-factor.setup'));

        $encryptedSecret = $start->getSession()->get(TwoFactorService::PENDING_SETUP_KEY);
        $secret = Crypt::decryptString($encryptedSecret);
        $setupPage = $this->get(route('profile.two-factor.setup'));
        $setupPage->assertOk()->assertSee('data:image/svg+xml;base64,', false);

        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $confirmation = $this->post(route('profile.two-factor.confirm'), ['code' => $code]);
        $confirmation->assertOk()->assertViewIs('profile.two-factor-recovery-codes');
        $codes = $confirmation->viewData('codes');

        $this->assertCount(8, $codes);
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codes[0]);
        $raw = DB::table('users')->where('id', $user->id)->first();
        $this->assertNotSame($secret, $raw->two_factor_secret);
        $this->assertStringNotContainsString($codes[0], $raw->two_factor_recovery_codes);

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_invalid_enrollment_code_does_not_enable_two_factor_authentication(): void
    {
        $user = User::factory()->create();
        $start = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('profile.two-factor.start'));
        $secret = Crypt::decryptString($start->getSession()->get(TwoFactorService::PENDING_SETUP_KEY));
        $authenticator = app(TwoFactorService::class)->authenticator();
        $invalidCode = null;

        for ($candidate = 0; $candidate <= 999999; $candidate++) {
            $candidateCode = str_pad((string) $candidate, 6, '0', STR_PAD_LEFT);

            if (! $authenticator->verifyKey($secret, $candidateCode)) {
                $invalidCode = $candidateCode;
                break;
            }
        }

        $this->assertNotNull($invalidCode);

        $this->post(route('profile.two-factor.confirm'), ['code' => $invalidCode])
            ->assertSessionHasErrors('code');

        $this->assertFalse($user->fresh()->hasTwoFactorAuthentication());
    }

    public function test_password_confirmation_replays_mfa_disable_code_once(): void
    {
        [$user, $secret] = $this->mfaUser();
        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);

        $start = $this->actingAs($user)
            ->delete(route('profile.two-factor.destroy'), ['code' => $code])
            ->assertRedirect(route('password.confirm'));

        $confirmation = $this->withCookie(config('session.cookie'), $start->getCookie(config('session.cookie'))->getValue())
            ->post(route('password.confirm'), ['password' => 'password']);
        $confirmation->assertOk()->assertViewIs('auth.password-confirmation-continuation');

        $action = $confirmation->viewData('action');
        $this->assertSame('DELETE', $action['method']);
        $this->assertContains(['name' => 'code', 'value' => $code], $action['inputs']);
        $confirmation->assertSessionMissing(PasswordConfirmationContinuationService::SESSION_KEY);

        $this->post(parse_url($action['url'], PHP_URL_PATH), [
            '_method' => 'DELETE',
            'code' => $code,
        ])->assertRedirect(route('profile.edit'));

        $this->assertFalse($user->fresh()->hasTwoFactorAuthentication());
    }

    public function test_remembered_mfa_user_must_complete_a_new_challenge(): void
    {
        [$user, $secret] = $this->mfaUser();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);
        $code = app(TwoFactorService::class)->authenticator()->getCurrentOtp($secret);
        $completed = $this->post('/two-factor-challenge', ['code' => $code]);
        $cookieName = Auth::guard('web')->getRecallerName();
        $rememberCookie = $completed->getCookie($cookieName)?->getValue();

        $this->assertNotNull($rememberCookie);
        Auth::guard('web')->forgetUser();
        $this->flushSession();

        $response = $this->withCookie($cookieName, $rememberCookie)->get('/dashboard');

        $response->assertRedirect(route('two-factor.challenge'));
        $response->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY);
        $this->assertGuest();
    }

    #[DataProvider('optionalRoleProvider')]
    public function test_mfa_remains_optional_for_every_role(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $response->assertRedirect(route('dashboard', absolute: false));
        $response->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
    }

    public static function optionalRoleProvider(): array
    {
        return [
            'admin' => ['admin'],
            'purchasing' => ['purchasing'],
            'supplier' => ['supplier'],
            'qc' => ['qc'],
        ];
    }

    public function test_admin_can_reset_another_users_mfa_and_revoke_sessions(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        [$target] = $this->mfaUser();
        $originalVersion = $target->auth_session_version;

        $this->actingAs($admin)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('admin.users.two-factor.destroy', $target))
            ->assertRedirect();

        $target->refresh();
        $this->assertFalse($target->hasTwoFactorAuthentication());
        $this->assertGreaterThan($originalVersion, $target->auth_session_version);
        $this->assertDatabaseHas('auth_audit_logs', [
            'user_id' => $target->id,
            'event' => 'mfa_admin_reset',
        ]);
    }

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
