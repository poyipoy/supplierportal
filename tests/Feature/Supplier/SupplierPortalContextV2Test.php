<?php

namespace Tests\Feature\Supplier;

use App\Models\Supplier;
use App\Models\User;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPortalContextV2Test extends TestCase
{
    use RefreshDatabase;

    private function supplierWithScopes(array $scopes, array $attributes = []): User
    {
        $user = User::factory()->create(array_merge([
            'role' => 'supplier',
            'is_active' => true,
        ], $attributes));

        $user->supplierScopes()->delete();
        foreach ($scopes as $scope) {
            $user->supplierScopes()->create(['scope' => $scope]);
        }

        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'Vendor '.$user->id,
            'address' => 'Jakarta',
            'phone' => '08123456789',
            'npwp' => '01.234.567.8-901.000',
            'category' => 'Raw Material',
            'payment_term_days' => 30,
        ]);

        return $user;
    }

    // ─── 1. Context Switching ───

    public function test_dual_scope_supplier_can_switch_to_local_dashboard(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);

        $response = $this->actingAs($supplier)
            ->withSession(['supplier_context' => 'import'])
            ->post(route('supplier-context.store'), ['context' => 'local']);

        $response->assertRedirect(route('local-supplier.dashboard', absolute: false));
        $this->assertSame('local', session('supplier_context'));
    }

    public function test_dual_scope_supplier_can_switch_to_material_procurement_dashboard(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);

        $response = $this->actingAs($supplier)
            ->withSession(['supplier_context' => 'local'])
            ->post(route('supplier-context.store'), ['context' => 'import']);

        $response->assertRedirect(route('supplier.dashboard', absolute: false));
        $this->assertSame('import', session('supplier_context'));
    }

    public function test_switching_preserves_user_role_and_scope_records(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);
        $initialScopes = $supplier->supplierScopes()->orderBy('scope')->pluck('scope')->all();

        $this->actingAs($supplier)->post(route('supplier-context.store'), ['context' => 'local']);

        $supplier->refresh();
        $this->assertSame('supplier', $supplier->role);
        $this->assertSame($initialScopes, $supplier->supplierScopes()->orderBy('scope')->pluck('scope')->all());

        $this->actingAs($supplier)->post(route('supplier-context.store'), ['context' => 'import']);

        $supplier->refresh();
        $this->assertSame('supplier', $supplier->role);
        $this->assertSame($initialScopes, $supplier->supplierScopes()->orderBy('scope')->pluck('scope')->all());
    }

    // ─── 2. Authorization & Boundaries ───

    public function test_local_only_supplier_cannot_switch_to_material_procurement(): void
    {
        $supplier = $this->supplierWithScopes(['local']);

        $this->actingAs($supplier)
            ->post(route('supplier-context.store'), ['context' => 'import'])
            ->assertForbidden();
    }

    public function test_import_only_supplier_cannot_switch_to_local(): void
    {
        $supplier = $this->supplierWithScopes(['import']);

        $this->actingAs($supplier)
            ->post(route('supplier-context.store'), ['context' => 'local'])
            ->assertForbidden();
    }

    public function test_forged_or_invalid_context_value_is_rejected(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);

        $this->actingAs($supplier)
            ->post(route('supplier-context.store'), ['context' => 'admin'])
            ->assertSessionHasErrors('context');

        $this->actingAs($supplier)
            ->post(route('supplier-context.store'), ['context' => 'nonexistent'])
            ->assertSessionHasErrors('context');
    }

    public function test_direct_unauthorized_route_access_remains_blocked(): void
    {
        $localSupplier = $this->supplierWithScopes(['local']);
        $importSupplier = $this->supplierWithScopes(['import']);

        $this->actingAs($localSupplier)
            ->get(route('supplier.dashboard'))
            ->assertForbidden();

        $this->actingAs($importSupplier)
            ->get(route('local-supplier.dashboard'))
            ->assertForbidden();
    }

    public function test_inactive_supplier_cannot_use_switcher(): void
    {
        $inactiveSupplier = $this->supplierWithScopes(['import', 'local'], ['is_active' => false]);

        $this->actingAs($inactiveSupplier)
            ->post(route('supplier-context.store'), ['context' => 'local']);

        // Inactive user will either be terminated by EnforceAuthSessionSecurity or rejected
        $this->assertNotSame('local', session('supplier_context'));
    }

    public function test_zero_scope_supplier_does_not_enter_redirect_loop(): void
    {
        $zeroScopeSupplier = $this->supplierWithScopes([]);

        // Landing on /dashboard goes to supplier-context.index
        $this->actingAs($zeroScopeSupplier)
            ->get(route('dashboard'))
            ->assertRedirect(route('supplier-context.index', absolute: false));

        // Visiting supplier-context.index renders safe empty-state message (no infinite redirect)
        $this->actingAs($zeroScopeSupplier)
            ->get(route('supplier-context.index'))
            ->assertOk()
            ->assertSee('Akses supplier belum dikonfigurasi');

        // Trying to switch fails with 403
        $this->actingAs($zeroScopeSupplier)
            ->post(route('supplier-context.store'), ['context' => 'local'])
            ->assertForbidden();

        $this->actingAs($zeroScopeSupplier)
            ->post(route('supplier-context.store'), ['context' => 'import'])
            ->assertForbidden();
    }

    // ─── 3. Scope Change / Session Invalidation ───

    public function test_admin_modifying_supplier_scope_increments_session_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = $this->supplierWithScopes(['import', 'local']);
        $initialSessionVersion = (int) $supplier->auth_session_version;

        $response = $this->actingAs($admin)->put(route('admin.users.update', $supplier), [
            'name' => $supplier->name,
            'email' => $supplier->email,
            'role' => 'supplier',
            'is_active' => '1',
            'company_name' => 'Vendor Test',
            'address' => 'Jakarta',
            'phone' => '123',
            'npwp' => '123',
            'category' => 'Parts',
            'supplier_scopes' => ['import'],
        ]);

        $response->assertRedirect(route('admin.users.index'));

        $supplier->refresh();
        $this->assertSame($initialSessionVersion + 1, (int) $supplier->auth_session_version);
        $this->assertSame(['import'], $supplier->supplierScopes()->pluck('scope')->all());
    }

    public function test_scope_revocation_terminates_active_session_via_session_version(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $supplier = $this->supplierWithScopes(['import', 'local']);

        // Simulate an active authenticated session for supplier with current session version
        $currentVersion = (int) $supplier->auth_session_version;

        // Admin revokes 'local' scope
        $this->actingAs($admin)->put(route('admin.users.update', $supplier), [
            'name' => $supplier->name,
            'email' => $supplier->email,
            'role' => 'supplier',
            'is_active' => '1',
            'company_name' => 'Vendor Test',
            'address' => 'Jakarta',
            'phone' => '123',
            'npwp' => '123',
            'category' => 'Parts',
            'supplier_scopes' => ['import'],
        ]);

        $supplier->refresh();
        $this->assertSame($currentVersion + 1, (int) $supplier->auth_session_version);

        // Supplier's existing session still has the older session version in session storage
        $this->actingAs($supplier)
            ->withSession([
                'auth_session_version' => $currentVersion,
                'auth_absolute_started_at' => now()->timestamp,
                'supplier_context' => 'local',
            ])
            ->get(route('supplier.dashboard'))
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your session has ended. Please sign in again.');

        $this->assertGuest();
    }

    public function test_re_authentication_resolves_only_remaining_authorized_scope(): void
    {
        $supplier = $this->supplierWithScopes(['import']);

        // After re-logging in with single scope 'import'
        $this->actingAs($supplier)
            ->withSession(['auth_session_version' => (int) $supplier->auth_session_version])
            ->get(route('dashboard'))
            ->assertRedirect(route('supplier.dashboard', absolute: false));

        // Direct local route access is rejected
        $this->actingAs($supplier)
            ->get(route('local-supplier.dashboard'))
            ->assertForbidden();
    }

    // ─── 4. Shared-Page & Multi-Tab Behavior ───

    public function test_route_specific_pages_use_authoritative_route_context(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);

        // On local invoice index, route context is authoritative
        $this->actingAs($supplier)
            ->withSession(['supplier_context' => 'import'])
            ->get(route('local-supplier.invoices.index'))
            ->assertOk();

        // On quotation index, route context is authoritative
        $this->actingAs($supplier)
            ->withSession(['supplier_context' => 'local'])
            ->get(route('supplier.quotations.index'))
            ->assertOk();
    }

    public function test_shared_page_does_not_grant_unauthorized_scope_from_session(): void
    {
        $importSupplier = $this->supplierWithScopes(['import']);

        // Forged session context on shared profile page
        $this->actingAs($importSupplier)
            ->withSession(['supplier_context' => 'local'])
            ->get(route('profile.edit'))
            ->assertOk();

        // PortalContext::current() for single-scope import supplier must still resolve to 'import'
        $this->assertSame('import', PortalContext::current($importSupplier));
        $this->assertFalse(PortalContext::isLocal($importSupplier));
    }

    public function test_shared_page_with_invalid_session_context_on_dual_scope_resolves_safely(): void
    {
        $dualSupplier = $this->supplierWithScopes(['import', 'local']);

        // Tampered session context value
        $this->actingAs($dualSupplier)
            ->withSession(['supplier_context' => 'unauthorized_scope'])
            ->get(route('profile.edit'))
            ->assertOk();

        $this->assertNull(PortalContext::current($dualSupplier));
        $this->assertFalse(PortalContext::isLocal($dualSupplier));
    }

    // ─── 5. UI Rendering ───

    public function test_dual_scope_supplier_renders_header_switcher_with_human_facing_names(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);

        $response = $this->actingAs($supplier)
            ->withSession(['supplier_context' => 'local'])
            ->get(route('local-supplier.dashboard'));

        $response->assertOk();
        $response->assertSee('portalContextSwitcherDropdown');
        $response->assertSee('Local Supplier');
        $response->assertSee('Material Procurement');
        $response->assertSee('Invoice, Vendor Profile');
        $response->assertSee('Quotation, PO, Shipment');
        $response->assertDontSee('Import Supplier Portal');
    }

    public function test_single_scope_supplier_does_not_render_switcher(): void
    {
        $localSupplier = $this->supplierWithScopes(['local']);
        $importSupplier = $this->supplierWithScopes(['import']);

        $this->actingAs($localSupplier)
            ->get(route('local-supplier.dashboard'))
            ->assertOk()
            ->assertDontSee('portalContextSwitcherDropdown');

        $this->actingAs($importSupplier)
            ->get(route('supplier.dashboard'))
            ->assertOk()
            ->assertDontSee('portalContextSwitcherDropdown');
    }

    public function test_fallback_context_page_renders_human_facing_names_and_active_state(): void
    {
        $supplier = $this->supplierWithScopes(['import', 'local']);

        $response = $this->actingAs($supplier)
            ->withSession(['supplier_context' => 'local'])
            ->get(route('supplier-context.index'));

        $response->assertOk();
        $response->assertSee('Material Procurement');
        $response->assertSee('Local Supplier');
        $response->assertSee('Quotation, PO, Shipment');
        $response->assertSee('Invoice, Vendor Profile');
        $response->assertSee('Aktif');
        $response->assertDontSee('Pengadaan Impor');
        $response->assertDontSee('Invoice Lokal');
    }
}
