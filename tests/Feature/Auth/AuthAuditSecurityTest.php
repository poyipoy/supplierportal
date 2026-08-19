<?php

namespace Tests\Feature\Auth;

use App\Events\AuthSecurityEvent;
use App\Models\AuthAuditLog;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AuthAuditSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_success_and_failure_are_audited_without_credentials(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'NeverStore!ThisPassword']);
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'login_success']);
        $this->assertDatabaseHas('auth_audit_logs', ['email_attempted' => $user->email, 'event' => 'login_failed']);
        $serialized = AuthAuditLog::query()->get()->toJson();
        $this->assertStringNotContainsString('NeverStore!ThisPassword', $serialized);
        $this->assertStringNotContainsString('password', strtolower($serialized));
    }

    public function test_login_success_is_not_written_until_mfa_challenge_completes(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $secret = $twoFactor->authenticator()->generateSecretKey(32);
        $user = User::factory()->create();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => [],
            'two_factor_confirmed_at' => now(),
        ])->saveQuietly();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->assertDatabaseMissing('auth_audit_logs', ['user_id' => $user->id, 'event' => 'login_success']);

        $this->post('/two-factor-challenge', ['code' => $twoFactor->authenticator()->getCurrentOtp($secret)]);
        $this->assertSame(1, AuthAuditLog::query()->where('user_id', $user->id)->where('event', 'login_success')->count());
    }

    public function test_security_event_metadata_is_strictly_whitelisted(): void
    {
        $user = User::factory()->create();

        event(new AuthSecurityEvent('mfa_enabled', $user, metadata: [
            'reason' => 'user_setup',
            'secret' => 'DO-NOT-STORE',
            'otp' => '123456',
            'turnstile_token' => 'token-value',
        ]));

        $log = AuthAuditLog::query()->where('event', 'mfa_enabled')->firstOrFail();
        $this->assertSame(['reason' => 'user_setup'], $log->metadata);
        $this->assertStringNotContainsString('DO-NOT-STORE', $log->toJson());
        $this->assertStringNotContainsString('123456', $log->toJson());
        $this->assertStringNotContainsString('token-value', $log->toJson());
    }

    public function test_auth_audit_pages_are_admin_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.auth-audit-logs.index'))->assertOk();
        $this->actingAs($admin)->getJson(route('admin.auth-audit-logs.data'))->assertOk();

        foreach (['purchasing', 'supplier', 'qc'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('admin.auth-audit-logs.index'))->assertForbidden();
            $this->actingAs($user)->getJson(route('admin.auth-audit-logs.data'))->assertForbidden();
        }
    }

    public function test_admin_data_endpoint_escapes_untrusted_output(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        AuthAuditLog::query()->create([
            'user_id' => $admin->id,
            'email_attempted' => $admin->email,
            'event' => 'login_success',
            'user_agent' => '<script>alert(1)</script>',
            'metadata' => ['reason' => '<img src=x onerror=alert(1)>'],
        ]);

        $response = $this->actingAs($admin)->getJson(route('admin.auth-audit-logs.data', [
            'draw' => 1,
            'start' => 0,
            'length' => 10,
        ]));

        $response->assertOk();
        $encoded = $response->getContent();
        $this->assertStringNotContainsString('<script>', $encoded);
        $this->assertStringNotContainsString('<img', $encoded);
    }

    public function test_logs_older_than_180_days_are_prunable_but_boundary_is_retained(): void
    {
        $this->travelTo(now()->startOfSecond());
        DB::table('auth_audit_logs')->insert([
            [
                'event' => 'login_success',
                'created_at' => now()->subDays(181),
            ],
            [
                'event' => 'login_failed',
                'created_at' => now()->subDays(180),
            ],
            [
                'event' => 'logout',
                'created_at' => now(),
            ],
        ]);

        (new AuthAuditLog)->prunable()->delete();

        $this->assertDatabaseMissing('auth_audit_logs', ['event' => 'login_success']);
        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'login_failed']);
        $this->assertDatabaseHas('auth_audit_logs', ['event' => 'logout']);
    }
}
