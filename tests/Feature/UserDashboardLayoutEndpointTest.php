<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDashboardLayoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ORDER = ['admin.notifications', 'admin.summary', 'admin.rates', 'admin.shortcuts'];

    public function test_it_saves_only_the_own_audience_layout_and_leaves_every_other_preference_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $bystander = User::factory()->create(['role' => 'finance']);
        $defaults = array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]);
        $admin->preference()->create([
            ...$defaults,
            'theme' => 'dark', 'accent' => 'slate', 'locale' => 'id', 'timezone' => 'Asia/Jakarta',
            'sidebar_state' => 'collapsed', 'quick_access' => ['admin.users'],
            'dashboard_preferences' => ['ga' => ['hidden' => ['ga.claims'], 'order' => []]],
        ]);
        $bystander->preference()->create([...$defaults, 'theme' => 'light']);
        $before = $admin->preference()->first()->getAttributes();
        $bystanderBefore = $bystander->preference()->first()->getAttributes();

        $this->actingAs($admin)->patchJson(route('profile.dashboard-layout.update'), [
            'dashboard' => ['order' => self::ADMIN_ORDER, 'hidden' => ['admin.notifications']],
        ])->assertOk();

        $after = $admin->preference()->first();
        $this->assertSame(self::ADMIN_ORDER, $after->dashboard_preferences['admin']['order']);
        $this->assertSame(['admin.notifications'], $after->dashboard_preferences['admin']['hidden']);
        $this->assertSame(['ga.claims'], $after->dashboard_preferences['ga']['hidden'], 'another audience of the same user is preserved');
        $this->assertSame($before['revision'] + 1, $after->revision);
        $this->assertSame($before['sidebar_revision'], $after->sidebar_revision, 'the sidebar token only moves on a sidebar change');

        $ignored = array_flip(['dashboard_preferences', 'revision', 'updated_at']);
        $this->assertSame(array_diff_key($before, $ignored), array_diff_key($after->getAttributes(), $ignored));
        $this->assertSame($bystanderBefore, $bystander->preference()->first()->getAttributes());
    }

    public function test_the_first_save_creates_the_row_from_defaults(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $admin->id]);

        $this->actingAs($admin)->patchJson(route('profile.dashboard-layout.update'), [
            'dashboard' => ['order' => self::ADMIN_ORDER, 'hidden' => []],
        ])->assertOk();

        $saved = $admin->preference()->first();
        $this->assertSame(config('user_preferences.defaults.theme'), $saved->theme);
        $this->assertSame(config('user_preferences.defaults.locale'), $saved->locale);
        $this->assertSame(1, $saved->revision);
        $this->assertSame(1, $saved->sidebar_revision);
        $this->assertSame(self::ADMIN_ORDER, $saved->dashboard_preferences['admin']['order']);
    }

    public function test_json_callers_get_the_own_dashboard_and_the_toast_message_is_flashed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->patchJson(route('profile.dashboard-layout.update'), [
            'dashboard' => ['order' => self::ADMIN_ORDER, 'hidden' => []],
        ])->assertOk()
            ->assertExactJson(['redirect' => route('admin.dashboard')])
            ->assertSessionHas('success', 'Dashboard layout saved.');
    }

    public function test_plain_form_callers_are_redirected_back_with_the_message(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->from(route('admin.dashboard'))->patch(route('profile.dashboard-layout.update'), [
            'dashboard' => ['order' => self::ADMIN_ORDER, 'hidden' => []],
        ])->assertRedirect(route('admin.dashboard'))->assertSessionHas('success', 'Dashboard layout saved.');
    }

    public function test_it_rejects_required_hidden_unknown_foreign_duplicate_and_malformed_payloads(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payloads = [
            'hiding a required widget' => ['dashboard' => ['order' => [], 'hidden' => ['admin.rates']]],
            'an unknown key' => ['dashboard' => ['order' => [], 'hidden' => ['admin.unknown']]],
            'another audience key in hidden' => ['dashboard' => ['order' => [], 'hidden' => ['finance.batches']]],
            'another audience key in order' => ['dashboard' => ['order' => ['purchasing.metrics'], 'hidden' => []]],
            'a duplicate in order' => ['dashboard' => ['order' => ['admin.summary', 'admin.summary'], 'hidden' => []]],
            'a duplicate in hidden' => ['dashboard' => ['order' => [], 'hidden' => ['admin.notifications', 'admin.notifications']]],
            'an extra layout key' => ['dashboard' => ['order' => [], 'hidden' => [], 'view' => 'admin.dashboard']],
            'an audience-shaped map' => ['dashboard' => ['admin' => ['order' => [], 'hidden' => []]]],
            'hidden as a scalar' => ['dashboard' => ['order' => [], 'hidden' => 'admin.notifications']],
            'a missing dashboard' => [],
            'an empty dashboard' => ['dashboard' => []],
        ];

        foreach ($payloads as $label => $payload) {
            $response = $this->actingAs($admin)->patchJson(route('profile.dashboard-layout.update'), $payload);
            $this->assertSame(422, $response->getStatusCode(), $label);
        }

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $admin->id]);
    }

    public function test_a_supplier_without_a_portal_context_is_refused(): void
    {
        $dual = $this->supplier(['local', 'import']);
        $this->actingAs($dual)->withSession(['supplier_context' => null])
            ->patchJson(route('profile.dashboard-layout.update'), ['dashboard' => ['order' => [], 'hidden' => []]])
            ->assertForbidden();

        $unscoped = $this->supplier([]);
        $this->actingAs($unscoped)->patchJson(route('profile.dashboard-layout.update'), ['dashboard' => ['order' => [], 'hidden' => []]])
            ->assertForbidden();

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $dual->id]);
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $unscoped->id]);
    }

    public function test_a_dual_scope_supplier_saves_the_active_context_only(): void
    {
        $user = $this->supplier(['local', 'import']);
        $user->preference()->create([
            ...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]),
            'dashboard_preferences' => ['supplier.local' => ['hidden' => ['supplier.local.company'], 'order' => []]],
        ]);

        $this->actingAs($user)->withSession(['supplier_context' => 'import'])
            ->patchJson(route('profile.dashboard-layout.update'), ['dashboard' => ['order' => [], 'hidden' => ['supplier.import.updates']]])
            ->assertOk()->assertExactJson(['redirect' => route('supplier.dashboard')]);

        $saved = $user->preference()->first()->dashboard_preferences;
        $this->assertSame(['supplier.import.updates'], $saved['supplier.import']['hidden']);
        $this->assertSame(['supplier.local.company'], $saved['supplier.local']['hidden']);

        $this->patchJson(route('profile.dashboard-layout.update'), ['dashboard' => ['order' => [], 'hidden' => ['supplier.local.company']]])
            ->assertStatus(422);
    }

    public function test_guests_and_missing_csrf_tokens_are_rejected(): void
    {
        $payload = ['dashboard' => ['order' => self::ADMIN_ORDER, 'hidden' => []]];
        $this->patchJson(route('profile.dashboard-layout.update'), $payload)->assertUnauthorized();
        $this->patch(route('profile.dashboard-layout.update'), $payload)->assertRedirect(route('login'));

        $admin = User::factory()->create(['role' => 'admin']);
        $this->app->detectEnvironment(fn () => 'csrf-verification');
        try {
            $this->actingAs($admin)->patchJson(route('profile.dashboard-layout.update'), $payload)->assertStatus(419);
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $admin->id]);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    private function supplier(array $scopes): User
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            DB::table('supplier_scopes')->insert(['supplier_id' => $user->id, 'scope' => $scope, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $user;
    }
}
