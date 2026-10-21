<?php

namespace Tests\Feature\Auth;

use App\Models\AuthAuditLog;
use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LogoutAllDevicesTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('logoutOptions')]
    public function test_logout_revokes_other_devices_only_when_enabled_and_selected(bool $enabled, array $payload, bool $revoked): void
    {
        config(['portal_session.logout_all_devices' => $enabled]);
        $user = User::factory()->create(['role' => 'admin', 'remember_token' => 'existing-remember-token']);
        $otherUser = User::factory()->create();
        $oldVersion = (int) $user->auth_session_version;

        $this->actingAs($user)->withSession(['auth_session_version' => $oldVersion])
            ->post(route('logout'), $payload)->assertRedirect(route('login'))
            ->assertHeaderMissing('Clear-Site-Data')
            ->assertCookieMissing(config('auth_security.known_devices.cookie_name', 'adasi_known_device'));

        $this->assertGuest();
        $user->refresh();
        $this->assertSame($oldVersion + (int) $revoked, $user->auth_session_version);
        $this->assertSame(1, $otherUser->fresh()->auth_session_version);
        $this->assertNotSame('existing-remember-token', $user->remember_token, 'Normal Laravel logout also rotates an existing remember token.');
        $this->assertDatabaseHas('auth_audit_logs', ['user_id' => $user->id, 'event' => 'logout']);
        $audit = AuthAuditLog::query()->where('user_id', $user->id)->where('event', 'other_device_logout')->first();
        if ($revoked) {
            $this->assertNotNull($audit);
            $this->assertSame(['guard' => 'web', 'reason' => 'logout_checkbox'], $audit->metadata);
        } else {
            $this->assertNull($audit);
        }

        // A second device presents its original session version through the real middleware.
        Auth::forgetGuards();
        $this->actingAs($user->fresh())->withSession(['auth_session_version' => $oldVersion, 'auth_absolute_started_at' => now()->timestamp]);
        $response = $this->getJson(route('notifications.unread-count'));
        if ($revoked) {
            $response->assertUnauthorized()->assertHeaderMissing('Location');
            $this->assertGuest();
        } else {
            $response->assertOk();
            $this->assertAuthenticatedAs($user);
        }
    }

    public static function logoutOptions(): array
    {
        return [
            'enabled and selected' => [true, ['logout_all' => '1'], true],
            'enabled without parameter' => [true, [], false],
            'enabled unchecked' => [true, ['logout_all' => '0'], false],
            'disabled forged parameter' => [false, ['logout_all' => '1'], false],
            'disabled default' => [false, [], false],
        ];
    }

    public function test_checkbox_is_opt_in_and_translated_in_both_locales(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach ([false, true] as $enabled) {
            config(['portal_session.logout_all_devices' => $enabled]);
            $response = $this->actingAs($user)->get(route('profile.edit'))->assertOk();
            if ($enabled) {
                $response->assertSee('name="logout_all" value="1"', false)
                    ->assertSee('data-bs-auto-close="outside"', false)
                    ->assertSee(__('navigation.logout_all_devices'));
            } else {
                $response->assertDontSee('name="logout_all"', false);
            }
        }
        foreach (['en', 'id'] as $locale) {
            $this->assertNotSame('navigation.logout_all_devices', trans('navigation.logout_all_devices', [], $locale));
        }
    }

    public function test_disabled_browser_flags_inject_no_authenticated_payload(): void
    {
        config(['portal_session.cross_tab_logout' => false, 'portal_session.scoped_storage_cleanup' => false]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get(route('profile.edit'))
            ->assertOk()->assertSee('window.AdasiSession = null;', false);
    }

    public function test_guest_login_has_marker_and_server_flags(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertSee('data-auth-login-page', false)
            ->assertSee('data-session-cross-tab="1"', false)
            ->assertSee('window.AdasiSession = null;', false);
    }

    public function test_authenticated_shell_has_post_logout_marker_and_server_session_payload(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('profile.edit'))->assertOk()
            ->assertSee('method="POST" data-logout-form', false)
            ->assertSee('window.AdasiLoginUrl = ', false)
            ->assertSee('window.AdasiSession = ', false)
            ->assertDontSee('data-auth-login-page', false);
    }

    public function test_guest_polling_returns_json_401_with_sso_on_or_off(): void
    {
        foreach ([false, true] as $sso) {
            config(['sso.enabled' => $sso]);
            foreach (['notifications.unread-count', 'conversations.unread-count'] as $route) {
                $this->getJson(route($route))->assertUnauthorized()->assertHeaderMissing('Location');
            }
        }
    }

    public function test_quotation_draft_key_uses_the_current_user_and_preserves_the_pr_hash(): void
    {
        $supplier = User::factory()->create();
        $other = User::factory()->create();
        $period = Period::create(['name' => 'Logout test', 'year' => 2026, 'month' => 10, 'status' => 'open', 'created_by' => $supplier->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $supplier->id, 'status' => 'bidding']);
        $pr->invitedSuppliers()->attach([$supplier->id, $other->id]);

        foreach ([$supplier, $other] as $user) {
            $this->actingAs($user)->get(route('supplier.quotations.create', $pr))->assertOk()
                ->assertSee('const draftUserId = '.$user->id.';', false)
                ->assertSee("const prId = '{$pr->hash}';", false)
                ->assertSee("const draftKey = 'quotation_draft_' + draftUserId + '_' + prId;", false);
        }
    }
}
