<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PoItemProgressUpdate;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\User;
use App\Services\MaterialProgressService;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PoItemMaterialProgressTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private User $qcUser;

    private Period $period;

    private ExchangeRate $exchangeRate;

    private MaterialProgressService $progressService;

    private ShipmentService $shipmentService;

    private PrItemAwardService $awardService;

    private PurchaseOrderGenerationService $poService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->supplierA = User::factory()->create([
            'role' => 'supplier',
            'name' => 'Supplier Alpha',
            'is_active' => true,
        ]);

        $this->supplierB = User::factory()->create([
            'role' => 'supplier',
            'name' => 'Supplier Beta',
            'is_active' => true,
        ]);

        $this->qcUser = User::factory()->create([
            'role' => 'qc',
            'is_active' => true,
        ]);

        $this->period = Period::create([
            'name' => 'Progress Test Period',
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);

        $this->progressService = app(MaterialProgressService::class);
        $this->shipmentService = app(ShipmentService::class);
        $this->awardService = app(PrItemAwardService::class);
        $this->poService = app(PurchaseOrderGenerationService::class);
    }

    /**
     * Helper to create a PurchaseOrder with awarded items for a given supplier.
     *
     * @param  array<int, array{0: float, 1: int}>  $itemsConfig  List of [weight, quantity]
     */
    private function createPoWithItems(User $supplier, array $itemsConfig = [[20.0, 20]], ?string $poTargetArrival = null): PurchaseOrder
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/'.str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'notes' => 'Material progress requisition',
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14)->toDateString(),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        $awards = collect();

        foreach ($itemsConfig as $index => $cfg) {
            $weight = $cfg[0];
            $qty = $cfg[1];

            $prItem = PrItem::create([
                'pr_id' => $pr->id,
                'hs_code' => '7209.16.00',
                'material_name' => 'Steel Plate '.($index + 1),
                'quantity' => $qty,
                'shape' => PrItem::SHAPE_FLAT,
                'thickness' => 2.0,
                'width' => 100,
                'length' => 200,
                'weight_needed' => $weight,
            ]);

            $qItem = $quotation->items()->create([
                'pr_item_id' => $prItem->id,
                'is_available' => true,
                'price_per_kg' => 2.5,
                'amount' => 2.5 * $weight,
                'available_qty' => $qty,
            ]);

            $award = $this->awardService->awardItem($prItem, $qItem, $this->purchasing);
            $awards->push($award);
        }

        $options = [];
        if ($poTargetArrival) {
            $options['estimated_arrival'] = $poTargetArrival;
        }

        $pos = $this->poService->generateFromAwards($awards, $this->purchasing, $options);

        return $pos->first();
    }

    /**
     * P-01: Default state awaiting_confirmation when no history exists.
     */
    public function test_p01_default_state_awaiting_confirmation_when_no_history_exists(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $current = $this->progressService->currentForAward($award);
        $this->assertSame(PoItemProgressUpdate::STAGE_AWAITING_CONFIRMATION, $current['status']);
        $this->assertTrue($current['is_fallback']);
        $this->assertNull($current['update']);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(PoItemProgressUpdate::STAGE_AWAITING_CONFIRMATION, $projection['manual_progress_status']);
        $this->assertSame('Awaiting Confirmation', $projection['manual_progress_label']);
        $this->assertFalse($projection['has_manual_update']);
        $this->assertSame(20, $projection['supplier_controlled_qty']);
        $this->assertSame(20, $projection['ordered_qty']);
        $this->assertTrue($projection['can_update']);
    }

    /**
     * P-02: Per-item independence within a single PO.
     */
    public function test_p02_per_item_independence_within_single_po(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20], [15.0, 15]]);
        $award1 = $po->awards[0];
        $award2 = $po->awards[1];

        // Update award 1 to order_confirmed
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award1]),
            [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
                'estimated_ready_date' => now()->addDays(10)->toDateString(),
                'note' => 'Confirmed item 1',
            ]
        );
        $response->assertRedirect()->assertSessionHasNoErrors();

        $proj1 = $this->progressService->projectionForAward($award1);
        $proj2 = $this->progressService->projectionForAward($award2);

        $this->assertSame(PoItemProgressUpdate::STAGE_ORDER_CONFIRMED, $proj1['manual_progress_status']);
        $this->assertTrue($proj1['has_manual_update']);

        // Award 2 must remain in default awaiting_confirmation
        $this->assertSame(PoItemProgressUpdate::STAGE_AWAITING_CONFIRMATION, $proj2['manual_progress_status']);
        $this->assertFalse($proj2['has_manual_update']);
    }

    /**
     * P-03: Forward stage skipping allowed.
     */
    public function test_p03_forward_stage_skipping_allowed(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // Skip directly from awaiting_confirmation to ready_to_ship
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
                'estimated_ready_date' => now()->addDays(5)->toDateString(),
                'note' => 'Fast-tracked directly to ready',
            ]
        );
        $response->assertRedirect()->assertSessionHasNoErrors();

        $current = $this->progressService->currentForAward($award);
        $this->assertSame(PoItemProgressUpdate::STAGE_READY_TO_SHIP, $current['status']);
    }

    /**
     * P-04: Backward stage movement with note succeeds.
     */
    public function test_p04_backward_stage_movement_with_note_succeeds(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // Advance to ready_to_ship
        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
            'estimated_ready_date' => now()->addDays(5)->toDateString(),
        ]);

        // Move backward to material_preparation with required note
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
                'estimated_ready_date' => now()->addDays(12)->toDateString(),
                'note' => 'Furnace calibration issue; re-preparing raw billets.',
            ]
        );
        $response->assertRedirect()->assertSessionHasNoErrors();

        $current = $this->progressService->currentForAward($award);
        $this->assertSame(PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION, $current['status']);
        $this->assertSame('Furnace calibration issue; re-preparing raw billets.', $current['note']);
    }

    /**
     * P-05: Backward stage movement without note fails validation.
     */
    public function test_p05_backward_stage_movement_without_note_fails_validation(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // Advance to ready_to_ship
        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
            'estimated_ready_date' => now()->addDays(5)->toDateString(),
        ]);

        $initialCount = PoItemProgressUpdate::where('pr_item_award_id', $award->id)->count();

        // Move backward without note -> must fail validation
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_ON_PRODUCTION,
                'estimated_ready_date' => now()->addDays(8)->toDateString(),
                'note' => '',
            ]
        );

        $response->assertSessionHasErrors('note');
        $this->assertSame($initialCount, PoItemProgressUpdate::where('pr_item_award_id', $award->id)->count());

        $current = $this->progressService->currentForAward($award);
        $this->assertSame(PoItemProgressUpdate::STAGE_READY_TO_SHIP, $current['status']);
    }

    /**
     * P-06: Same-stage refresh allowed with new forecast or note.
     */
    public function test_p06_same_stage_refresh_allowed_with_new_forecast_or_note(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $date1 = now()->addDays(10)->toDateString();
        $date2 = now()->addDays(15)->toDateString();

        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
            'estimated_ready_date' => $date1,
            'note' => 'Phase 1 prep',
        ]);

        // Same stage with updated timeline
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
                'estimated_ready_date' => $date2,
                'note' => 'Extended preparation timeline',
            ]
        );
        $response->assertRedirect()->assertSessionHasNoErrors();

        $history = $this->progressService->historyForAward($award);
        $this->assertCount(2, $history);
        $this->assertSame($date2, $history->first()->estimated_ready_date->toDateString());
    }

    /**
     * P-07: Append-only history preserved across multiple updates.
     */
    public function test_p07_append_only_history_preserved_across_multiple_updates(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            'note' => 'Step 1',
        ]);
        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
            'note' => 'Step 2',
        ]);
        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_ON_PRODUCTION,
            'note' => 'Step 3',
        ]);

        $history = $this->progressService->historyForAward($award);
        $this->assertCount(3, $history);

        // Newest first
        $this->assertSame(PoItemProgressUpdate::STAGE_ON_PRODUCTION, $history[0]->status);
        $this->assertSame(PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION, $history[1]->status);
        $this->assertSame(PoItemProgressUpdate::STAGE_ORDER_CONFIRMED, $history[2]->status);
    }

    /**
     * P-08: Initial supplier-controlled Qty matches ordered Qty.
     */
    public function test_p08_initial_supplier_controlled_qty_matches_ordered_qty(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[25.0, 25]]);
        $award = $po->awards->first();

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(25, $projection['ordered_qty']);
        $this->assertSame(25, $projection['supplier_controlled_qty']);
        $this->assertSame(0, $projection['in_transit_qty']);
        $this->assertSame(0, $projection['accepted_qty']);
        $this->assertSame(0, $projection['arrived_pending_qc_qty']);
        $this->assertTrue($projection['can_update']);
    }

    /**
     * P-09: Draft shipment does not reduce supplier-controlled Qty.
     */
    public function test_p09_draft_shipment_does_not_reduce_supplier_controlled_qty(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        $draft = $this->shipmentService->createDraft($this->supplierA, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 8,
                    'actual_weight_kg' => 8.0,
                ],
            ],
        ]);

        $this->assertSame(Shipment::STATUS_DRAFT, $draft->status);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(20, $projection['supplier_controlled_qty']);
        $this->assertSame(0, $projection['in_transit_qty']);
        $this->assertTrue($projection['can_update']);
    }

    /**
     * P-10: Submitted shipment reduces supplier-controlled Qty and increases In Transit.
     */
    public function test_p10_submitted_shipment_reduces_supplier_controlled_qty_and_increases_in_transit(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        $draft = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 8,
                    'actual_weight_kg' => 8.0,
                ],
            ],
        ], $this->supplierA);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->status);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(12, $projection['supplier_controlled_qty']);
        $this->assertSame(8, $projection['in_transit_qty']);
        $this->assertSame(0, $projection['accepted_qty']);
        $this->assertTrue($projection['can_update']);
    }

    /**
     * P-11: Remaining quantity keeps previous manual progress stage.
     */
    public function test_p11_remaining_quantity_keeps_previous_manual_progress_stage(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        // Set stage to ready_to_ship
        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
            'note' => 'Ready for shipping batches',
        ]);

        // Submit partial shipment
        $draft = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($draft, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 8,
                    'actual_weight_kg' => 8.0,
                ],
            ],
        ], $this->supplierA);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(PoItemProgressUpdate::STAGE_READY_TO_SHIP, $projection['manual_progress_status']);
        $this->assertSame(12, $projection['supplier_controlled_qty']);
    }

    /**
     * P-12: Arrived shipment preserves remaining supplier-controlled Qty.
     */
    public function test_p12_arrived_shipment_preserves_remaining_supplier_controlled_qty(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        $draft = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 8,
                    'actual_weight_kg' => 8.0,
                ],
            ],
        ], $this->supplierA);

        $this->shipmentService->confirmArrival($shipment, $this->purchasing);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(12, $projection['supplier_controlled_qty']);
        $this->assertSame(0, $projection['in_transit_qty']);
        $this->assertSame(8, $projection['arrived_pending_qc_qty']);
    }

    /**
     * P-13: QC accepted material projection matches authoritative fulfillment.
     */
    public function test_p13_qc_accepted_material_projection_matches_authoritative_fulfillment(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;
        $prItem = $award->prItem;

        $draft = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 8,
                    'actual_weight_kg' => 8.0,
                ],
            ],
        ], $this->supplierA);
        $this->shipmentService->confirmArrival($shipment, $this->purchasing);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_d_outer' => 120.0,
                    'actual_length' => 200.0,
                    'actual_weight' => 8.0,
                    'status' => 'ok',
                ],
            ],
        ]);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(8, $projection['accepted_qty']);
        $this->assertSame(12, $projection['supplier_controlled_qty']);
        $this->assertSame(0, $projection['in_transit_qty']);
        $this->assertSame(0, $projection['arrived_pending_qc_qty']);
    }

    /**
     * P-14 and P-15: Second shipment exhausts remaining quantity and rejects manual updates.
     */
    public function test_p14_and_p15_zero_remaining_quantity_rejects_manual_updates(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        // Shipment 1: 8 pcs
        $draft1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($draft1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 8, 'actual_weight_kg' => 8.0],
            ],
        ], $this->supplierA);

        // Shipment 2: Remaining 12 pcs
        $draft2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($draft2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 12, 'actual_weight_kg' => 12.0],
            ],
        ], $this->supplierA);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(0, $projection['supplier_controlled_qty']);
        $this->assertFalse($projection['can_update']);

        // Attempting to post manual progress update must be rejected
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
                'note' => 'Attempt update after full shipment',
            ]
        );

        $response->assertSessionHasErrors('status');
    }

    /**
     * P-16: Shipment cancellation releases quantity back to supplier-controlled.
     */
    public function test_p16_shipment_cancellation_releases_quantity_back_to_supplier_controlled(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        $draft = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 8, 'actual_weight_kg' => 8.0],
            ],
        ], $this->supplierA);

        $projSubmitted = $this->progressService->projectionForAward($award);
        $this->assertSame(12, $projSubmitted['supplier_controlled_qty']);

        // Cancel the shipment
        $this->shipmentService->cancelShipment($shipment, $this->supplierA, 'Vessel canceled');

        $projCancelled = $this->progressService->projectionForAward($award);
        $this->assertSame(20, $projCancelled['supplier_controlled_qty']);
        $this->assertSame(0, $projCancelled['in_transit_qty']);
        $this->assertTrue($projCancelled['can_update']);
    }

    /**
     * P-17: QC NG defect projection matches authoritative fulfillment.
     */
    public function test_p17_qc_ng_defect_projection_matches_authoritative_fulfillment(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;
        $prItem = $award->prItem;

        $draft = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 8, 'actual_weight_kg' => 8.0],
            ],
        ], $this->supplierA);
        $this->shipmentService->confirmArrival($shipment, $this->purchasing);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_d_outer' => 120.0,
                    'actual_length' => 200.0,
                    'actual_weight' => 8.0,
                    'status' => 'ng',
                ],
            ],
        ]);

        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame(0, $projection['accepted_qty']);
        $this->assertSame(12, $projection['supplier_controlled_qty']);
    }

    /**
     * P-18: Material claim and replacement follow authoritative fulfillment.
     */
    public function test_p18_material_claim_and_replacement_follow_authoritative_fulfillment(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // Projection reflects unallocated supplier controlled qty
        $projection = $this->progressService->projectionForAward($award);
        $this->assertArrayHasKey('supplier_controlled_qty', $projection);
        $this->assertArrayHasKey('accepted_qty', $projection);
        $this->assertArrayHasKey('in_transit_qty', $projection);
        $this->assertArrayHasKey('arrived_pending_qc_qty', $projection);
    }

    /**
     * P-19: Cross-supplier update rejected with 403.
     */
    public function test_p19_cross_supplier_update_rejected_with_403(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // Supplier B attempts to update Supplier A's PO item
        $response = $this->actingAs($this->supplierB)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
                'note' => 'Malicious update attempt',
            ]
        );

        $response->assertForbidden();
    }

    /**
     * P-20: Foreign award/PO mismatch rejected with 403 or 404.
     */
    public function test_p20_foreign_award_po_mismatch_rejected(): void
    {
        $po1 = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $po2 = $this->createPoWithItems($this->supplierA, [[15.0, 15]]);

        $award1 = $po1->awards->first();

        // Post award1 against po2
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po2, 'award_id' => $award1]),
            [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            ]
        );

        $response->assertForbidden();
    }

    /**
     * P-21: Purchasing role cannot update supplier progress endpoint.
     */
    public function test_p21_purchasing_role_cannot_update_supplier_progress_endpoint(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $response = $this->actingAs($this->purchasing)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            ]
        );

        $response->assertForbidden();
    }

    /**
     * P-22: Committed update notifies purchasing.
     */
    public function test_p22_committed_update_notifies_purchasing(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $initialNotifCount = $this->purchasing->notifications()->count();

        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
                'estimated_ready_date' => now()->addDays(10)->toDateString(),
                'note' => 'Order verified and confirmed.',
            ]
        );
        $response->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame($initialNotifCount + 1, $this->purchasing->notifications()->count());
        $notif = $this->purchasing->notifications()->latest()->first();
        $this->assertSame('po_item_progress.updated', $notif->data['event']);
        $this->assertSame($po->id, $notif->data['po_id']);
        $this->assertSame($award->id, $notif->data['award_id']);
    }

    /**
     * P-23: Uncommitted or failed update does not dispatch notification.
     */
    public function test_p23_uncommitted_or_failed_update_does_not_dispatch_notification(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
        ]);

        $initialNotifCount = $this->purchasing->notifications()->count();

        // Backward without note -> fails validation
        $response = $this->actingAs($this->supplierA)->post(
            route('supplier.purchase-orders.item-progress.update', ['po_id' => $po, 'award_id' => $award]),
            [
                'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
                'note' => '',
            ]
        );

        $response->assertSessionHasErrors('note');
        $this->assertSame($initialNotifCount, $this->purchasing->notifications()->count());
    }

    /**
     * P-24: History endpoint returns JSON and guards authorization.
     */
    public function test_p24_history_endpoint_returns_json_and_guards_authorization(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            'note' => 'Confirmed',
        ]);

        // Supplier A can view history
        $respSupplier = $this->actingAs($this->supplierA)->getJson(
            route('supplier.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $award])
        );
        $respSupplier->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'history')
            ->assertJsonPath('projection.manual_progress_status', PoItemProgressUpdate::STAGE_ORDER_CONFIRMED);

        // Purchasing can view read-only history
        $respPurchasing = $this->actingAs($this->purchasing)->getJson(
            route('purchasing.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $award])
        );
        $respPurchasing->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'history');

        // Supplier B cannot view Supplier A's history
        $respSupplierB = $this->actingAs($this->supplierB)->getJson(
            route('supplier.purchase-orders.item-progress.history', ['po_id' => $po, 'award_id' => $award])
        );
        $respSupplierB->assertForbidden();
    }

    /**
     * P-25: Original quotation estimated_delivery remains unchanged.
     */
    public function test_p25_original_quotation_estimated_delivery_remains_unchanged(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $quotation = $award->quotation;

        $originalReadyDate = $quotation->estimated_delivery->toDateString();
        $newForecastDate = now()->addDays(40)->toDateString();

        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
            'estimated_ready_date' => $newForecastDate,
            'note' => 'Extended supplier ready date forecast',
        ]);

        $this->assertSame($originalReadyDate, $quotation->fresh()->estimated_delivery->toDateString());
    }

    /**
     * P-26: Progress estimated_ready_date changes independently.
     */
    public function test_p26_progress_estimated_ready_date_changes_independently(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $newDate = now()->addDays(25)->toDateString();

        $update = $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_MATERIAL_PREPARATION,
            'estimated_ready_date' => $newDate,
        ]);

        $this->assertSame($newDate, $update->estimated_ready_date->toDateString());
        $projection = $this->progressService->projectionForAward($award);
        $this->assertSame($newDate, $projection['current_estimated_ready_date']->toDateString());
    }

    /**
     * P-27: PO estimated_arrival remains unchanged by progress update.
     */
    public function test_p27_po_estimated_arrival_remains_unchanged_by_progress_update(): void
    {
        $poArrival = now()->addDays(30)->toDateString();
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]], $poArrival);
        $award = $po->awards->first();

        $this->assertSame($poArrival, $po->estimated_arrival->toDateString());

        $this->progressService->updateProgress($po, $award, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_ON_PRODUCTION,
            'estimated_ready_date' => now()->addDays(15)->toDateString(),
        ]);

        $this->assertSame($poArrival, $po->fresh()->estimated_arrival->toDateString());
    }

    /**
     * P-28 and P-29: Shipment ETA and actual arrival remain per-shipment.
     */
    public function test_p28_and_p29_shipment_eta_and_actual_arrival_remain_per_shipment(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;

        $shipmentEta = now()->addDays(7)->toDateString();

        $draft = $this->shipmentService->createDraft($this->supplierA, [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => $shipmentEta,
        ]);

        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 10, 'actual_weight_kg' => 10.0],
            ],
        ], $this->supplierA);

        $this->assertSame($shipmentEta, $shipment->estimated_arrival_date->toDateString());
        $this->assertNull($shipment->actual_arrival_date);

        // Confirm arrival sets shipment actual_arrival_date
        $arrived = $this->shipmentService->confirmArrival($shipment, $this->purchasing);
        $this->assertNotNull($arrived->actual_arrival_date);
    }

    /**
     * P-30: Submitted shipment lifecycle label is "In Transit".
     */
    public function test_p30_submitted_shipment_lifecycle_label_is_in_transit(): void
    {
        $this->assertSame('In Transit', StatusHelper::shipmentLifecycleLabel(Shipment::STATUS_SUBMITTED));
        $this->assertSame('Draft', StatusHelper::shipmentLifecycleLabel(Shipment::STATUS_DRAFT));
        $this->assertSame('Arrived', StatusHelper::shipmentLifecycleLabel(Shipment::STATUS_ARRIVED));
        $this->assertSame('Cancelled', StatusHelper::shipmentLifecycleLabel(Shipment::STATUS_CANCELLED));

        $badge = StatusHelper::shipmentLifecycleBadge(Shipment::STATUS_SUBMITTED);
        $this->assertSame('bg-primary', $badge);
        $this->assertSame('info', StatusHelper::shipmentLifecycleTone(Shipment::STATUS_SUBMITTED));
    }

    /**
     * P-31: Partially accepted PO remains Active/Waiting QC, not Completed.
     */
    public function test_p31_partially_accepted_po_remains_active_or_waiting_qc_not_completed(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();
        $qItem = $award->quotationItem;
        $prItem = $award->prItem;

        // Deliver 8 of 20
        $draft = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($draft, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 8, 'actual_weight_kg' => 8.0],
            ],
        ], $this->supplierA);
        $this->shipmentService->confirmArrival($shipment, $this->purchasing);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_d_outer' => 120.0,
                    'actual_length' => 200.0,
                    'actual_weight' => 8.0,
                    'status' => 'ok',
                ],
            ],
        ]);

        $po->refresh();
        $this->assertNotSame('completed', $po->status);
        $this->assertSame('active', $po->status);
    }

    /**
     * P-32: Mixed item states produce mixed PO summary.
     */
    public function test_p32_mixed_item_states_produce_mixed_po_summary(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20], [15.0, 15]]);
        $award1 = $po->awards[0];
        $award2 = $po->awards[1];

        // Item 1 -> Order Confirmed
        $this->progressService->updateProgress($po, $award1, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
        ]);

        // Item 2 -> Ready to Ship
        $this->progressService->updateProgress($po, $award2, $this->supplierA, [
            'status' => PoItemProgressUpdate::STAGE_READY_TO_SHIP,
        ]);

        $summary = $this->progressService->poSummary($po);
        $this->assertFalse($summary['is_homogeneous']);
        $this->assertStringContainsString('Order Confirmed', $summary['text']);
        $this->assertStringContainsString('Ready to Ship', $summary['text']);
    }

    /**
     * F-01 & F-06: Blade views must render valid hashed route URLs for progress updates and history.
     */
    public function test_f01_blade_view_renders_hashed_urls_and_allows_form_submission(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // 1. Supplier PO show view
        $response = $this->actingAs($this->supplierA)
            ->get(route('supplier.purchase-orders.show', $po));

        $response->assertOk();
        $html = $response->getContent();

        // Assert that the raw numeric integer award ID is NOT used in the URL
        $rawAction = "/supplier/purchase-orders/{$po->hash}/items/{$award->id}/progress";
        $hashedAction = "/supplier/purchase-orders/{$po->hash}/items/{$award->hash}/progress";

        $this->assertStringNotContainsString($rawAction, $html, 'Blade view incorrectly leaked raw integer award ID in action URL.');
        $this->assertStringContainsString($hashedAction, $html, 'Blade view failed to render hashed award ID in action URL.');

        // History URL in supplier view
        $rawHistoryUrl = "/supplier/purchase-orders/{$po->hash}/items/{$award->id}/progress/history";
        $hashedHistoryUrl = "/supplier/purchase-orders/{$po->hash}/items/{$award->hash}/progress/history";
        $this->assertStringNotContainsString($rawHistoryUrl, $html);
        $this->assertStringContainsString($hashedHistoryUrl, $html);

        // Submit form to the hashed URL rendered in the view
        $postResponse = $this->actingAs($this->supplierA)
            ->post($hashedAction, [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            ]);

        $postResponse->assertSessionHasNoErrors();
        $postResponse->assertRedirect();
        $postResponse->assertStatus(302);
    }

    /**
     * F-01: Purchasing PO show view must render valid hashed history URL.
     */
    public function test_f01_purchasing_blade_view_renders_hashed_history_url(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po));

        $response->assertOk();
        $html = $response->getContent();

        $rawHistoryUrl = "/purchase-orders/{$po->hash}/items/{$award->id}/progress/history";
        $hashedHistoryUrl = "/purchase-orders/{$po->hash}/items/{$award->hash}/progress/history";

        $this->assertStringNotContainsString($rawHistoryUrl, $html);
        $this->assertStringContainsString($hashedHistoryUrl, $html);
    }

    /**
     * F-04: Domain service must strictly reject non-supplier users (fail secure).
     */
    public function test_f04_service_strictly_rejects_non_supplier_mutation(): void
    {
        $po = $this->createPoWithItems($this->supplierA, [[20.0, 20]]);
        $award = $po->awards->first();

        // Attempt mutation with Purchasing user
        try {
            $this->progressService->updateProgress($po, $award, $this->purchasing, [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            ]);
            $this->fail('Expected HttpException 403 was not thrown for purchasing user.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        // Attempt mutation with Admin user
        $admin = User::where('role', 'admin')->first() ?? User::factory()->create(['role' => 'admin']);
        try {
            $this->progressService->updateProgress($po, $award, $admin, [
                'status' => PoItemProgressUpdate::STAGE_ORDER_CONFIRMED,
            ]);
            $this->fail('Expected HttpException 403 was not thrown for admin user.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    /**
     * F-05: PrItemAward::latestProgressUpdate() explicitly specifies foreign key.
     */
    public function test_f05_award_latest_progress_update_foreign_key(): void
    {
        $award = new PrItemAward;
        $relation = $award->latestProgressUpdate();

        $this->assertSame('pr_item_award_id', $relation->getForeignKeyName());
    }
}
