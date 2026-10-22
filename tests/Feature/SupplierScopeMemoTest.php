<?php

namespace Tests\Feature;

use App\Models\SupplierScope;
use App\Models\User;
use App\Support\PortalContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Supplier scope checks are memoized per User instance. They must stay exact
 * for dual-scope suppliers, follow grants and revocations once the instance is
 * refreshed or re-resolved, and never leak into a new request's instance.
 */
class SupplierScopeMemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_dual_scope_supplier_is_resolved_with_one_query(): void
    {
        $supplier = $this->supplier(['import', 'local']);
        $fresh = User::findOrFail($supplier->id);

        DB::enableQueryLog();
        $this->assertTrue($fresh->hasSupplierScope('import'));
        $this->assertTrue($fresh->hasSupplierScope('local'));
        $this->assertTrue(PortalContext::isDualScope($fresh));
        $this->assertTrue($fresh->isImportEligible());
        $this->assertTrue($fresh->isLocalEligible());
        $this->assertFalse($fresh->hasSupplierScope('other'));

        $this->assertCount(1, DB::getQueryLog());
    }

    public function test_grant_and_revocation_are_visible_after_refresh_or_a_new_instance(): void
    {
        $supplier = $this->supplier(['import']);
        $this->assertFalse($supplier->hasSupplierScope('local'));

        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);
        $this->assertTrue(User::findOrFail($supplier->id)->hasSupplierScope('local'), 'A new request resolves a new instance.');
        $this->assertTrue($supplier->refresh()->hasSupplierScope('local'));

        SupplierScope::where('supplier_id', $supplier->id)->where('scope', 'import')->delete();
        $this->assertFalse(User::findOrFail($supplier->id)->hasSupplierScope('import'));
        $this->assertFalse($supplier->forgetSupplierScopes()->hasSupplierScope('import'));
        $this->assertSame(['local'], $supplier->supplierScopeValues());
    }

    public function test_non_suppliers_never_have_a_scope_and_do_not_query(): void
    {
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $purchasing->id, 'scope' => 'import']);
        $fresh = User::findOrFail($purchasing->id);

        DB::enableQueryLog();
        $this->assertFalse($fresh->hasSupplierScope('import'));
        $this->assertCount(0, DB::getQueryLog());
    }

    public function test_scope_middleware_follows_a_revocation_on_the_next_request(): void
    {
        $supplier = $this->supplier(['import', 'local']);
        $this->actingAs(User::findOrFail($supplier->id))->get(route('local-supplier.dashboard'))->assertOk();

        SupplierScope::where('supplier_id', $supplier->id)->where('scope', 'local')->delete();

        // Each real request authenticates a freshly loaded User.
        $this->actingAs(User::findOrFail($supplier->id))->get(route('local-supplier.dashboard'))->assertForbidden();
    }

    public function test_memo_never_outlives_a_request_even_for_the_same_instance(): void
    {
        $supplier = $this->supplier(['import', 'local']);
        $this->actingAs($supplier)->get(route('local-supplier.dashboard'))->assertOk();

        SupplierScope::where('supplier_id', $supplier->id)->where('scope', 'local')->delete();

        $this->actingAs($supplier)->get(route('local-supplier.dashboard'))->assertForbidden();
    }

    private function supplier(array $scopes): User
    {
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        // Supplier creation may already assign a default scope; set the exact list.
        SupplierScope::where('supplier_id', $supplier->id)->whereNotIn('scope', $scopes)->delete();
        foreach ($scopes as $scope) {
            SupplierScope::firstOrCreate(['supplier_id' => $supplier->id, 'scope' => $scope]);
        }

        return $supplier->forgetSupplierScopes();
    }
}
