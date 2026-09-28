<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\Dashboard\DashboardWidgetService;
use App\Services\UserPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UserDashboardCustomizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phase_two_defaults_resolve_without_inserting_a_row(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $service = app(UserPreferenceService::class);
        $values = $service->for($user);
        $this->assertSame('brand', $values['accent']);
        $this->assertSame([], $values['dashboard_preferences']);
        $this->assertSame(1, $values['sidebar_revision']);
        $this->assertSame('sidebar-v2:1', $service->sidebarCacheVersion($user, $values));
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_valid_accent_and_layout_persist_for_authenticated_owner_only(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'finance']);
        $other->preference()->create([...array_diff_key(config('user_preferences.defaults'), ['sidebar_revision' => true]), 'theme' => 'light']);
        $otherPreferenceBefore = $other->fresh()->preference->getAttributes();
        $before = $user->fresh()->getAttributes();
        $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload([
            'accent' => 'slate',
            'dashboard' => ['hidden' => ['admin.notifications'], 'order' => ['admin.summary', 'admin.rates']],
            'user_id' => $other->id, 'role' => 'finance', 'password' => 'forged',
            'revision' => 100, 'sidebar_revision' => 100, 'account_status' => 'REJECTED',
        ]))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        $this->assertSame('slate', $saved->accent);
        $this->assertSame(['admin.notifications'], $saved->dashboard_preferences['admin']['hidden']);
        $this->assertSame('admin.summary', $saved->dashboard_preferences['admin']['order'][0]);
        $this->assertSame(1, $saved->revision);
        $this->assertSame(1, $saved->sidebar_revision);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertSame($otherPreferenceBefore, $other->fresh()->preference->getAttributes());
    }

    public function test_untrusted_accents_and_dashboard_payloads_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        foreach (['#ffffff', 'var(--danger)', 'unknown', '<script>'] as $accent) {
            $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload(['accent' => $accent]))->assertSessionHasErrors('accent');
        }
        foreach ([
            ['hidden' => ['finance.batches'], 'order' => []],
            ['hidden' => ['admin.notifications', 'admin.notifications'], 'order' => []],
            ['hidden' => ['admin.rates'], 'order' => []],
            ['hidden' => ['admin.unknown'], 'order' => []],
            ['hidden' => ['https://example.test'], 'order' => []],
            ['hidden' => [], 'order' => ['admin.summary', 'admin.summary']],
            ['hidden' => [], 'order' => [], 'view' => 'admin.dashboard'],
            ['admin' => ['hidden' => [], 'order' => []]],
        ] as $dashboard) {
            $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload(['dashboard' => $dashboard]))->assertSessionHasErrors();
        }
        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_registry_normalizes_stale_duplicates_cross_role_and_required_hidden_entries(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $widgets = app(DashboardWidgetService::class)->layoutFor($user, ['admin' => [
            'hidden' => ['admin.rates', 'admin.notifications', 'admin.notifications', 'finance.batches'],
            'order' => ['admin.summary', 'admin.removed', 'admin.summary', 'finance.batches'],
        ]]);
        $this->assertSame(['admin.summary', 'admin.rates', 'admin.notifications', 'admin.shortcuts'], array_column($widgets, 'key'));
        $byKey = array_column($widgets, null, 'key');
        $this->assertTrue($byKey['admin.rates']['visible']);
        $this->assertFalse($byKey['admin.notifications']['visible']);
        $this->assertTrue($byKey['admin.shortcuts']['visible']);
    }

    public function test_exact_dashboard_route_roles_are_honored_without_admin_bypass(): void
    {
        $service = app(DashboardWidgetService::class);
        $admin = User::factory()->create(['role' => 'admin']);
        $finance = User::factory()->create(['role' => 'finance']);
        $this->assertSame('admin', $service->audienceFor($admin));
        $this->assertSame('finance', $service->audienceFor($admin, 'finance.dashboard'));
        $this->assertSame('accounting', $service->audienceFor($admin, 'accounting.dashboard'));
        $this->assertSame('ga', $service->audienceFor($admin, 'ga.dashboard'));
        $this->assertNull($service->audienceFor($admin, 'qc.dashboard'));
        $this->assertNull($service->audienceFor($admin, 'purchasing.dashboard'));
        $this->assertSame('accounting', $service->audienceFor($finance, 'accounting.dashboard'));
        foreach (['admin', 'purchasing', 'finance', 'accounting', 'qc', 'ga'] as $role) {
            $this->assertSame($role, $service->audienceFor(User::factory()->create(['role' => $role])));
        }
    }

    public function test_supplier_context_layouts_preserve_each_other_and_reject_stale_marker(): void
    {
        $user = $this->supplier(['local', 'import']);
        $service = app(DashboardWidgetService::class);
        $this->actingAs($user)->withSession(['supplier_context' => 'local']);
        $this->patch(route('profile.customization.update'), $this->payload([
            'supplier_context' => 'local', 'dashboard' => ['hidden' => ['supplier.local.company'], 'order' => []],
        ]))->assertSessionHasNoErrors();
        $this->withSession(['supplier_context' => 'import'])->patch(route('profile.customization.update'), $this->payload([
            'supplier_context' => 'import', 'dashboard' => ['hidden' => ['supplier.import.metrics'], 'order' => []],
        ]))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference->dashboard_preferences;
        $this->assertSame(['supplier.local.company'], $saved['supplier.local']['hidden']);
        $this->assertSame(['supplier.import.metrics'], $saved['supplier.import']['hidden']);
        $this->patch(route('profile.customization.update'), $this->payload([
            'supplier_context' => 'local', 'dashboard' => ['hidden' => ['supplier.local.company'], 'order' => []],
        ]))->assertSessionHasErrors('supplier_context');
        $this->patch(route('profile.customization.update'), $this->payload([
            'supplier_context' => 'import', 'dashboard' => ['hidden' => ['supplier.local.company'], 'order' => []],
        ]))->assertSessionHasErrors();
        $user->supplierScopes()->where('scope', 'local')->delete();
        $normalized = $service->normalizeLayouts($user->fresh(), $saved);
        $this->assertArrayNotHasKey('supplier.local', $normalized);
        $this->assertArrayHasKey('supplier.import', $normalized);
    }

    public function test_supplier_without_context_has_no_operational_choices(): void
    {
        $user = $this->supplier(['local', 'import']);
        $this->actingAs($user)->withSession(['supplier_context' => null]);
        $this->get(route('profile.customization'))->assertOk()->assertViewHas('dashboardAudience', null)->assertViewHas('dashboardWidgets', []);
        $this->patch(route('profile.customization.update'), $this->payload([
            'dashboard' => ['hidden' => ['supplier.import.metrics'], 'order' => []],
        ]))->assertSessionHasErrors();
    }

    public function test_sidebar_token_only_changes_on_sidebar_change_or_reset_and_legacy_payload_preserves_new_fields(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $service = app(UserPreferenceService::class);
        $tokenBefore = $service->sidebarCacheVersion($user, $service->for($user));
        $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload(['accent' => 'slate']))->assertSessionHasNoErrors();
        $this->assertSame($tokenBefore, $service->sidebarCacheVersion($user, $service->for($user)));
        $this->patch(route('profile.customization.update'), $this->payload(['dashboard' => ['hidden' => ['admin.summary'], 'order' => []]]))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        $this->assertSame('slate', $saved->accent);
        $this->assertSame(1, $saved->sidebar_revision);
        $this->assertSame(2, $saved->revision);
        $this->patch(route('profile.customization.update'), $this->payload(['sidebar_state' => 'collapsed']))->assertSessionHasNoErrors();
        $saved = $user->fresh()->preference;
        $this->assertSame(2, $saved->sidebar_revision);
        $this->assertSame(['admin.summary'], $saved->dashboard_preferences['admin']['hidden']);
        $this->delete(route('profile.customization.reset'))->assertRedirect();
        $this->delete(route('profile.customization.reset'))->assertRedirect();
        $saved = $user->fresh()->preference;
        $this->assertSame('brand', $saved->accent);
        $this->assertSame([], $saved->dashboard_preferences);
        $this->assertSame([], $saved->quick_access);
        $this->assertSame('expanded', $saved->sidebar_state);
        $this->assertGreaterThan(2, $saved->sidebar_revision);
        $this->assertSame(5, $saved->revision);
    }

    public function test_corrupt_stored_accent_falls_back_and_frontend_values_are_bounded(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([...config('user_preferences.defaults'), 'accent' => '<script>', 'dashboard_preferences' => ['admin' => ['hidden' => ['admin.rates'], 'order' => []]]]);
        $service = app(UserPreferenceService::class);
        $values = $service->for($user);
        $this->assertSame('brand', $values['accent']);
        $payload = $service->frontendPayload($user, $values);
        $this->assertSame('brand', $payload['accent']);
        $this->assertEqualsCanonicalizing(['theme', 'density', 'accent', 'accentKeys', 'sidebarState', 'pageSize', 'sidebarRevision', 'accountId'], array_keys($payload));
        $this->assertNotContains('admin.rates', $values['dashboard_preferences']['admin']['hidden']);
    }

    public function test_internal_owner_and_revisions_are_not_mass_assignable(): void
    {
        $model = new UserPreference;
        foreach (['user_id', 'revision', 'sidebar_revision'] as $field) {
            $this->assertFalse($model->isFillable($field));
        }
    }

    public function test_customization_keeps_csrf_protection(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->app->detectEnvironment(fn () => 'csrf-verification');
        try {
            $this->actingAs($user)->patch(route('profile.customization.update'), $this->payload(['accent' => 'slate']))->assertStatus(419);
            $this->delete(route('profile.customization.reset'))->assertStatus(419);
            $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }

    public function test_guest_cannot_save_or_reset(): void
    {
        $this->patch(route('profile.customization.update'), $this->payload(['accent' => 'slate']))->assertRedirect(route('login'));
        $this->delete(route('profile.customization.reset'))->assertRedirect(route('login'));
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

    private function payload(array $overrides = []): array
    {
        return ['theme' => 'system', 'density' => 'comfortable', 'sidebar_state' => 'expanded', 'page_size' => 25, 'quick_access' => [], 'supplier_context' => '', ...$overrides];
    }

    public function test_corrupt_saved_accent_is_pruned_by_legacy_save(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $defaults = config('user_preferences.defaults');
        $user->preference()->create([
            ...array_diff_key($defaults, ['sidebar_revision' => true]),
            'accent' => 'unregistered-color',
        ]);
        $payload = array_intersect_key($defaults, array_flip(['theme', 'density', 'sidebar_state', 'page_size', 'quick_access']));
        $this->actingAs($user)->patch(route('profile.customization.update'), $payload)->assertSessionHasNoErrors();
        $this->assertSame($defaults['accent'], $user->preference()->first()->accent);
    }
}
