<?php

namespace Tests\Feature\Procurement;

use App\Models\ExchangeRate;
use App\Models\MaterialClaim;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\QcInspection;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AttachesShipmentMtc;
use Tests\TestCase;

/**
 * Gate 2 scenario C-1 (plan §3-E): Item A 10 Flat, Item B 5 Round, ETA yesterday.
 * S1 ships A6/B5 and arrives; QC A ok, B ng; the claim is resolved; S2 ships A4/B5, both ok.
 */
class FulfillmentScenarioC1Test extends TestCase
{
    use AttachesShipmentMtc;
    use RefreshDatabase;

    protected User $purchasing;

    protected User $supplier;

    protected User $qc;

    protected PurchaseOrder $po;

    protected QuotationItem $itemA;

    protected QuotationItem $itemB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->travelTo('2026-10-28 10:00:00');
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $rate = ExchangeRate::create(['currency' => 'USD', 'rate_to_idr' => 16000, 'valid_from' => '2025-01-01', 'created_by' => $this->purchasing->id]);
        $period = Period::create(['name' => 'C1', 'month' => 10, 'year' => 2026, 'status' => 'open', 'created_by' => $this->purchasing->id]);
        $pr = PurchaseRequisition::create(['period_id' => $period->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/10/2026/901', 'status' => 'bidding']);
        $prA = PrItem::create(['pr_id' => $pr->id, 'material_name' => 'SCM440', 'quantity' => 10, 'shape' => 'Flat', 'thickness' => 10, 'width' => 100, 'length' => 200, 'weight_needed' => 5]);
        $prB = PrItem::create(['pr_id' => $pr->id, 'material_name' => 'S45C', 'quantity' => 5, 'shape' => 'Round', 'd_outer' => 50, 'length' => 300, 'weight_needed' => 4]);
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => $rate->id, 'status' => Quotation::STATUS_SUBMITTED, 'submitted_at' => now()]);
        $this->itemA = $quotation->items()->create(['pr_item_id' => $prA->id, 'is_available' => true, 'price_per_kg' => 2, 'amount' => 100, 'available_qty' => 10]);
        $this->itemB = $quotation->items()->create(['pr_item_id' => $prB->id, 'is_available' => true, 'price_per_kg' => 3, 'amount' => 60, 'available_qty' => 5]);
        $awards = app(PrItemAwardService::class);
        $this->po = app(PurchaseOrderGenerationService::class)->generateFromAwards(collect([
            $awards->awardItem($prA, $this->itemA, $this->purchasing),
            $awards->awardItem($prB, $this->itemB, $this->purchasing),
        ]), $this->purchasing)->first();
        $this->po->update(['estimated_arrival' => now()->subDay()->toDateString()]);
    }

    /** Runs the C-1 steps and returns the observed state after each one. */
    protected function runScenario(): array
    {
        $steps = [];
        $s1 = $this->shipAndArrive(['A' => 6, 'B' => 5]);
        $steps['1_arrived'] = $this->observe();
        $ngInspection = $this->inspect($s1, ['A' => 'ok', 'B' => 'ng']);
        $steps['2_qc_ng'] = $this->observe();
        $claim = MaterialClaim::create(['inspection_id' => $ngInspection->id, 'po_id' => $this->po->id, 'submitted_by' => $this->purchasing->id, 'supplier_id' => $this->supplier->id, 'status' => 'pending', 'description' => 'B NG', 'deadline' => now()->addWeek()->toDateString()]);
        $this->po->fresh()->reconcileOperationalStatus();
        $steps['2b_claim_open'] = $this->observe();
        $claim->update(['status' => 'resolved']);
        $this->po->fresh()->reconcileOperationalStatus();
        $steps['3_claim_resolved'] = $this->observe();
        $s2 = $this->shipAndArrive(['A' => 4, 'B' => 5]);
        $steps['4a_s2_arrived'] = $this->observe();
        $this->inspect($s2, ['A' => 'ok', 'B' => 'ok']);
        $steps['4_completed'] = $this->observe();

        return $steps;
    }

    protected function observe(): array
    {
        $po = $this->po->fresh();
        if ($this->afterEachStep !== null) {
            $step = array_key_last(array_merge($this->projectionAt, [])) === null ? 0 : count($this->projectionAt);
            $name = ['1_arrived', '2_qc_ng', '2b_claim_open', '3_claim_resolved', '4a_s2_arrived', '4_completed'][$step];
            ($this->afterEachStep)($name);
            $projection = $po->fulfillmentProjection()['items'];
            $this->projectionAt[$name] = ['A' => $projection[$this->itemA->id], 'B' => $projection[$this->itemB->id]];
        }
        $keys = ['ordered_qty', 'physical_shipped_qty', 'physical_arrived_qty', 'accepted_qty', 'ng_qty', 'replacement_eligible_qty', 'reserved_qty', 'allocated_qty', 'remaining_qty', 'is_fully_allocated', 'is_fully_accepted'];

        return [
            'status' => $po->status,
            'is_overdue' => $po->is_overdue,
            'A' => array_intersect_key($po->itemFulfillmentStatus($this->itemA->id), array_flip($keys)),
            'B' => array_intersect_key($po->itemFulfillmentStatus($this->itemB->id), array_flip($keys)),
        ];
    }

    protected function shipAndArrive(array $qty): Shipment
    {
        $service = app(ShipmentService::class);
        $shipment = $service->createDraft($this->supplier);
        $lines = [];
        foreach (['A' => $this->itemA, 'B' => $this->itemB] as $key => $item) {
            if (($qty[$key] ?? 0) > 0) {
                $lines[] = ['purchase_order_id' => $this->po->id, 'quotation_item_id' => $item->id, 'shipped_qty' => $qty[$key], 'actual_weight_kg' => $qty[$key] * 5];
            }
        }
        $this->submitWithMtc($shipment, ['items' => $lines]);

        return $service->confirmArrival($shipment->id, $this->purchasing);
    }

    protected function inspect(Shipment $shipment, array $statuses): QcInspection
    {
        $shipment->load('items');
        $inspection = QcInspection::create(['po_id' => $this->po->id, 'shipment_id' => $shipment->id, 'inspected_by' => $this->qc->id, 'status' => in_array('ng', $statuses, true) ? 'ng' : 'ok', 'inspected_at' => now()]);
        foreach (['A' => $this->itemA, 'B' => $this->itemB] as $key => $item) {
            $line = $shipment->items->firstWhere('quotation_item_id', $item->id);
            if ($line !== null && isset($statuses[$key])) {
                $inspection->items()->create(['shipment_item_id' => $line->id, 'pr_item_id' => $item->pr_item_id, 'status' => $statuses[$key]]);
            }
        }
        $this->po->fresh()->reconcileOperationalStatus();

        return $inspection;
    }

    /**
     * CT: tallies and statuses equal the characterization recorded before the Gate 2 refactor
     * (tests/Fixtures/fulfillment-c1-characterization.json). Only "overdue" changes: after the
     * claim is resolved the PO is active, the ETA has passed and B is still owed, so it is
     * overdue (PUR-08); before, a set actual_arrival hid that.
     */
    public function test_c1_tallies_match_the_characterization_and_overdue_follows_the_guarded_predicate(): void
    {
        $golden = json_decode(file_get_contents(dirname(__DIR__, 2).'/Fixtures/fulfillment-c1-characterization.json'), true, flags: JSON_THROW_ON_ERROR);
        $observed = $this->runScenario();

        $this->assertSame(array_keys($golden), array_keys($observed));
        foreach ($golden as $step => $expected) {
            $this->assertSame($expected['status'], $observed[$step]['status'], $step);
            $this->assertSame($expected['A'], $observed[$step]['A'], $step.' A');
            $this->assertSame($expected['B'], $observed[$step]['B'], $step.' B');
            $this->assertSame($step === '3_claim_resolved', $observed[$step]['is_overdue'], $step.' overdue');
        }
    }

    /** KT + invariant: the read-only projection equals the transactional tally at every step. */
    public function test_projection_equals_item_status_and_conserves_quantity_at_every_step(): void
    {
        $checks = 0;
        $this->afterEachStep = function (string $step) use (&$checks): void {
            $po = $this->po->fresh();
            $projection = $po->fulfillmentProjection();
            foreach ([$this->itemA, $this->itemB] as $item) {
                $direct = $po->itemFulfillmentStatus($item->id);
                $line = $projection['items'][$item->id];
                foreach ($direct as $key => $value) {
                    $this->assertSame($value, $line[$key], "{$step} item {$item->id} {$key}");
                }
                $this->assertSame(
                    $line['physical_shipped_qty'],
                    $line['accepted_qty'] + $line['in_transit_qty'] + $line['waiting_qc_qty'] + $line['unresolved_ng_qty'] + $line['replacement_eligible_qty'],
                    "{$step} conservation"
                );
                $this->assertSame($line['reserved_qty'], $line['in_transit_qty'] + $line['waiting_qc_qty'] + $line['unresolved_ng_qty'], "{$step} reserved split");
                $checks++;
            }
        };
        $observed = $this->runScenario();
        $this->assertSame(12, $checks);

        // NT: NG under an open claim is unresolved NG, never "waiting QC".
        $this->assertSame(5, $this->projectionAt['2b_claim_open']['B']['unresolved_ng_qty']);
        $this->assertSame(0, $this->projectionAt['2b_claim_open']['B']['waiting_qc_qty']);
        $this->assertSame(5, $this->projectionAt['1_arrived']['B']['waiting_qc_qty']);
        $this->assertSame(5, $this->projectionAt['3_claim_resolved']['B']['replacement_eligible_qty']);
        $this->assertSame('completed', $observed['4_completed']['status']);
    }

    /** PUR-09 (RISK-14): an unavailable line on a PO without awards never blocks completion. */
    public function test_no_award_po_completes_when_every_available_line_is_accepted(): void
    {
        $pr = PurchaseRequisition::create(['period_id' => Period::firstOrFail()->id, 'created_by' => $this->purchasing->id, 'pr_number' => 'REQ/10/2026/902', 'status' => 'completed']);
        $prOk = PrItem::create(['pr_id' => $pr->id, 'material_name' => 'SCM440', 'quantity' => 5, 'shape' => 'Flat', 'thickness' => 10, 'width' => 100, 'length' => 200, 'weight_needed' => 5]);
        $prNa = PrItem::create(['pr_id' => $pr->id, 'material_name' => 'S45C', 'quantity' => 3, 'shape' => 'Round', 'd_outer' => 50, 'length' => 300, 'weight_needed' => 4]);
        $quotation = Quotation::create(['pr_id' => $pr->id, 'supplier_id' => $this->supplier->id, 'currency' => 'USD', 'exchange_rate_id' => ExchangeRate::firstOrFail()->id, 'status' => 'accepted', 'submitted_at' => now()]);
        $available = $quotation->items()->create(['pr_item_id' => $prOk->id, 'is_available' => true, 'price_per_kg' => 2, 'amount' => 50, 'available_qty' => 5]);
        $quotation->items()->create(['pr_item_id' => $prNa->id, 'is_available' => false, 'price_per_kg' => null, 'amount' => 0]);
        $po = PurchaseOrder::create(['supplier_id' => $this->supplier->id, 'currency' => 'USD', 'po_number' => 'PO/10/2026/902', 'status' => 'active', 'created_by' => $this->purchasing->id]);
        $po->quotations()->attach($quotation->id);

        $ship = function (int $qty) use ($po, $available): Shipment {
            $shipment = Shipment::create(['shipment_number' => 'SHP/902/'.$qty, 'supplier_id' => $this->supplier->id, 'status' => Shipment::STATUS_ARRIVED, 'created_by' => $this->supplier->id]);
            $line = $shipment->items()->create(['purchase_order_id' => $po->id, 'quotation_item_id' => $available->id, 'shipped_qty' => $qty, 'actual_weight_kg' => $qty * 5]);
            $inspection = QcInspection::create(['po_id' => $po->id, 'shipment_id' => $shipment->id, 'inspected_by' => $this->qc->id, 'status' => 'ok', 'inspected_at' => now()]);
            $inspection->items()->create(['shipment_item_id' => $line->id, 'pr_item_id' => $available->pr_item_id, 'status' => 'ok']);

            return $shipment;
        };

        $ship(2);
        $this->assertNotSame('completed', $po->fresh()->reconcileOperationalStatus(), 'Partial acceptance never completes.');
        $ship(3);
        $this->assertSame('completed', $po->fresh()->reconcileOperationalStatus(), 'The unavailable S45C line is not owed.');
    }

    /**
     * PUR-08: "Create Claim" targets the latest NG inspection without any claim (soft-deleted
     * included) on the PO list, the claims action-needed list and the PO page; claims.store
     * rejects an inspection that already has a claim, so the old link was a dead end.
     */
    public function test_create_claim_links_to_the_latest_unclaimed_ng_inspection_everywhere(): void
    {
        // Shipping is (correctly) blocked while claim_needed, so the second NG is a re-inspection.
        $s1 = $this->shipAndArrive(['A' => 3, 'B' => 0]);
        $unclaimed = $this->inspect($s1, ['A' => 'ng']);
        // The newest NG inspection already had a claim (withdrawn = soft-deleted): a dead end.
        $claimed = $this->inspect($s1, ['A' => 'ng']);
        $claimed->forceFill(['inspected_at' => now()->addMinute()])->save();
        MaterialClaim::create(['inspection_id' => $claimed->id, 'po_id' => $this->po->id, 'submitted_by' => $this->purchasing->id, 'supplier_id' => $this->supplier->id, 'status' => 'pending', 'description' => 'withdrawn', 'deadline' => now()->addWeek()->toDateString()])->delete();
        $this->po->fresh()->reconcileOperationalStatus();
        $this->assertSame('claim_needed', $this->po->fresh()->status);

        $this->actingAs($this->purchasing);
        $target = route('purchasing.claims.create', $unclaimed);
        $stale = route('purchasing.claims.create', $claimed);
        $ajax = fn (string $url) => html_entity_decode((string) $this->withHeader('X-Requested-With', 'XMLHttpRequest')->getJson($url.'?draw=1&start=0&length=25')->assertOk()->json('data.0.action'));
        $surfaces = [
            'po list' => $ajax(route('purchasing.purchase-orders.index')),
            'action needed' => $ajax(route('purchasing.claims.data-action')),
            'po page' => html_entity_decode($this->get(route('purchasing.purchase-orders.show', $this->po))->assertOk()->getContent()),
        ];
        foreach ($surfaces as $surface => $html) {
            $this->assertStringContainsString($target, $html, $surface);
            $this->assertStringNotContainsString($stale.'?', $html, $surface.' never targets the inspection that already had a claim');
            $this->assertStringNotContainsString($stale.'"', $html, $surface.' never targets the inspection that already had a claim');
        }
    }

    /** QC-01 KT: the QC page shows and copies the ordered (offered) spec; inputs start empty. */
    public function test_qc_create_shows_and_copies_the_ordered_specification(): void
    {
        $this->itemA->forceFill(['available_thickness' => 12])->saveQuietly();
        $shipment = $this->shipAndArrive(['A' => 6, 'B' => 5]);

        $html = $this->actingAs($this->qc)->get(route('qc.inspections.create', ['po_id' => $this->po, 'shipment_id' => $shipment->hash]))->assertOk()->getContent();
        $this->assertSame(['thickness' => '12', 'width' => '100', 'length' => '200'], $this->itemA->fresh('prItem')->orderedSpecification() ? array_map(fn ($s) => $s['value'], $this->itemA->fresh('prItem')->orderedSpecification()) : null);
        $this->assertMatchesRegularExpression('/data-spec-type="thickness" data-val="12"/', $html, 'The ordered (offered) thickness is the spec.');
        $this->assertStringContainsString(__('qc.copy.requested_value', ['value' => '10']), $html);
        preg_match_all('/data-spec="([^"]*)"/', $html, $copies);
        $copySpecs = array_map(fn ($raw) => json_decode(html_entity_decode($raw), true), $copies[1]);
        $this->assertSame([['thickness' => '12', 'width' => '100', 'length' => '200'], ['d_outer' => '50', 'length' => '300']], $copySpecs, 'Copy carries only the ordered numeric dimensions; no status. '.json_encode($copySpecs));
        $this->assertDoesNotMatchRegularExpression('/name="items\[0\]\[actual_thickness\]"[^>]*value="[^"]+"/', $html, 'Inputs start empty.');
        $this->assertMatchesRegularExpression('/name="items\[0\]\[actual_thickness\]"[^>]*required/s', $html);
    }

    /** QC-01 NT: every relevant dimension of a known shape is required for OK and NG; null shapes stay weight-only. */
    public function test_qc_store_requires_every_dimension_of_a_known_shape(): void
    {
        $shipment = $this->shipAndArrive(['A' => 6, 'B' => 5]);
        $this->actingAs($this->qc);
        $post = fn (array $items) => $this->from(route('qc.inspections.create', ['po_id' => $this->po, 'shipment_id' => $shipment->hash]))
            ->post(route('qc.inspections.store', $this->po), ['shipment_id' => $shipment->hash, 'items' => $items]);

        foreach (['ok', 'ng'] as $status) {
            $post([['pr_item_id' => $this->itemA->pr_item_id, 'status' => $status, 'actual_thickness' => 10], ['pr_item_id' => $this->itemB->pr_item_id, 'status' => 'ok', 'actual_d_outer' => 50, 'actual_length' => 300]])
                ->assertSessionHasErrors(['items.0.actual_width', 'items.0.actual_length']);
        }
        foreach ([0, -1, 1000000] as $bad) {
            $post([['pr_item_id' => $this->itemB->pr_item_id, 'status' => 'ok', 'actual_d_outer' => $bad, 'actual_length' => 300]])
                ->assertSessionHasErrors(['items.0.actual_d_outer']);
        }
        $this->assertSame(0, QcInspection::count());

        // A lowercase shape (legacy direct write) still counts as Round.
        $this->itemB->prItem->forceFill(['shape' => 'round'])->saveQuietly();
        $post([['pr_item_id' => $this->itemB->pr_item_id, 'status' => 'ok', 'actual_weight' => 4]])
            ->assertSessionHasErrors(['items.0.actual_d_outer', 'items.0.actual_length']);

        // Null shape: no dimension can be derived; the inspection stays weight-only (unchanged).
        $this->itemB->prItem->forceFill(['shape' => null])->saveQuietly();
        $post([
            ['pr_item_id' => $this->itemA->pr_item_id, 'status' => 'ok', 'actual_thickness' => 10, 'actual_width' => 100, 'actual_length' => 200],
            ['pr_item_id' => $this->itemB->pr_item_id, 'status' => 'ok', 'actual_weight' => 4],
        ])->assertSessionHasNoErrors();
        $this->assertSame(1, QcInspection::count());
    }

    /** @var (callable(string): void)|null */
    protected $afterEachStep = null;

    protected array $projectionAt = [];
}
