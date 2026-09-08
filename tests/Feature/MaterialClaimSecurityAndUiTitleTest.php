<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaterialClaimSecurityAndUiTitleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $purchasing;
    private User $supplier;
    private User $qc;
    private PurchaseOrder $po;
    private MaterialClaim $claim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $period = Period::create([
            'name' => 'Period Claim Security Test',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->admin->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/TEST/099',
            'notes' => 'Test PR for claim security',
            'status' => 'completed',
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Steel Coil',
            'shape' => 'Coil',
            'thickness' => 3.0,
            'width' => 1200,
            'length' => 2400,
            'weight_needed' => 10000,
        ]);

        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now(),
            'created_by' => $this->admin->id,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
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
            'price_per_kg' => 2.0,
            'amount' => 20000,
        ]);

        $this->po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/09/2026/011',
            'status' => 'completed',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(30),
            'actual_arrival' => now()->addDays(35),
        ]);

        $this->po->quotations()->attach($quotation->id);

        $inspection = QcInspection::create([
            'po_id' => $this->po->id,
            'inspected_by' => $this->qc->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);

        $this->claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $this->po->id,
            'submitted_by' => $this->purchasing->id,
            'supplier_id' => $this->supplier->id,
            'status' => 'pending',
            'description' => 'Dimensional discrepancy in thickness',
            'resolution_expected' => 'Replace defective coils',
            'deadline' => now()->addDays(14),
        ]);
    }

    public function test_material_claim_model_has_obfuscated_claim_number_accessor(): void
    {
        $expectedNumber = 'CLM-'.strtoupper($this->claim->hash);

        $this->assertNotEmpty($this->claim->hash);
        $this->assertEquals($expectedNumber, $this->claim->claim_number);
    }

    public function test_supplier_claim_show_does_not_leak_raw_database_id_in_title_or_content(): void
    {
        $rawIdString = '#'.$this->claim->id;

        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.claims.show', $this->claim));

        $response->assertOk();

        // Must NOT leak raw database ID in browser title or page headers
        $response->assertDontSee('Claim Details '.$rawIdString);
        $response->assertDontSee('Claim '.$rawIdString);

        // Must display PO number in title and header
        $response->assertSee('Claim Details: '.$this->po->po_number);
        $response->assertSee('Material Claim: '.$this->po->po_number);
    }

    public function test_purchasing_claim_show_does_not_leak_raw_database_id_in_title_or_content(): void
    {
        $rawIdString = '#'.$this->claim->id;

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.claims.show', $this->claim));

        $response->assertOk();

        // Must NOT leak raw database ID in browser title, header, or card titles
        $response->assertDontSee('Claim Details '.$rawIdString);
        $response->assertDontSee('Claim '.$rawIdString);
        $response->assertDontSee('Claim Particulars '.$rawIdString);

        // Must display business reference
        $response->assertSee('Claim Details: '.$this->po->po_number);
        $response->assertSee('Material Claim: '.$this->po->po_number);
        $response->assertSee('Claim Particulars');
    }

    public function test_supplier_claim_datatables_uses_claim_number_instead_of_raw_database_id(): void
    {
        $response = $this->actingAs($this->supplier)
            ->getJson(route('supplier.claims.index'), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $firstRow = $data[0];
        $this->assertEquals($this->claim->claim_number, $firstRow['claim_id']);
        $this->assertStringNotContainsString('#'.$this->claim->id, $firstRow['claim_id']);
    }

    public function test_purchasing_claim_datatables_history_uses_claim_number_instead_of_raw_database_id(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->getJson(route('purchasing.claims.data-history'), ['X-Requested-With' => 'XMLHttpRequest']);

        $response->assertOk();

        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $firstRow = $data[0];
        $this->assertEquals($this->claim->claim_number, $firstRow['claim_id']);
        $this->assertStringNotContainsString('#'.$this->claim->id, $firstRow['claim_id']);
    }
}
