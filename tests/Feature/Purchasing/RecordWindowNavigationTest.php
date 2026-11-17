<?php

namespace Tests\Feature\Purchasing;

use App\Models\ExchangeRate;
use App\Models\LocalPurchaseOrder;
use App\Models\Period;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\SupplierScope;
use App\Models\User;
use App\Support\BusinessTime;
use App\Support\PurchasingNavigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordWindowNavigationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $finance;

    private User $importSupplier;

    private User $localSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true, 'account_status' => 'ACTIVE']);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true, 'account_status' => 'ACTIVE']);

        $this->importSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->updateOrCreate(['supplier_id' => $this->importSupplier->id, 'scope' => 'import']);

        $this->localSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true, 'account_status' => 'ACTIVE']);
        SupplierScope::query()->where('supplier_id', $this->localSupplier->id)->delete();
        SupplierScope::query()->create(['supplier_id' => $this->localSupplier->id, 'scope' => 'local']);
    }

    public function test_datatables_ajax_populates_po_record_window_and_po_show_renders_prev_next_controls(): void
    {
        $pos = collect([1, 2, 3])->map(function (int $i) {
            return PurchaseOrder::create([
                'supplier_id' => $this->importSupplier->id,
                'currency' => 'USD',
                'po_number' => "PO/WIN/00{$i}",
                'status' => 'active',
                'created_by' => $this->purchasing->id,
            ]);
        });

        $ajax = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.purchase-orders.index'));

        $ajax->assertOk();
        $rowIds = collect($ajax->json('data'))->pluck('DT_RowId')->values()->all();
        $this->assertCount(3, $rowIds);

        // Prefetch must NOT overwrite the existing record window.
        $this->actingAs($this->purchasing)
            ->withHeaders([
                'X-Requested-With' => 'XMLHttpRequest',
                'X-Adasi-Prefetch' => '1',
            ])
            ->getJson(route('purchasing.purchase-orders.index', ['po_number' => 'NON-EXISTENT-FILTER']))
            ->assertOk();

        $middle = $pos->first(fn (PurchaseOrder $po) => $po->getRouteKey() === $rowIds[1]);
        $prev = $pos->first(fn (PurchaseOrder $po) => $po->getRouteKey() === $rowIds[0]);
        $next = $pos->first(fn (PurchaseOrder $po) => $po->getRouteKey() === $rowIds[2]);

        $middleShow = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $middle));

        $middleShow->assertOk()
            ->assertSee('data-record-nav-bar', false)
            ->assertSee('data-record-nav="prev"', false)
            ->assertSee('data-record-nav="next"', false)
            ->assertSee(route('purchasing.purchase-orders.show', $prev, false), false)
            ->assertSee(route('purchasing.purchase-orders.show', $next, false), false)
            ->assertSee('2 / 3', false);

        // Boundary record renders the missing direction as a disabled control, not a link.
        // (The layout keyboard handler also mentions the data-record-nav selector, so assert on the disabled marker.)
        $firstShow = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $prev));
        $firstShow->assertOk()
            ->assertSee('1 / 3', false)
            ->assertSee('data-record-nav-disabled="prev"', false)
            ->assertDontSee('data-record-nav-disabled="next"', false)
            ->assertSee(route('purchasing.purchase-orders.show', $middle, false), false);
    }

    public function test_pr_quotation_shipment_and_local_po_record_windows_wire_prev_next_navigation(): void
    {
        $period = Period::create([
            'name' => 'Window Period',
            'month' => 11,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);
        $rate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => BusinessTime::today()->toDateString(),
            'created_by' => $this->purchasing->id,
        ]);

        $prs = collect([1, 2])->map(fn (int $i) => PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => "PR/WIN/00{$i}",
            'status' => 'bidding',
        ]));
        $prs->each(fn (PurchaseRequisition $pr) => $pr->items()->create([
            'material_name' => 'Steel Sheet',
            'quantity' => 1,
            'weight_needed' => 10,
        ]));

        // PR DataTables -> Show Prev/Next
        $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.requisitions.index'))
            ->assertOk();
        $prWindow = PurchasingNavigation::recordWindowNavigation(
            'purchasing.requisitions.index',
            'purchasing.requisitions.show',
            $prs[0]->getRouteKey(),
        );
        $this->assertSame(2, $prWindow['total']);
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.requisitions.show', $prs[0]))
            ->assertOk()
            ->assertSee('data-record-nav-bar', false);

        // Quotation paginator -> Show Prev/Next
        $quotes = $prs->map(fn (PurchaseRequisition $pr) => Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->importSupplier->id,
            'exchange_rate_id' => $rate->id,
            'currency' => 'USD',
            'status' => 'submitted',
        ]));
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.index'))
            ->assertOk();
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.quotations.show', $quotes[0]))
            ->assertOk()
            ->assertSee('data-record-nav-bar', false)
            ->assertSee('2', false);

        // Shipment DataTables -> Show Prev/Next
        collect([1, 2])->map(fn (int $i) => Shipment::create([
            'shipment_number' => "SHP/WIN/00{$i}",
            'supplier_id' => $this->importSupplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => BusinessTime::today()->toDateString(),
            'estimated_arrival_date' => BusinessTime::today()->addDays(5)->toDateString(),
            'created_by' => $this->importSupplier->id,
        ]));
        $shipmentAjax = $this->actingAs($this->purchasing)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route('purchasing.shipments.index'))
            ->assertOk();
        $firstShipmentHash = $shipmentAjax->json('data.0.DT_RowId');
        $this->actingAs($this->purchasing)
            ->get(route('purchasing.shipments.show', $firstShipmentHash))
            ->assertOk()
            ->assertSee('data-record-nav-bar', false)
            ->assertSee('data-record-nav="next"', false);

        // Local Procurement paginator -> Show Prev/Next
        $localPos = collect([1, 2])->map(fn (int $i) => LocalPurchaseOrder::create([
            'po_number' => "LPO/WIN/00{$i}",
            'supplier_id' => $this->localSupplier->id,
            'total_amount' => '10000000.00',
            'currency' => 'IDR',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'po_date' => BusinessTime::today()->subDays($i)->toDateString(),
            'source' => LocalPurchaseOrder::SOURCE_MANUAL,
            'created_by' => $this->finance->id,
        ]));
        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.index'))
            ->assertOk();
        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.show', $localPos[0]))
            ->assertOk()
            ->assertSee('data-record-nav-bar', false)
            ->assertSee('1 / 2', false)
            ->assertSee('data-record-nav="next"', false);
    }

    public function test_layout_renders_prev_next_keyboard_shortcut_documentation(): void
    {
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.index'));

        $response->assertOk()
            ->assertSee(__('navigation.shortcut_prev_record'), false)
            ->assertSee(__('navigation.shortcut_next_record'), false)
            ->assertSee('Alt', false);
    }
}
