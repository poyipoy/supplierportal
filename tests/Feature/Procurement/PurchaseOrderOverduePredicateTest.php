<?php

namespace Tests\Feature\Procurement;

use App\Exports\PurchaseOrdersExport;
use App\Http\Requests\Export\Filters\PurchaseOrderExportFilters;
use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Gate 2 PUR-08 (§2.1 truth table): the is_overdue accessor and every SQL copy select the same
 * POs; partial shipment-based arrival no longer hides lateness; legacy-only arrival keeps its
 * meaning; waiting_qc / claim_needed are never masked.
 */
class PurchaseOrderOverduePredicateTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private QuotationItem $line;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-28 10:00:00');
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplier = User::factory()->create(['role' => 'supplier']);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]);
        $period = Period::create(['name' => 'Overdue', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/OVD/1', 'status' => 'bidding']);
        $item = PrItem::create(['pr_id' => $pr->id, 'material_name' => 'SCM440', 'quantity' => 10, 'shape' => 'Flat', 'thickness' => 10, 'width' => 100, 'length' => 200, 'weight_needed' => 5]);
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'status' => 'accepted', 'submitted_at' => now()]);
        $this->line = $quotation->items()->create(['pr_item_id' => $item->id, 'is_available' => true, 'price_per_kg' => 2, 'amount' => 100, 'available_qty' => 10]);
    }

    public function test_php_and_every_sql_copy_select_the_same_overdue_purchase_orders(): void
    {
        $past = now()->subDays(3)->toDateString();
        $expected = [
            'active_no_arrival_eta_past' => [$this->po('active', $past, null), true],
            'active_partial_shipment_arrival_eta_past' => [$this->po('active', $past, $past, shipment: true), true],
            'legacy_only_arrival_eta_past' => [$this->po('active', $past, $past), false],
            'waiting_qc_eta_past' => [$this->po('waiting_qc', $past, $past, shipment: true), false],
            'claim_needed_eta_past' => [$this->po('claim_needed', $past, $past, shipment: true), false],
            'completed' => [$this->po('completed', $past, $past, shipment: true), false],
            'cancelled' => [$this->po('cancelled', $past, null), false],
            'status_overdue' => [$this->po('overdue', $past, null), true],
            'active_eta_future' => [$this->po('active', now()->addDay()->toDateString(), null), false],
            'active_eta_today' => [$this->po('active', now()->toDateString(), null), false],
            'active_no_eta' => [$this->po('active', null, null), false],
        ];

        foreach ($expected as $case => [$po, $overdue]) {
            $this->assertSame($overdue, $po->fresh()->is_overdue, $case.' (accessor, lazy)');
        }
        $expectedIds = collect($expected)->filter(fn ($row) => $row[1])->map(fn ($row) => $row[0]->id)->sort()->values()->all();

        // The list path reads the withExists flag: no per-row overdue query.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $listed = PurchaseOrder::query()->withExists('shipmentItems as has_shipment_items')->get();
        $phpIds = $listed->filter->is_overdue->pluck('id')->sort()->values()->all();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame(1, $queries, 'is_overdue on a withExists list adds no query.');

        $filtered = PurchaseOrder::query();
        PurchaseOrderExportFilters::apply($filtered, ['status' => 'overdue']);
        $filterIds = $filtered->pluck('id')->sort()->values()->all();
        $exportIds = (new PurchaseOrdersExport(status: 'overdue'))->query()->pluck('purchase_orders.id')->sort()->values()->all();

        $this->assertSame($expectedIds, $phpIds);
        $this->assertSame($expectedIds, $filterIds, 'Index/advanced filter');
        $this->assertSame($expectedIds, $exportIds, 'Legacy export');
    }

    private function po(string $status, ?string $eta, ?string $arrival, bool $shipment = false): PurchaseOrder
    {
        $this->sequence++;
        $po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'currency' => 'USD', 'po_number' => 'PO/OVD/'.$this->sequence, 'status' => $status, 'created_by' => $this->purchasing->id, 'estimated_arrival' => $eta, 'actual_arrival' => $arrival]);
        if ($shipment) {
            $record = Shipment::create(['shipment_number' => 'SHP/OVD/'.$this->sequence, 'supplier_id' => $this->supplier->id, 'status' => Shipment::STATUS_ARRIVED, 'created_by' => $this->supplier->id]);
            ShipmentItem::create(['shipment_id' => $record->id, 'purchase_order_id' => $po->id, 'quotation_item_id' => $this->line->id, 'shipped_qty' => 4, 'actual_weight_kg' => 20]);
        }

        return $po;
    }
}
