<?php

namespace Tests\Feature\Auth;

use App\Events\AuthSecurityEvent;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Models\User;
use App\Services\Auth\CompleteLoginService;
use App\Services\Auth\SessionInventoryService;
use App\Services\Auth\TwoFactorService;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TargetedAuthenticationCorrectnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_a01_common_cleanup_precedes_guard_login_and_preserves_other_metadata(): void
    {
        $pending = $this->mfaUser();
        $user = User::factory()->create(['role' => 'admin']);
        config(['ui.auth_splash.enabled' => true]);
        $guardCalls = 0;
        Event::listen(Login::class, function () use (&$guardCalls): void {
            $guardCalls++;
            $this->assertFalse(session()->has(TwoFactorService::PENDING_LOGIN_KEY));
            $this->assertSame('keep', session(TwoFactorService::PENDING_SETUP_KEY));
            $this->assertSame('id', session('locale'));
        });

        $this->withSession($this->pending($pending) + [
            TwoFactorService::PENDING_SETUP_KEY => 'keep', 'locale' => 'id',
            'auth.password_confirmed_at' => 123, 'url.intended' => '/profile',
        ])->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect('/profile')->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY)
            ->assertSessionHas('auth.password_confirmed_at', 123)->assertSessionHas('ui.auth_splash.reason', 'post-login')
            ->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, $guardCalls);
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'login_success']);
        $this->assertDatabaseHas('auth_known_devices', ['user_id' => $user->id]);
    }

    #[DataProvider('proofs')]
    public function test_a02_mfa_completion_retains_one_use_proof_and_common_cleanup(string $proof): void
    {
        $user = $this->mfaUser();
        $service = app(TwoFactorService::class);
        $code = $this->code($user, $proof);
        $this->withSession($this->pending($user) + ['unrelated' => 'keep'])
            ->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/profile')
            ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY)->assertSessionHas('unrelated', 'keep');
        $this->assertAuthenticatedAs($user);
        $this->assertNull($service->verifyLoginCode($user->fresh(), $code));
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id,
            'event' => $proof === 'recovery' ? 'mfa_recovery_code_used' : 'mfa_challenge_succeeded']);
    }

    public static function proofs(): array
    {
        return [['totp'], ['recovery']];
    }

    #[DataProvider('authenticatedChallenges')]
    public function test_a03_a04_authenticated_challenge_never_invokes_proof_or_guard(string $method, bool $json, bool $same): void
    {
        $current = $this->mfaUser();
        $pending = $same ? $current : $this->mfaUser();
        $this->partialMock(TwoFactorService::class)->shouldNotReceive('verifyLoginCode');
        Event::fake([Login::class, AuthSecurityEvent::class]);
        $this->actingAs($current)->withSession($this->pending($pending) + [
            'auth_absolute_started_at' => now()->subMinute()->timestamp,
            'auth_session_version' => $current->auth_session_version,
            TwoFactorService::PENDING_SETUP_KEY => 'keep',
        ]);
        $id = session()->getId();
        $csrf = session()->token();
        $started = session('auth_absolute_started_at');
        $this->withCookie(config('session.cookie'), $id);
        $response = $this->call($method, '/two-factor-challenge', ['code' => $this->code($pending, 'recovery')], $this->prepareCookiesForRequest(), [],
            $json ? ['HTTP_ACCEPT' => 'application/json'] : []);
        if ($json) {
            $response->assertStatus(409)->assertExactJson(['message' => __('auth.feedback.stale_challenge')]);
        } else {
            $response->assertRedirect(route('dashboard'));
        }
        $response->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY)
            ->assertSessionHas(TwoFactorService::PENDING_SETUP_KEY, 'keep')
            ->assertSessionHas('auth_absolute_started_at', $started)
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($current);
        $this->assertSame($id, session()->getId());
        $this->assertSame($csrf, session()->token());
        $this->assertCount(1, $pending->fresh()->two_factor_recovery_codes);
        $this->assertDatabaseCount('auth_known_devices', 0);
        Event::assertNotDispatched(Login::class);
        Event::assertNotDispatched(AuthSecurityEvent::class);
    }

    public static function authenticatedChallenges(): array
    {
        $cases = [];
        foreach (['GET', 'POST'] as $method) {
            foreach ([false, true] as $json) {
                foreach ([false, true] as $same) {
                    $cases[] = [$method, $json, $same];
                }
            }
        }

        return $cases;
    }

    public function test_a04_csrf_rejection_precedes_authenticated_conflict(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests(): bool
            {
                return false;
            }
        });
        $user = $this->mfaUser();
        $this->actingAs($user)->withSession($this->pending($user) + ['_token' => 'expected']);
        foreach ([[], ['_token' => 'forged']] as $payload) {
            $this->postJson('/two-factor-challenge', $payload)->assertStatus(419)
                ->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY);
            $this->assertAuthenticatedAs($user);
        }
        $this->postJson('/two-factor-challenge', ['_token' => 'expected'])->assertStatus(409);
    }

    public function test_a05_old_challenge_cannot_replace_completed_non_mfa_account(): void
    {
        $pending = $this->mfaUser();
        $current = User::factory()->create(['role' => 'admin']);
        $this->post('/login', ['email' => $pending->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        $this->post('/login', ['email' => $current->email, 'password' => 'password'])->assertRedirect('/dashboard');
        $this->post('/two-factor-challenge', ['code' => $this->code($pending, 'recovery')])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($current);
        $this->assertCount(1, $pending->fresh()->two_factor_recovery_codes);
    }

    public function test_a03_controller_and_completion_defend_authenticated_state_independently(): void
    {
        $current = $this->mfaUser();
        $pending = $this->mfaUser();
        $this->actingAs($current)->withSession($this->pending($pending));
        $request = Request::create('/two-factor-challenge', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(session()->driver());
        $this->partialMock(TwoFactorService::class)->shouldNotReceive('verifyLoginCode');
        foreach (['controller', 'completion'] as $boundary) {
            try {
                if ($boundary === 'controller') {
                    app(TwoFactorChallengeController::class)->store($request, app(TwoFactorService::class), app(CompleteLoginService::class));
                } else {
                    app(CompleteLoginService::class)->complete($request, $pending, true);
                }
                $this->fail('Authenticated boundary accepted');
            } catch (HttpResponseException $exception) {
                $this->assertSame(409, $exception->getResponse()->getStatusCode());
            }
            $this->assertAuthenticatedAs($current);
        }
        $this->assertCount(1, $pending->fresh()->two_factor_recovery_codes);
    }

    public function test_a09_failed_unrelated_credentials_preserve_legitimate_pending(): void
    {
        $pending = $this->mfaUser();
        $other = User::factory()->create(['role' => 'admin']);
        $this->withSession($this->pending($pending))->post('/login', ['email' => $other->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email')
            ->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY, fn ($value) => $value['user_id'] === $pending->id);
        $this->assertGuest();
    }

    public function test_profile_verifier_retains_its_generic_contract(): void
    {
        $user = $this->mfaUser();
        $user->forceFill(['account_status' => 'REJECTED'])->saveQuietly();
        $service = app(TwoFactorService::class);
        $code = $this->code($user, 'recovery');
        $this->assertSame('recovery', $service->verifyUserCode($user, $code));
        $this->assertNull($service->verifyUserCode($user, $code));
    }

    public function test_a15_admission_does_not_activate_supplier_assign_scope_or_change_immutable_email(): void
    {
        $user = User::create(['name' => 'Pending supplier', 'email' => 'pending@m02a.invalid',
            'password' => 'password', 'role' => 'supplier', 'is_active' => false, 'account_status' => 'PENDING']);
        $this->postJson('/login', ['email' => $user->email, 'password' => 'password'])->assertUnprocessable();
        $this->assertSame('PENDING', $user->fresh()->account_status);
        $this->assertFalse($user->fresh()->is_active);
        $this->assertSame(0, $user->supplierScopes()->count());
        try {
            $user->email = 'changed@m02a.invalid';
            $user->save();
            $this->fail('Immutable email changed');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('email', $exception->errors());
        }
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'pending@m02a.invalid']);
    }

    #[DataProvider('eligibility')]
    public function test_a06_password_and_mfa_share_eligibility(bool $active, string $status): void
    {
        $user = $this->mfaUser();
        $user->forceFill(['is_active' => $active, 'account_status' => $status])->saveQuietly();
        $eligible = $active && $status === User::ACCOUNT_STATUS_ACTIVE;
        $this->assertSame($eligible, $user->isAuthenticationEligible());
        $password = $this->postJson('/login', ['email' => $user->email, 'password' => 'password']);
        if ($eligible) {
            $password->assertRedirect('/two-factor-challenge');
        } else {
            $password->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $mfa = $this->withSession($this->pending($user))->postJson('/two-factor-challenge', ['code' => $this->code($user, 'recovery')]);
        if ($eligible) {
            $mfa->assertRedirect('/profile');
            $this->assertAuthenticatedAs($user);
        } else {
            $mfa->assertUnprocessable()->assertJsonValidationErrors('email')->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY);
            $this->assertGuest();
            $this->assertCount(1, $user->fresh()->two_factor_recovery_codes);
        }
    }

    public static function eligibility(): array
    {
        $cases = [];
        foreach ([false, true] as $active) {
            foreach (['PENDING', 'REVISION', 'ACTIVE', 'REJECTED'] as $status) {
                $cases[] = [$active, $status];
            }
        }

        return $cases;
    }

    public function test_a06_missing_and_ineligible_password_paths_still_verify_dummy_hash(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'account_status' => 'PENDING']);
        $dummy = config('auth_security.dummy_hash');
        Hash::shouldReceive('check')->twice()->with('wrong', $dummy)->andReturn(false);
        foreach ([$user->email, 'missing@m02a.invalid'] as $email) {
            $this->postJson('/login', ['email' => '  '.strtoupper($email).'  ', 'password' => 'wrong'])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->assertGuest();
    }

    #[DataProvider('securityChanges')]
    public function test_a07_locked_admission_rejects_changed_subject_before_consumption(string $change, string $proof): void
    {
        $user = $this->mfaUser();
        $code = $this->code($user, $proof);
        $real = app(TwoFactorService::class);
        $this->partialMock(TwoFactorService::class)->shouldReceive('verifyLoginCode')->once()
            ->andReturnUsing(function (User $snapshot, string $code) use ($real, $change): ?string {
                $this->changeSecurity($snapshot, $change);

                return $real->verifyLoginCode($snapshot, $code);
            });
        $this->withSession($this->pending($user))->postJson('/two-factor-challenge', ['code' => $code])
            ->assertUnprocessable()->assertJsonValidationErrors('email')->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY);
        $this->assertGuest();
        if ($change !== 'deleted') {
            $fresh = $user->fresh();
            $this->assertNull($fresh->two_factor_last_used_step);
            $this->assertCount(1, $fresh->two_factor_recovery_codes);
        }
    }

    #[DataProvider('securityChanges')]
    public function test_a08_final_admission_does_not_adopt_changed_configuration_or_restore_consumed_proof(string $change, string $proof): void
    {
        $user = $this->mfaUser();
        $real = app(CompleteLoginService::class);
        $this->partialMock(CompleteLoginService::class)->shouldReceive('complete')->once()
            ->andReturnUsing(function (Request $request, User $snapshot, bool $remember) use ($real, $change): void {
                $this->changeSecurity($snapshot, $change);
                $real->complete($request, $snapshot, $remember);
            });
        $this->withSession($this->pending($user))->postJson('/two-factor-challenge', ['code' => $this->code($user, $proof)])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY)->assertSessionMissing('ui.auth_splash');
        $this->assertGuest();
        $this->assertFalse(request()->attributes->get('auth_security.login_completed', false));
        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'login_success']);
        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'mfa_challenge_succeeded']);
        if ($proof === 'recovery' && $change !== 'deleted') {
            $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'mfa_recovery_code_used']);
        }
        if ($change !== 'deleted') {
            $fresh = $user->fresh();
            $proof === 'totp' ? $this->assertNotNull($fresh->two_factor_last_used_step) : $this->assertCount(0, $fresh->two_factor_recovery_codes);
        }
    }

    public static function securityChanges(): array
    {
        $cases = [];
        foreach (['inactive', 'status', 'password', 'version', 'secret', 'confirmed', 'deleted'] as $change) {
            foreach (['totp', 'recovery'] as $proof) {
                $cases[] = [$change, $proof];
            }
        }

        return $cases;
    }

    #[DataProvider('nonMfaChanges')]
    public function test_a08_password_completion_rechecks_fresh_configuration_without_discarding_unrelated_pending(string $change): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $pending = $this->mfaUser();
        $real = app(CompleteLoginService::class);
        $this->partialMock(CompleteLoginService::class)->shouldReceive('complete')->once()
            ->andReturnUsing(function (Request $request, User $snapshot, bool $remember) use ($real, $change): void {
                $this->changeSecurity($snapshot, $change);
                $real->complete($request, $snapshot, $remember);
            });
        $this->withSession($this->pending($pending))->postJson('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email')
            ->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY, fn ($value) => $value['user_id'] === $pending->id);
        $this->assertGuest();
        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'login_success']);
    }

    public static function nonMfaChanges(): array
    {
        return array_map(fn ($change) => [$change], ['inactive', 'status', 'password', 'version', 'mfa_enabled', 'deleted']);
    }

    #[DataProvider('eligibility')]
    public function test_a06_a09_final_eligibility_rejection_only_cleans_the_rejected_subject(bool $active, string $status): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $snapshot = clone $user;
        $other = $this->mfaUser();
        $this->withSession($this->pending($other) + ['unrelated' => 'keep']);
        $user->forceFill(['is_active' => $active, 'account_status' => $status])->saveQuietly();
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(session()->driver());
        if ($active && $status === 'ACTIVE') {
            app(CompleteLoginService::class)->complete($request, $snapshot, false);
            $this->assertFalse(session()->has(TwoFactorService::PENDING_LOGIN_KEY));
            $this->assertAuthenticatedAs($user);
        } else {
            try {
                app(CompleteLoginService::class)->complete($request, $snapshot, false);
                $this->fail('Ineligible final admission accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('email', $exception->errors());
                $this->assertSame($other->id, session(TwoFactorService::PENDING_LOGIN_KEY)['user_id']);
                $this->assertGuest();
            }
        }
        $this->assertSame('keep', session('unrelated'));
    }

    #[DataProvider('recallEntries')]
    public function test_a10_recalled_mfa_converts_before_challenge_rejection(string $entry): void
    {
        $user = $this->mfaUser();
        $this->withCookie(...$this->recaller($user))->get($entry)->assertRedirect('/two-factor-challenge')
            ->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY, fn ($pending) => $pending['user_id'] === $user->id && $pending['remember']);
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => $this->code($user, 'recovery')])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    }

    public static function recallEntries(): array
    {
        return [['/profile/security'], ['/two-factor-challenge']];
    }

    public function test_a11_recalled_mfa_json_terminates_and_non_mfa_recall_is_preserved(): void
    {
        $user = $this->mfaUser();
        $this->withCredentials()->withCookie(...$this->recaller($user))->getJson('/two-factor-challenge')->assertUnauthorized()
            ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY);
        $this->assertGuest();
        $plain = User::factory()->create(['role' => 'admin']);
        Auth::forgetGuards();
        $this->withCookie(...$this->recaller($plain))->get('/dashboard')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($plain);
    }

    public function test_a12_pending_deadline_does_not_slide_and_equality_is_preserved(): void
    {
        $user = $this->mfaUser();
        $this->freezeTime();
        $pending = $this->pending($user, now()->subSeconds(600)->timestamp);
        $this->withSession($pending)->get('/two-factor-challenge')->assertOk()->assertSessionHas(TwoFactorService::PENDING_LOGIN_KEY, $pending[TwoFactorService::PENDING_LOGIN_KEY]);
        $this->travel(1)->seconds();
        $this->get('/two-factor-challenge')->assertRedirect('/login')->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY);
    }

    public function test_a13_guest_replacement_policy_is_retained_and_a_proof_cannot_complete_b(): void
    {
        $a = $this->mfaUser();
        $b = $this->mfaUser();
        foreach ([$a, $b] as $user) {
            $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/two-factor-challenge');
        }
        $this->post('/two-factor-challenge', ['code' => $this->code($a, 'recovery')])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/two-factor-challenge', ['code' => $this->code($b, 'recovery')])->assertRedirect();
        $this->assertAuthenticatedAs($b);
        $this->assertCount(1, $a->fresh()->two_factor_recovery_codes);
    }

    #[DataProvider('completionPaths')]
    public function test_a16_post_guard_fault_does_not_restore_pending_or_retry_consumed_proof(bool $mfa): void
    {
        config(['ui.auth_splash.enabled' => true]);
        $user = $mfa ? $this->mfaUser() : User::factory()->create(['role' => 'admin']);
        $this->mock(SessionInventoryService::class)->shouldReceive('enforceConcurrentLimit')->once()
            ->andThrow(new RuntimeException('Controlled M02A post-guard failure'));
        $this->withSession($this->pending($user) + ['unrelated' => 'keep']);
        $payload = $mfa ? ['code' => $this->code($user, 'recovery')] : ['email' => $user->email, 'password' => 'password'];
        $this->postJson($mfa ? '/two-factor-challenge' : '/login', $payload)->assertStatus(500)
            ->assertSessionMissing(TwoFactorService::PENDING_LOGIN_KEY)->assertSessionMissing('ui.auth_splash')
            ->assertSessionHas('unrelated', 'keep');
        $this->assertAuthenticatedAs($user); // Explicit F07 partial outcome, not rollback.
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'login_success']);
        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'mfa_challenge_succeeded']);
        if ($mfa) {
            $this->assertCount(0, $user->fresh()->two_factor_recovery_codes);
            $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'mfa_recovery_code_used']);
        }
    }

    public static function completionPaths(): array
    {
        return [[false], [true]];
    }

    private function mfaUser(): User
    {
        $user = User::factory()->create(['role' => 'admin']);
        $code = 'M02A-'.$user->id.'-RECOVERY';
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($code));
        $user->forceFill([
            'two_factor_secret' => app(TwoFactorService::class)->authenticator()->generateSecretKey(32),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => [hash_hmac('sha256', $normalized, hash('sha256', config('app.key')))],
        ])->saveQuietly();

        return $user;
    }

    private function code(User $user, string $proof): string
    {
        return $proof === 'recovery' ? 'M02A-'.$user->id.'-RECOVERY'
            : app(TwoFactorService::class)->authenticator()->getCurrentOtp($user->two_factor_secret);
    }

    private function pending(User $user, ?int $started = null): array
    {
        return [TwoFactorService::PENDING_LOGIN_KEY => [
            'user_id' => $user->id, 'remember' => false, 'intended' => '/profile',
            'started_at' => $started ?? now()->timestamp,
        ]];
    }

    private function changeSecurity(User $snapshot, string $change): void
    {
        if ($change === 'deleted') {
            DB::table('users')->where('id', $snapshot->id)->delete();

            return;
        }
        $values = match ($change) {
            'inactive' => ['is_active' => false], 'status' => ['account_status' => 'REJECTED'],
            'password' => ['password' => Hash::make('changed-password')],
            'version' => ['auth_session_version' => $snapshot->auth_session_version + 1],
            'secret' => ['two_factor_secret' => app(TwoFactorService::class)->authenticator()->generateSecretKey(32)],
            'mfa_enabled' => ['two_factor_secret' => app(TwoFactorService::class)->authenticator()->generateSecretKey(32), 'two_factor_confirmed_at' => now()],
            'confirmed' => ['two_factor_confirmed_at' => now()->subDay()],
        };
        $snapshot->fresh()->forceFill($values)->saveQuietly();
    }

    private function recaller(User $user): array
    {
        $guard = Auth::guard('web');

        return [$guard->getRecallerName(), $user->id.'|'.$user->remember_token.'|'.$guard->hashPasswordForCookie($user->getAuthPassword())];
    }
}
