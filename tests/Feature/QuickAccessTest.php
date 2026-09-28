<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_duplicate_arbitrary_and_over_limit_keys_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        foreach ([
            ['unknown.key'],
            ['admin.users', 'admin.users'],
            ['https://attacker.example'],
            ['admin.dashboard', 'admin.users', 'admin.registrations', 'admin.exchange-rates', 'admin.materials', 'admin.audit', 'admin.announcements'],
        ] as $keys) {
            $this->actingAs($user)
                ->patch(route('profile.customization.update'), $this->payload(['quick_access' => $keys]))
                ->assertSessionHasErrors('quick_access');
        }

        $this->assertDatabaseMissing('user_preferences', ['user_id' => $user->id]);
    }

    public function test_scalar_quick_access_input_shows_an_accessible_error_without_breaking_the_form(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)
            ->patch(route('profile.customization.update'), $this->payload(['quick_access' => 'admin.users']))
            ->assertSessionHasErrors('quick_access');

        $response = $this->get(route('profile.customization'));
        $response->assertOk()->assertSee('id="quick-access-error"', false);
        $this->assertSame(1, preg_match('/<input[^>]*name="quick_access\[\]"[^>]*aria-describedby="[^"]*quick-access-error[^"]*"/s', $response->getContent()));
    }

    public function test_shortcuts_from_another_role_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'finance']);

        $this->actingAs($user)
            ->patch(route('profile.customization.update'), $this->payload(['quick_access' => ['admin.users']]))
            ->assertSessionHasErrors('quick_access');
    }

    public function test_supplier_choices_are_limited_to_the_active_context(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->delete();
        $user->supplierScopes()->create(['scope' => 'local']);

        $this->actingAs($user)
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertSee('supplier_local.invoices', false)
            ->assertDontSee('supplier_import.quotations', false);

        $this->patch(route('profile.customization.update'), $this->payload([
            'quick_access' => ['supplier_import.quotations'],
            'supplier_context' => 'local',
        ]))->assertSessionHasErrors('quick_access');
    }

    public function test_dual_scope_supplier_without_a_context_has_no_operational_shortcuts(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);

        $this->actingAs($user)
            ->withSession(['supplier_context' => null])
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertSee('Choose a supplier portal', false)
            ->assertDontSee('supplier_local.invoices', false)
            ->assertDontSee('supplier_import.quotations', false);
    }

    public function test_saving_local_choices_preserves_valid_import_favorites(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $user->preference()->create([
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => ['supplier_import.quotations'],
            'revision' => 1,
        ]);

        $this->actingAs($user)
            ->withSession(['supplier_context' => 'local'])
            ->patch(route('profile.customization.update'), $this->payload([
                'quick_access' => ['supplier_local.invoices'],
                'supplier_context' => 'local',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['supplier_local.invoices', 'supplier_import.quotations'],
            $user->fresh()->preference->quick_access,
        );
    }

    public function test_saving_with_no_active_supplier_context_preserves_valid_favorites(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $user->preference()->create([
            'theme' => 'dark',
            'density' => 'compact',
            'sidebar_state' => 'collapsed',
            'page_size' => 50,
            'quick_access' => ['supplier_import.quotations', 'supplier_local.invoices'],
        ]);

        $this->actingAs($user)
            ->withSession(['supplier_context' => null])
            ->patch(route('profile.customization.update'), $this->payload([
                'theme' => 'light',
                'supplier_context' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame(
            ['supplier_import.quotations', 'supplier_local.invoices'],
            $user->fresh()->preference->quick_access,
        );
    }

    public function test_purchasing_shortcut_uses_the_remembered_list_url(): void
    {
        $user = User::factory()->create(['role' => 'purchasing']);
        $listUrl = '/purchasing/purchase-orders?status=active';

        $this->actingAs($user)
            ->withSession(['purchasing' => ['index_urls' => ['purchasing' => ['purchase-orders' => ['index' => $listUrl]]]]])
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertViewHas('quickAccessChoices', fn (array $choices) => $choices['purchasing.orders']['url'] === $listUrl);
    }

    public function test_saved_shortcuts_are_filtered_after_a_role_change(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $user->preference()->create([
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => ['admin.users'],
        ]);
        $user->forceFill(['role' => 'finance'])->save();

        $this->actingAs($user->fresh())
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertViewHas('selectedQuickAccess', [])
            ->assertViewHas('quickAccessChoices', fn (array $choices) => isset($choices['finance.invoices']) && ! isset($choices['admin.users']));
    }

    public function test_saved_local_shortcuts_are_filtered_after_scope_revocation(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);
        $user->preference()->create([
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => ['supplier_import.quotations', 'supplier_local.invoices'],
        ]);
        $user->supplierScopes()->where('scope', 'local')->delete();

        $this->actingAs($user->fresh())
            ->withSession(['supplier_context' => 'import'])
            ->get(route('profile.customization'))
            ->assertOk()
            ->assertViewHas('selectedQuickAccess', ['supplier_import.quotations'])
            ->assertViewHas('quickAccessChoices', fn (array $choices) => isset($choices['supplier_import.quotations']) && ! isset($choices['supplier_local.invoices']));
    }

    public function test_stale_context_marker_is_rejected(): void
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->create(['scope' => 'local']);

        $this->actingAs($user)
            ->withSession(['supplier_context' => 'local'])
            ->patch(route('profile.customization.update'), $this->payload([
                'quick_access' => ['supplier_local.invoices'],
                'supplier_context' => 'import',
            ]))
            ->assertSessionHasErrors('supplier_context');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'theme' => 'system',
            'density' => 'comfortable',
            'sidebar_state' => 'expanded',
            'page_size' => 25,
            'quick_access' => [],
            'supplier_context' => '',
        ], $overrides);
    }
}
