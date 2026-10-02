<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RegionalDataTablesContractTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private Period $period;

    private ExchangeRate $rate;

    private ShipmentService $shipments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purchasing = User::factory()->create(['role' => 'purchasing']);
        $this->supplierA = User::factory()->create(['role' => 'supplier']);
        $this->supplierB = User::factory()->create(['role' => 'supplier']);
        $this->period = Period::create(['name' => 'Shipment Regional', 'month' => 9, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $this->rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 1, 'valid_from' => '2026-09-01', 'created_by' => $this->purchasing->id]);
        $this->shipments = app(ShipmentService::class);
    }

    public function test_system_ajax_keeps_legacy_shipment_contract_and_supplier_scope(): void
    {
        $early = $this->shipment($this->supplierA, 'SH-REG-001', '2026-09-30', '2026-10-03', '2026-10-02', 1250, '1234.5678');
        $late = $this->shipment($this->supplierA, 'SH-REG-002', '2026-10-02', '2026-10-05', null, 25, '0.1250');
        $foreign = $this->shipment($this->supplierB, 'SH-REG-003', '2026-10-01', '2026-10-04', null, 5, '4.2500');
        $queries = $this->watchPreferences();
        $response = $this->shipmentAjax($this->supplierA, 'supplier', ['date_from' => '2026-09-01', 'date_to' => '2026-10-31'])->assertOk()->assertJsonPath('recordsTotal', 2)->assertJsonPath('recordsFiltered', 2);
        $rows = collect($response->json('data'))->keyBy(fn ($row) => strip_tags($row['shipment_number_display']));
        $this->assertSame([$late->shipment_number, $early->shipment_number], array_map('strip_tags', array_column($response->json('data'), 'shipment_number_display')));
        $this->assertSame('<span class="ui-tabular-nums">30 Sep 2026</span>', $rows[$early->shipment_number]['shipment_date']);
        $this->assertSame('<span class="ui-tabular-nums">02 Oct 2026</span>', $rows[$late->shipment_number]['shipment_date']);
        $this->assertSame('1234.57 Kg', strip_tags($rows[$early->shipment_number]['actual_weight']));
        $this->assertSame('1234.57 Kg', strip_tags($rows[$early->shipment_number]['total_weight']));
        $this->assertSame($rows[$early->shipment_number]['shipment_date'], $rows[$early->shipment_number]['shipment_date_display']);
        $this->assertStringNotContainsString($foreign->shipment_number, $response->getContent());
        $this->assertSame(1, $queries->count);
        $this->assertSame('2026-09-30', $early->fresh()->shipment_date->toDateString());
        $this->assertSame('2026-10-02', $early->fresh()->actual_arrival_date->toDateString());
    }

    public function test_regional_ajax_shipment_alias_keeps_requested_sort_filter_and_precision(): void
    {
        $late = $this->shipment($this->supplierA, 'SH-REG-004', '2026-10-02', '2026-10-05', null, 25, '0.1250');
        $early = $this->shipment($this->supplierA, 'SH-REG-005', '2026-09-30', '2026-10-03', '2026-10-02', 1250, '1234.5678');
        $foreign = $this->shipment($this->supplierB, 'SH-REG-006', '2026-10-01', '2026-10-04', null, 5, '4.2500');
        $this->preference($this->supplierA);
        $queries = $this->watchPreferences();
        // Preserve the controller's current latest(shipment_date) SQL order under either client direction.
        $ascending = $this->shipmentAjax($this->supplierA, 'supplier', [
            'date_from' => '2026-09-01', 'date_to' => '2026-10-31', 'order' => [['column' => 5, 'dir' => 'asc']],
        ])->assertOk();
        $this->assertSame(1, $queries->count);
        $rowsAsc = $ascending->json('data');
        $this->assertSame([$late->shipment_number, $early->shipment_number], array_map('strip_tags', array_column($rowsAsc, 'shipment_number_display')));
        $this->assertSame('<span class="ui-tabular-nums">02 Oct 2026</span>', $rowsAsc[0]['shipment_date']);
        $this->assertSame('02/10/2026', strip_tags($rowsAsc[0]['shipment_date_display']));
        $this->assertSame('30/09/2026', strip_tags($rowsAsc[1]['shipment_date_display']));
        $this->assertSame('25 pcs', strip_tags($rowsAsc[0]['total_qty']));
        $this->assertSame('0,13 Kg', strip_tags($rowsAsc[0]['actual_weight']));
        $this->assertSame('0.13 Kg', strip_tags($rowsAsc[0]['total_weight']));
        $this->assertSame('2026-10-02', $early->fresh()->actual_arrival_date->toDateString());
        $this->assertStringNotContainsString($foreign->shipment_number, $ascending->getContent());

        $queries->count = 0;
        $descending = $this->shipmentAjax($this->supplierA, 'supplier', [
            'date_from' => '2026-09-01', 'date_to' => '2026-10-31', 'order' => [['column' => 5, 'dir' => 'desc']],
        ])->assertOk();
        $this->assertLessThanOrEqual(1, $queries->count);
        $this->assertSame([$late->shipment_number, $early->shipment_number], array_map('strip_tags', array_column($descending->json('data'), 'shipment_number_display')));

        $filtered = $this->shipmentAjax($this->supplierA, 'supplier', ['date_from' => '2026-10-01', 'status' => 'draft'])->assertOk();
        $this->assertSame([$late->shipment_number], array_map('strip_tags', array_column($filtered->json('data'), 'shipment_number_display')));
        $this->assertSame('2026-09-30', $early->fresh()->shipment_date->toDateString());
    }

    public function test_purchasing_ajax_and_server_rendered_fallback_share_regional_date_text(): void
    {
        $shipment = $this->shipment($this->supplierA, 'SH-REG-004', '2026-09-30', '2026-10-03', '2026-10-02', 1250, '1234.5678');
        $this->preference($this->purchasing);

        $queries = $this->watchPreferences();
        $response = $this->shipmentAjax($this->purchasing, 'purchasing', [
            'supplier_id' => $this->supplierA->hash,
            'date_from' => '2026-09-30',
            'date_to' => '2026-10-02',
            'order' => [['column' => 6, 'dir' => 'desc']],
        ])->assertOk()->assertJsonPath('recordsFiltered', 1);
        $row = $response->json('data.0');
        $this->assertSame($shipment->shipment_number, strip_tags($row['shipment_number_display']));
        $this->assertSame('<span class="ui-tabular-nums">30 Sep 2026</span>', $row['shipment_date']);
        $this->assertSame('30/09/2026', strip_tags($row['shipment_date_display']));
        $this->assertSame('02/10/2026', strip_tags($row['actual_arrival']));
        $this->assertStringContainsString(route('purchasing.shipments.show', $shipment), $row['action']);
        $this->assertSame(1, $queries->count);

        $queries->count = 0;
        $html = $this->actingAs($this->purchasing)->get(route('purchasing.shipments.index'))->assertOk()->getContent();
        $this->assertSame(1, $queries->count);
        $this->assertStringContainsString('30/09/2026', $html);
        $this->assertStringContainsString('02/10/2026', $html);
        $this->assertStringNotContainsString('30 Sep 2026', $html);
    }

    public function test_shipment_tables_render_the_display_alias_but_keep_the_original_sql_name(): void
    {
        foreach (['purchasing/shipments/index.blade.php', 'supplier/shipments/index.blade.php'] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));
            $this->assertStringContainsString("data: 'shipment_date_display', name: 'shipment_date'", $source);
        }
    }

    private function shipmentAjax(User $user, string $portal, array $extra = [])
    {
        $url = route($portal === 'supplier' ? 'supplier.shipments.index' : 'purchasing.shipments.index');
        $columnNames = $portal === 'supplier'
            ? ['shipment_number', 'po_references', 'items_count', 'total_qty', 'actual_weight', 'shipment_date', 'estimated_arrival_date', 'status', 'action']
            : ['shipment_number', 'supplier.name', 'consolidated_pos', 'items_count', 'total_qty', 'actual_weight', 'shipment_date', 'estimated_arrival_date', 'actual_arrival_date', 'status', 'action'];
        $columns = array_map(fn (string $name) => ['data' => $name === 'shipment_date' ? 'shipment_date_display' : $name, 'name' => $name, 'searchable' => ! in_array($name, ['items_count', 'total_qty', 'actual_weight', 'action'], true) ? 'true' : 'false', 'orderable' => ! in_array($name, ['items_count', 'total_qty', 'actual_weight', 'action'], true) ? 'true' : 'false', 'search' => ['value' => '', 'regex' => false]], $columnNames);
        $parameters = $extra + ['draw' => 1, 'start' => 0, 'length' => 25, 'columns' => $columns, 'order' => [], 'search' => ['value' => '', 'regex' => false]];

        return $this->actingAs($user)->getJson($url.'?'.http_build_query($parameters), ['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function preference(User $user): void
    {
        $defaults = config('user_preferences.defaults');
        unset($defaults['sidebar_revision']);
        $user->preference()->create([...$defaults, 'timezone' => 'Asia/Jakarta', 'date_format' => 'dmy', 'time_format' => '12h', 'number_format' => 'indonesian']);
    }

    private function watchPreferences(): object
    {
        $counter = (object) ['count' => 0];
        DB::listen(function ($query) use ($counter): void {
            if (str_contains(strtolower($query->sql), 'user_preferences')) {
                $counter->count++;
            }
        });

        return $counter;
    }

    private function shipment(User $supplier, string $number, string $date, string $eta, ?string $arrival, int $quantity, string $weight): Shipment
    {
        $requisition = PurchaseRequisition::create(['period_id' => $this->period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ-'.$number, 'status' => 'completed']);
        $prItem = $requisition->items()->create(['hs_code' => '7209.16.00', 'material_name' => 'Regional Plate', 'quantity' => 2, 'shape' => 'Flat', 'thickness' => 2.5, 'width' => 1000, 'length' => 2000, 'weight_needed' => 100]);
        $quotation = Quotation::create(['pr_id' => $requisition->id, 'supplier_id' => $supplier->id, 'exchange_rate_id' => $this->rate->id, 'currency' => 'USD', 'status' => 'accepted', 'submitted_at' => '2026-09-28 23:35:00']);
        $quotationItem = $quotation->items()->create(['pr_item_id' => $prItem->id, 'price_per_kg' => 2.5, 'amount' => '1250000.5']);
        $po = PurchaseOrder::create(['supplier_id' => $supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $this->rate->id, 'po_number' => 'PO-'.$number, 'status' => 'active', 'created_by' => $this->purchasing->id, 'estimated_arrival' => $eta]);
        $po->quotations()->attach($quotation->id);
        $shipment = $this->shipments->createDraft($supplier, [
            'notes' => $number,
            'shipment_date' => $date,
            'estimated_arrival_date' => $eta,
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $quotationItem->id, 'shipped_qty' => $quantity, 'actual_weight_kg' => $weight]],
        ]);
        if ($arrival !== null) {
            $shipment->forceFill(['actual_arrival_date' => $arrival])->save();
        }

        return $shipment->fresh(['items']);
    }
}
