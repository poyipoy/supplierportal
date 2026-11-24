<?php

namespace Tests\Feature;

use App\Models\ReferencePriceRule;
use App\Models\User;
use App\Services\UserPreferenceService;
use App\Support\WorkspaceShell;
use Database\Seeders\ReferencePriceRulesInitialSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceShortcutsPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_shortcuts_state_persists_across_logout_and_hydrates_into_workspace_shell_config(): void
    {
        config(['ui.workspace_shell.enabled' => true]);

        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $payload = [
            'scope' => 'import',
            'updated_at' => 1763900000000,
            'usage' => [
                '/purchasing/requisitions' => ['count' => 9, 'lastUsedAt' => 1763900000000],
                '/purchasing/purchase-orders' => ['count' => 4, 'lastUsedAt' => 1763890000000],
            ],
            'favorites' => [
                '/purchasing/requisitions',
                '/purchasing/quotations',
            ],
            'favorites_initialized' => true,
            'bookmarks' => [
                [
                    'url' => '/purchasing/purchase-orders?status=active',
                    'label' => 'Active POs',
                    'moduleKey' => 'purchase-orders',
                    'createdAt' => 1763880000000,
                ],
            ],
            'layout' => [
                'weights' => ['favorites' => 30, 'bookmarks' => 30, 'frequent' => 40],
                'minimized' => ['favorites' => false, 'bookmarks' => true, 'frequent' => false],
                'minimizedOrder' => ['bookmarks'],
            ],
        ];

        $this->actingAs($purchasing)
            ->patchJson(route('profile.workspace-shortcuts.update'), $payload)
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('shortcuts.usage./purchasing/requisitions.count', 9)
            ->assertJsonPath('shortcuts.favorites.0', '/purchasing/requisitions')
            ->assertJsonPath('shortcuts.bookmarks.0.label', 'Active POs')
            ->assertJsonPath('shortcuts.layout.minimized.bookmarks', true);

        // User logs out
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // User logs back in and opens a Workspace Shell page: shortcutsState is hydrated in workspaceConfig
        WorkspaceShell::resetCaches();
        $response = $this->actingAs($purchasing->fresh())
            ->get(route('purchasing.dashboard'))
            ->assertOk();

        $html = $response->getContent();
        $this->assertIsString($html);
        $this->assertStringContainsString('"shortcutsSyncUrl":', $html);
        $this->assertStringContainsString('\/purchasing\/requisitions', $html);
        $this->assertStringContainsString('Active POs', $html);
    }

    public function test_shortcuts_state_survives_customization_save_dashboard_layout_save_and_preferences_reset(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->patchJson(route('profile.workspace-shortcuts.update'), [
            'scope' => 'import',
            'updated_at' => 1763900001000,
            'usage' => [
                '/admin/users' => ['count' => 12, 'lastUsedAt' => 1763900001000],
            ],
            'favorites' => ['/admin/users'],
            'favorites_initialized' => true,
        ])->assertOk();

        // 1. Update dashboard layout -> shortcuts state must remain intact
        $this->actingAs($admin)->patchJson(route('profile.dashboard-layout.update'), [
            'dashboard' => [
                'order' => ['admin.notifications', 'admin.summary', 'admin.rates', 'admin.shortcuts'],
                'hidden' => ['admin.notifications'],
            ],
        ])->assertOk();

        // 2. Save full customization preferences -> shortcuts state must remain intact
        $this->actingAs($admin)->patch(route('profile.customization.update'), [
            'theme' => 'dark',
            'shell_style' => 'slate',
            'density' => 'compact',
            'sidebar_state' => 'collapsed',
            'quick_flow' => true,
            'page_size' => 50,
            'quick_access' => ['admin.users'],
            'accent' => 'slate',
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'iso',
            'time_format' => '24h',
            'number_format' => 'indonesian',
            'locale' => 'id',
        ])->assertRedirect(route('profile.customization'));

        // 3. Reset customization preferences -> shortcuts state must STILL remain intact
        $this->actingAs($admin)->delete(route('profile.customization.reset'))
            ->assertRedirect(route('profile.customization'));

        $service = app(UserPreferenceService::class);
        $freshUser = $admin->fresh();
        $shortcuts = $service->workspaceShortcutsForScope($freshUser, $service->for($freshUser), 'import');

        $this->assertSame(12, $shortcuts['usage']['/admin/users']['count'] ?? null);
        $this->assertSame(['/admin/users'], $shortcuts['favorites'] ?? null);
    }

    public function test_dual_scope_supplier_isolates_import_and_local_shortcuts_and_blocks_ineligible_scopes(): void
    {
        $dualSupplier = $this->createSupplierWithScopes(['import', 'local']);
        $importOnlySupplier = $this->createSupplierWithScopes(['import']);

        $this->actingAs($dualSupplier)->withSession(['supplier_context' => 'import'])
            ->patchJson(route('profile.workspace-shortcuts.update'), [
                'scope' => 'import',
                'usage' => ['/supplier/quotations' => ['count' => 5, 'lastUsedAt' => 1000]],
            ])->assertOk();

        $this->actingAs($dualSupplier)->withSession(['supplier_context' => 'local'])
            ->patchJson(route('profile.workspace-shortcuts.update'), [
                'scope' => 'local',
                'usage' => ['/local-supplier/invoices' => ['count' => 8, 'lastUsedAt' => 2000]],
            ])->assertOk();

        $service = app(UserPreferenceService::class);
        $prefs = $service->for($dualSupplier->fresh());
        $importShortcuts = $service->workspaceShortcutsForScope($dualSupplier, $prefs, 'import');
        $localShortcuts = $service->workspaceShortcutsForScope($dualSupplier, $prefs, 'local');

        $this->assertSame(5, $importShortcuts['usage']['/supplier/quotations']['count'] ?? null);
        $this->assertArrayNotHasKey('/local-supplier/invoices', $importShortcuts['usage'] ?? []);
        $this->assertSame(8, $localShortcuts['usage']['/local-supplier/invoices']['count'] ?? null);
        $this->assertArrayNotHasKey('/supplier/quotations', $localShortcuts['usage'] ?? []);

        // Import-only supplier cannot write to 'local' scope
        $this->actingAs($importOnlySupplier)->withSession(['supplier_context' => 'import'])
            ->patchJson(route('profile.workspace-shortcuts.update'), [
                'scope' => 'local',
                'usage' => ['/local-supplier/invoices' => ['count' => 3, 'lastUsedAt' => 1000]],
            ])->assertStatus(422);
    }

    public function test_it_rejects_unsafe_paths_and_malicious_bookmark_urls(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing']);

        $invalidPayloads = [
            'top-level security path in usage' => [
                'usage' => ['/login' => ['count' => 1, 'lastUsedAt' => 100]],
            ],
            'query string in usage path' => [
                'usage' => ['/purchasing/requisitions?foo=bar' => ['count' => 1, 'lastUsedAt' => 100]],
            ],
            'javascript pseudo-url in bookmarks' => [
                'bookmarks' => [['url' => 'javascript:alert(1)', 'label' => 'XSS']],
            ],
            'cross-origin url in bookmarks' => [
                'bookmarks' => [['url' => 'https://evil.example.test/purchasing/requisitions', 'label' => 'Phish']],
            ],
            'signed url in bookmarks' => [
                'bookmarks' => [['url' => '/purchasing/requisitions?signature=secret', 'label' => 'Signed']],
            ],
        ];

        foreach ($invalidPayloads as $reason => $payload) {
            $response = $this->actingAs($purchasing)->patchJson(route('profile.workspace-shortcuts.update'), $payload);
            $this->assertSame(422, $response->getStatusCode(), $reason);
        }
    }

    public function test_workspace_shortcuts_json_sync_preserves_pending_session_flash_and_old_input(): void
    {
        config(['ui.workspace_shell.enabled' => true]);

        $purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        $this->seed(ReferencePriceRulesInitialSeeder::class);
        $rule601 = ReferencePriceRule::where('category_key', '601-700')->firstOrFail();
        $rule1 = ReferencePriceRule::where('category_key', '1-15')->firstOrFail();

        // 1. Success flash + redirect, interrupted by a concurrent Workspace Shell shortcuts PATCH JSON sync
        $saveResponse = $this->actingAs($purchasing)->post(
            route('purchasing.master-data.reference-prices.rules.category.save'),
            [
                'form_mode' => 'edit',
                'rule_id' => $rule601->id,
                'category_key' => '601-720',
                'category_name' => 'Heavy 601-720',
                'dimension_min' => 601,
                'dimension_max' => 720,
            ]
        );
        $saveResponse->assertRedirect();

        $this->actingAs($purchasing)->patchJson(route('profile.workspace-shortcuts.update'), [
            'scope' => 'import',
            'usage' => ['/purchasing/master-data/reference-prices' => ['count' => 1, 'lastUsedAt' => 1763900002000]],
        ])->assertOk();

        $redirectUrl = route('purchasing.master-data.reference-prices.index', ['tab' => 'rules', '_embed' => 1]);
        $this->actingAs($purchasing)
            ->get($redirectUrl)
            ->assertOk()
            ->assertSee('data-adasi-flash', false)
            ->assertSee(__('materials.reference_prices.rules.saved_success'), false);

        // Consumed after the HTML page renders it once
        $this->actingAs($purchasing)
            ->get($redirectUrl)
            ->assertOk()
            ->assertDontSee('data-adasi-flash', false);

        // 2. Error flash + old input, interrupted by a concurrent Workspace Shell shortcuts PATCH JSON sync
        $errorResponse = $this->actingAs($purchasing)->post(
            route('purchasing.master-data.reference-prices.rules.category.save'),
            [
                'form_mode' => 'edit',
                'rule_id' => $rule1->id,
                'category_key' => '1-15',
                'category_name' => 'Overlap 1-20',
                'dimension_min' => 1,
                'dimension_max' => 20,
            ]
        );
        $errorResponse->assertRedirect();

        $this->actingAs($purchasing)->patchJson(route('profile.workspace-shortcuts.update'), [
            'scope' => 'import',
            'usage' => ['/purchasing/master-data/reference-prices' => ['count' => 2, 'lastUsedAt' => 1763900003000]],
        ])->assertOk();

        $this->actingAs($purchasing)
            ->get($redirectUrl)
            ->assertOk()
            ->assertSee('data-adasi-flash', false)
            ->assertSee('data-type="error"', false)
            ->assertSee('Overlap 1-20', false);
    }

    private function createSupplierWithScopes(array $scopes): User
    {
        $user = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => 'ACTIVE',
        ]);
        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            DB::table('supplier_scopes')->insert([
                'supplier_id' => $user->id,
                'scope' => $scope,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user->fresh();
    }
}
