<?php

namespace Tests\Feature;

use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GEN-01 (Gate 2): Local amounts were hard-coded with Indonesian separators. They now go through
 * RegionalDisplayFormatter::money(), which is byte-identical for 'system' and 'indonesian' and
 * follows an explicit 'international' preference. Prefix, spacing and decimals are unchanged.
 */
class PriceGroupingLocalSurfacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_local_po_amounts_keep_system_output_and_follow_explicit_preferences(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $supplier = $this->localSupplier('PT Grouping');
        $other = $this->localSupplier('PT Other');

        $masters = app(LocalProcurementMasterService::class);
        $masters->createPurchaseOrder($finance, ['supplier_id' => $supplier->id, 'po_number' => 'PO-GRP-1', 'po_date' => '2026-09-10', 'total_amount' => '648193716.98']);
        $masters->createPurchaseOrder($finance, ['supplier_id' => $other->id, 'po_number' => 'PO-GRP-2', 'po_date' => '2026-09-10', 'total_amount' => '777777.77']);

        foreach ([[$finance, 'finance.local-procurement.index'], [$supplier, 'local-supplier.purchase-orders.index']] as [$user, $route]) {
            $this->app->forgetScopedInstances(); // a new user's request resolves its own formatter
            $system = $this->html($this->actingAs($user)->get(route($route))->assertOk()->getContent());
            $this->assertStringContainsString('Rp 648.193.716,98', $system, $route.' system output is unchanged');

            $this->preference($user, 'indonesian');
            $this->assertStringContainsString('Rp 648.193.716,98', $this->html($this->get(route($route))->assertOk()->getContent()));

            $user->preference()->update(['number_format' => 'international']);
            $this->app->forgetScopedInstances();
            $international = $this->html($this->get(route($route))->assertOk()->getContent());
            $this->assertStringContainsString('Rp 648,193,716.98', $international, $route.' follows the international preference');
            $this->assertStringNotContainsString('Rp 648.193.716,98', $international);
        }

        $this->assertStringNotContainsString('PO-GRP-2', $this->actingAs($supplier)->get(route('local-supplier.purchase-orders.index'))->getContent());
    }

    private function html(string $content): string
    {
        return preg_replace('/\s+/u', ' ', $content);
    }

    private function localSupplier(string $name): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => User::ACCOUNT_STATUS_ACTIVE]);
        SupplierScope::firstOrCreate(['supplier_id' => $user->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $user->id, 'company_name' => $name, 'category' => 'Parts', 'is_pkp' => true, 'payment_term_days' => 30]);

        return $user;
    }

    private function preference(User $user, string $number): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'number_format' => $number]);
        $this->app->forgetScopedInstances();
    }
}
