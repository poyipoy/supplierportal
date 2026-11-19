<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutstandingMaterialsColumnHeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'Supplier Co',
            'address' => 'Jakarta',
            'phone' => '08123456789',
            'npwp' => '01.234.567.8-901.000',
            'category' => 'Raw Material',
            'payment_term_days' => 30,
        ]);

        $period = Period::create([
            'name' => 'Test Period',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 15000,
            'valid_from' => '2026-09-01',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'pr_number' => 'REQ/OUT/001',
            'period_id' => $period->id,
            'status' => 'submitted',
            'created_by' => $this->purchasing->id,
        ]);
        $prItem = $pr->items()->create([
            'material_name' => 'Steel Plate',
            'quantity_value' => 50,
            'quantity_unit' => 'pcs',
        ]);
        $quotation = Quotation::create([
            'supplier_id' => $this->supplier->id,
            'pr_id' => $pr->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'status' => 'approved',
        ]);
        QuotationItem::create([
            'quotation_id' => $quotation->id,
            'pr_item_id' => $prItem->id,
            'offered_price' => 20,
            'amount' => 1000,
            'is_available' => true,
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $rate->id,
            'po_number' => 'PO/09/2026/999',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->addDays(10)->toDateString(),
        ]);
        $po->quotations()->attach($quotation->id);
    }

    public function test_purchasing_outstanding_materials_table_renders_port_of_discharge_column(): void
    {
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.outstanding-materials.index'));

        $response->assertOk();
        $response->assertSeeText('Port of Discharge');
        $response->assertDontSeeText('Destination Port');
    }

    public function test_supplier_outstanding_materials_table_renders_port_of_discharge_column(): void
    {
        $response = $this->actingAs($this->supplier)->get(route('supplier.outstanding-materials.index'));

        $response->assertOk();
        $response->assertSeeText('Port of Discharge');
        $response->assertDontSeeText('Destination Port');
    }

    public function test_outstanding_materials_table_renders_indonesian_pelabuhan_awal_dan_tujuan(): void
    {
        $this->purchasing->preference()->create([...config('user_preferences.defaults'), 'locale' => 'id']);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.outstanding-materials.index'));

        $response->assertOk();
        $response->assertSeeText('Referensi Invoice');
        $response->assertSeeText('Pelabuhan Awal');
        $response->assertSeeText('Pelabuhan Tujuan');
        $response->assertDontSeeText('Pelabuhan Muat');
        $response->assertDontSeeText('Pelabuhan Bongkar');
    }

    public function test_outstanding_materials_renders_invoice_reference_column_and_overdue_status_and_filter(): void
    {
        $po = PurchaseOrder::where('po_number', 'PO/09/2026/999')->firstOrFail();
        $quotationItem = $po->quotations()->first()->items()->first();

        $shipment = Shipment::create([
            'shipment_number' => 'SHP/OUT/001',
            'invoice_number' => 'INV-OUT-2026-01',
            'supplier_reference' => 'SUP-REF-77',
            'supplier_id' => $this->supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => BusinessTime::today()->toDateString(),
            'estimated_arrival_date' => BusinessTime::today()->addDays(5)->toDateString(),
        ]);
        $shipment->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $quotationItem->id,
            'shipped_qty' => 10,
            'actual_weight_kg' => 100,
        ]);

        // Add an overdue PO
        $overduePr = PurchaseRequisition::create([
            'pr_number' => 'REQ/OUT/OVERDUE',
            'period_id' => Period::first()->id,
            'status' => 'submitted',
            'created_by' => $this->purchasing->id,
        ]);
        $overduePrItem = $overduePr->items()->create([
            'material_name' => 'Overdue Alloy',
            'quantity_value' => 25,
            'quantity_unit' => 'pcs',
        ]);
        $overdueQuotation = Quotation::create([
            'supplier_id' => $this->supplier->id,
            'pr_id' => $overduePr->id,
            'currency' => 'USD',
            'exchange_rate_id' => ExchangeRate::first()->id,
            'status' => 'approved',
        ]);
        QuotationItem::create([
            'quotation_id' => $overdueQuotation->id,
            'pr_item_id' => $overduePrItem->id,
            'offered_price' => 30,
            'amount' => 750,
            'is_available' => true,
        ]);
        $overduePo = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => ExchangeRate::first()->id,
            'po_number' => 'PO/09/2026/OVR',
            'status' => 'overdue',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => BusinessTime::today()->subDays(5)->toDateString(),
        ]);
        $overduePo->quotations()->attach($overdueQuotation->id);

        // Unfiltered view renders Invoice Reference header right after Purchase Order and shows Overdue chip
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.outstanding-materials.index'));
        $response->assertOk();
        $html = $response->getContent();
        $this->assertMatchesRegularExpression('/<th[^>]*>Purchase Order<\/th>\s*<th[^>]*>Invoice Reference<\/th>/s', $html);
        $response->assertSeeText('INV-OUT-2026-01');
        $response->assertSeeText('Ref: SUP-REF-77');
        $response->assertSeeText('Overdue Alloy');
        $response->assertSeeText('Overdue');

        // Filtering by status=overdue returns only overdue rows
        $overdueOnly = $this->actingAs($this->purchasing)
            ->get(route('purchasing.outstanding-materials.index', ['status' => 'overdue']));
        $overdueOnly->assertOk();
        $overdueOnly->assertSeeText('PO/09/2026/OVR');
        $overdueOnly->assertDontSeeText('PO/09/2026/999');

        // Searching by invoice number returns matching PO
        $invoiceSearch = $this->actingAs($this->supplier)
            ->get(route('supplier.outstanding-materials.index', ['search' => 'INV-OUT-2026-01']));
        $invoiceSearch->assertOk();
        $invoiceSearch->assertSeeText('PO/09/2026/999');
        $invoiceSearch->assertDontSeeText('PO/09/2026/OVR');
    }
}
