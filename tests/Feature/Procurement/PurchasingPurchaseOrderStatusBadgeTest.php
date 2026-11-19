<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingPurchaseOrderStatusBadgeTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private Period $period;

    private ExchangeRate $rate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $this->period = Period::create([
            'name' => 'Badge Period',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $this->rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 15000,
            'valid_from' => '2026-09-01',
            'created_by' => $this->purchasing->id,
        ]);
    }

    private function createPoWithItem(string $poNumber, string $status = 'active', ?string $eta = null): array
    {
        $pr = PurchaseRequisition::create([
            'pr_number' => 'REQ/BADGE/'.substr($poNumber, -3),
            'period_id' => $this->period->id,
            'status' => 'submitted',
            'created_by' => $this->purchasing->id,
        ]);
        $prItem = $pr->items()->create([
            'material_name' => 'Steel Rod',
            'quantity_value' => 100,
            'quantity_unit' => 'pcs',
        ]);
        $quotation = Quotation::create([
            'supplier_id' => $this->supplier->id,
            'pr_id' => $pr->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'status' => 'approved',
        ]);
        $quotationItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'pr_item_id' => $prItem->id,
            'offered_price' => 10,
            'amount' => 1000,
            'is_available' => true,
        ]);
        $po = PurchaseOrder::create([
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->rate->id,
            'po_number' => $poNumber,
            'status' => $status,
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => $eta ?? BusinessTime::today()->addDays(7)->toDateString(),
            'notes' => 'Status badge test',
        ]);
        $po->quotations()->attach($quotation->id);

        return [$po, $quotationItem];
    }

    public function test_purchasing_po_index_renders_active_not_yet_shipped_badge(): void
    {
        [$po] = $this->createPoWithItem('PO/09/2026/801', 'active');

        $response = $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.purchase-orders.index'))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('po_number_display', $po->po_number);
        $this->assertNotNull($row);
        $this->assertStringContainsString('ui-status-chip--warning', $row['status_badge']);
        $this->assertStringContainsString(__('purchasing.copy.active_no_shipment'), $row['status_badge']);
    }

    public function test_purchasing_po_index_renders_active_shipped_badge_when_active_shipment_exists(): void
    {
        [$po, $quotationItem] = $this->createPoWithItem('PO/09/2026/802', 'active');

        $shipment = Shipment::create([
            'supplier_id' => $this->supplier->id,
            'shipment_number' => 'SHP/09/2026/802',
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => BusinessTime::today()->toDateString(),
            'estimated_arrival_date' => BusinessTime::today()->addDays(5)->toDateString(),
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $quotationItem->id,
            'shipped_qty' => 50,
            'actual_weight_kg' => 25.0,
        ]);

        $response = $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.purchase-orders.index'))
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('po_number_display', $po->po_number);
        $this->assertNotNull($row);
        $this->assertStringContainsString('ui-status-chip--info', $row['status_badge']);
        $this->assertStringContainsString(__('purchasing.copy.active_has_shipment'), $row['status_badge']);
    }

    public function test_purchasing_po_index_renders_waiting_qc_and_claim_needed_badges(): void
    {
        [$poQc] = $this->createPoWithItem('PO/09/2026/803', 'waiting_qc');
        [$poClaim] = $this->createPoWithItem('PO/09/2026/804', 'claim_needed');

        $response = $this->actingAs($this->purchasing)
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson(route('purchasing.purchase-orders.index'))
            ->assertOk();

        $rowQc = collect($response->json('data'))->firstWhere('po_number_display', $poQc->po_number);
        $this->assertNotNull($rowQc);
        $this->assertStringContainsString('ui-status-chip--warning', $rowQc['status_badge']);
        $this->assertStringContainsString(__('purchasing.copy.waiting_qc'), $rowQc['status_badge']);

        $rowClaim = collect($response->json('data'))->firstWhere('po_number_display', $poClaim->po_number);
        $this->assertNotNull($rowClaim);
        $this->assertStringContainsString('ui-status-chip--error', $rowClaim['status_badge']);
        $this->assertStringContainsString(__('purchasing.copy.claim_needed'), $rowClaim['status_badge']);
    }

    public function test_purchasing_po_show_renders_detailed_status_badge(): void
    {
        [$poUnshipped] = $this->createPoWithItem('PO/09/2026/805', 'active');
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $poUnshipped))
            ->assertOk()
            ->assertSee(__('purchasing.copy.active_no_shipment'))
            ->assertSee('ui-status-chip--warning', false);

        [$poShipped, $quotationItem] = $this->createPoWithItem('PO/09/2026/806', 'active');
        $shipment = Shipment::create([
            'supplier_id' => $this->supplier->id,
            'shipment_number' => 'SHP/09/2026/806',
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => BusinessTime::today()->toDateString(),
            'estimated_arrival_date' => BusinessTime::today()->addDays(5)->toDateString(),
        ]);
        ShipmentItem::create([
            'shipment_id' => $shipment->id,
            'purchase_order_id' => $poShipped->id,
            'quotation_item_id' => $quotationItem->id,
            'shipped_qty' => 50,
            'actual_weight_kg' => 25.0,
        ]);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $poShipped))
            ->assertOk()
            ->assertSee(__('purchasing.copy.active_has_shipment'))
            ->assertSee('ui-status-chip--info', false);
    }

    public function test_status_helper_po_status_chip_parity_between_purchasing_and_supplier(): void
    {
        $chipShipped = StatusHelper::poStatusChip('active', false, true);
        $chipNotShipped = StatusHelper::poStatusChip('active', false, false);
        $chipOverdue = StatusHelper::poStatusChip('active', true, true);
        $chipWaitingQc = StatusHelper::poStatusChip('waiting_qc', false);
        $chipClaim = StatusHelper::poStatusChip('claim_needed', false);
        $chipCompleted = StatusHelper::poStatusChip('completed', false);

        $this->assertStringContainsString('ui-status-chip--info', $chipShipped);
        $this->assertStringContainsString(__('purchasing.copy.active_has_shipment'), $chipShipped);

        $this->assertStringContainsString('ui-status-chip--warning', $chipNotShipped);
        $this->assertStringContainsString(__('purchasing.copy.active_no_shipment'), $chipNotShipped);

        $this->assertStringContainsString('ui-status-chip--error', $chipOverdue);
        $this->assertStringContainsString(__('purchasing.copy.overdue'), $chipOverdue);

        $this->assertStringContainsString('ui-status-chip--warning', $chipWaitingQc);
        $this->assertStringContainsString(__('purchasing.copy.waiting_qc'), $chipWaitingQc);

        $this->assertStringContainsString('ui-status-chip--error', $chipClaim);
        $this->assertStringContainsString(__('purchasing.copy.claim_needed'), $chipClaim);

        $this->assertStringContainsString('ui-status-chip--success', $chipCompleted);
        $this->assertStringContainsString(__('purchasing.copy.completed'), $chipCompleted);
    }
}
