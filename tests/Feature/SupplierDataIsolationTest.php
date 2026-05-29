<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequirement;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Test suite untuk memastikan isolasi data antar supplier.
 *
 * Aturan AGENTS.md: "Supplier tidak boleh melihat atau mengubah
 * data supplier lain. Setiap query yang melibatkan data supplier
 * wajib difilter: ->where('supplier_id', auth()->id())"
 */
class SupplierDataIsolationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierA;

    private User $supplierB;

    private User $purchasing;

    private User $qc;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create users for all required roles
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    /**
     * Helper: Create a full data chain (Period → PR → Quotation → PO → QC → Claim) for a given supplier.
     */
    private function createFullDataChainFor(User $supplier): array
    {
        $period = Period::create([
            'name' => 'Test Period ' . $supplier->id,
            'month' => 5,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $pr = PurchaseRequirement::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/TEST/' . str_pad($supplier->id, 3, '0', STR_PAD_LEFT),
            'notes' => 'Test PR for supplier ' . $supplier->id,
            'status' => 'completed',
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Test Material',
            'shape' => 'Flat',
            'thickness' => 2.0,
            'width' => 1219,
            'length' => 2438,
            'weight_needed' => 5000,
        ]);

        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now(),
            'created_by' => $this->admin->id,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => 'accepted',
            'submitted_at' => now(),
            'estimated_delivery' => now()->addDays(30),
            'payment_terms' => 'Net 30',
            'validity_period' => now()->addDays(14),
        ]);

        $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 1.85,
            'amount' => 1.85 * 5000,
        ]);

        $po = PurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/TEST/' . str_pad($supplier->id, 3, '0', STR_PAD_LEFT),
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(30),
            'actual_arrival' => now()->addDays(35),
        ]);

        // Attach quotation via pivot (new many-to-many schema)
        $po->quotations()->attach($quotation->id);

        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);

        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $supplier->id,
            'status' => 'pending',
            'description' => 'Test claim',
            'deadline' => now()->addDays(14),
        ]);

        return compact('period', 'pr', 'prItem', 'quotation', 'po', 'inspection', 'claim');
    }

    // ─────────────────────────────────────────────────
    //  QUOTATION ISOLATION
    // ─────────────────────────────────────────────────

    public function test_supplier_can_view_own_quotation(): void
    {
        $dataA = $this->createFullDataChainFor($this->supplierA);

        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.quotations.show', $dataA['quotation']->id));

        $response->assertStatus(200);
    }

    public function test_supplier_cannot_view_other_supplier_quotation(): void
    {
        $dataB = $this->createFullDataChainFor($this->supplierB);

        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.quotations.show', $dataB['quotation']->id));

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────
    //  PURCHASE ORDER ISOLATION
    // ─────────────────────────────────────────────────

    public function test_supplier_can_view_own_purchase_order(): void
    {
        $dataA = $this->createFullDataChainFor($this->supplierA);

        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.purchase-orders.show', $dataA['po']->id));

        $response->assertStatus(200);
    }

    public function test_supplier_cannot_view_other_supplier_purchase_order(): void
    {
        $dataB = $this->createFullDataChainFor($this->supplierB);

        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.purchase-orders.show', $dataB['po']->id));

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────
    //  MATERIAL CLAIM ISOLATION
    // ─────────────────────────────────────────────────

    public function test_supplier_can_view_own_claim(): void
    {
        $dataA = $this->createFullDataChainFor($this->supplierA);

        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.claims.show', $dataA['claim']->id));

        $response->assertStatus(200);
    }

    public function test_supplier_cannot_view_other_supplier_claim(): void
    {
        $dataB = $this->createFullDataChainFor($this->supplierB);

        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.claims.show', $dataB['claim']->id));

        $response->assertStatus(403);
    }

    // ─────────────────────────────────────────────────
    //  CROSS-ROLE PROTECTION
    // ─────────────────────────────────────────────────

    public function test_supplier_cannot_access_purchasing_dashboard(): void
    {
        $response = $this->actingAs($this->supplierA)
            ->get(route('purchasing.dashboard'));

        $response->assertStatus(403);
    }

    public function test_supplier_cannot_access_admin_dashboard(): void
    {
        $response = $this->actingAs($this->supplierA)
            ->get(route('admin.dashboard'));

        $response->assertStatus(403);
    }

    public function test_supplier_cannot_access_qc_dashboard(): void
    {
        $response = $this->actingAs($this->supplierA)
            ->get(route('qc.dashboard'));

        $response->assertStatus(403);
    }
}
