<?php

namespace Tests\Feature\Auth;

use App\Models\Period;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutAllDevicesTest extends TestCase
{
    use RefreshDatabase;

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
