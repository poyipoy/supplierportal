<?php

namespace Tests\Feature;

use App\Models\ExchangeRate;
use App\Models\Period;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\ShipmentItem;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\TestCase;

class ShipmentAndPartialDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplierA;

    private User $supplierB;

    private ShipmentService $shipmentService;

    private PurchaseOrderGenerationService $poService;

    private PrItemAwardService $awardService;

    private ExchangeRate $exchangeRate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);

        $this->awardService = app(PrItemAwardService::class);
        $this->poService = app(PurchaseOrderGenerationService::class);
        $this->shipmentService = app(ShipmentService::class);

        $this->exchangeRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasing->id,
        ]);
    }

    public function test_can_create_draft_shipment_with_default_documents(): void
    {
        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'notes' => 'Draft test shipment',
        ]);

        $this->assertDatabaseHas('shipments', [
            'id' => $shipment->id,
            'supplier_id' => $this->supplierA->id,
            'status' => Shipment::STATUS_DRAFT,
        ]);

        // Default 4 documents initialized
        $this->assertCount(4, $shipment->documents);
        $this->assertSame(
            ['invoice', 'packing_list', 'bl', 'form_e'],
            $shipment->documents->pluck('doc_type')->all()
        );
    }

    public function test_unselected_blank_rows_are_ignored_by_shipment_form_validation(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $quotationItem = $po->awards->first()->quotationItem;

        $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(14)->toDateString(),
            'notes' => 'Form payload with unselected rows',
            'action' => 'draft',
            'items' => [
                0 => [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $quotationItem->id,
                    'shipped_qty' => 8,
                    'actual_weight_kg' => 8.0,
                ],
                1 => [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $quotationItem->id,
                    'shipped_qty' => '',
                    'actual_weight_kg' => '',
                ],
                2 => [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $quotationItem->id,
                    'shipped_qty' => null,
                    'actual_weight_kg' => null,
                ],
            ],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipment_items', [
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $quotationItem->id,
            'shipped_qty' => 8,
        ]);
    }

    public function test_single_po_shipment(): void
    {
        $poA = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItemA = $poA->awards->first()->quotationItem;

        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $submitted = $this->shipmentService->submitShipment($shipment, [
            'items' => [
                [
                    'purchase_order_id' => $poA->id,
                    'quotation_item_id' => $qItemA->id,
                    'shipped_qty' => 20,
                    'actual_weight_kg' => 20.0,
                ],
            ],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertCount(1, $submitted->items);
        $this->assertSame(20, $submitted->items->first()->shipped_qty);
        $this->assertEquals(20.0, $submitted->items->first()->actual_weight_kg);

        $deliveryStatus = $this->shipmentService->getItemDeliveryStatus($poA->id, $qItemA->id);
        $this->assertEquals(20, $deliveryStatus['ordered']);
        $this->assertEquals(20, $deliveryStatus['allocated']);
        $this->assertEquals(0, $deliveryStatus['remaining']);
        $this->assertTrue($deliveryStatus['is_fully_allocated']);
    }

    public function test_multi_po_same_supplier_shipment(): void
    {
        // Two separate POs belonging to the same supplier
        $po1 = $this->createPoForSupplier($this->supplierA, 20.0);
        $po2 = $this->createPoForSupplier($this->supplierA, 10.0);

        $qItem1 = $po1->awards->first()->quotationItem;
        $qItem2 = $po2->awards->first()->quotationItem;

        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $submitted = $this->shipmentService->submitShipment($shipment, [
            'items' => [
                [
                    'purchase_order_id' => $po1->id,
                    'quotation_item_id' => $qItem1->id,
                    'shipped_qty' => 12,
                    'actual_weight_kg' => 12.0,
                ],
                [
                    'purchase_order_id' => $po2->id,
                    'quotation_item_id' => $qItem2->id,
                    'shipped_qty' => 10,
                    'actual_weight_kg' => 10.0,
                ],
            ],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertCount(2, $submitted->items);

        // One shipment contains both POs
        $pos = $submitted->purchaseOrders();
        $this->assertCount(2, $pos);
        $this->assertTrue($pos->contains($po1));
        $this->assertTrue($pos->contains($po2));
    }

    public function test_different_supplier_po_rejected_in_shipment(): void
    {
        $poA = $this->createPoForSupplier($this->supplierA, 20.0);
        $poB = $this->createPoForSupplier($this->supplierB, 15.0);

        $qItemA = $poA->awards->first()->quotationItem;
        $qItemB = $poB->awards->first()->quotationItem;

        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multi-supplier shipments are forbidden');

        $this->shipmentService->submitShipment($shipment, [
            'items' => [
                [
                    'purchase_order_id' => $poA->id,
                    'quotation_item_id' => $qItemA->id,
                    'shipped_qty' => 10,
                    'actual_weight_kg' => 10.0,
                ],
                [
                    'purchase_order_id' => $poB->id,
                    'quotation_item_id' => $qItemB->id,
                    'shipped_qty' => 5,
                    'actual_weight_kg' => 5.0,
                ],
            ],
        ]);
    }

    public function test_partial_delivery_across_multiple_shipments(): void
    {
        // Ordered: 20 pcs
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        // Shipment 1: 8 pcs
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 8, 'actual_weight_kg' => 8.0],
            ],
        ]);

        $status1 = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertEquals(8, $status1['allocated']);
        $this->assertEquals(12, $status1['remaining']);
        $this->assertFalse($status1['is_fully_allocated']);

        // Shipment 2: 7 pcs
        $shp2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 7, 'actual_weight_kg' => 7.0],
            ],
        ]);

        $status2 = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertEquals(15, $status2['allocated']);
        $this->assertEquals(5, $status2['remaining']);

        // Shipment 3: 5 pcs (remaining fulfilled)
        $shp3 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp3, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
            ],
        ]);

        $status3 = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertEquals(20, $status3['allocated']);
        $this->assertEquals(0, $status3['remaining']);
        $this->assertTrue($status3['is_fully_allocated']);
    }

    public function test_over_allocation_strictly_rejected(): void
    {
        // Ordered: 20 pcs
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        // Shipment 1: 15 pcs
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 15, 'actual_weight_kg' => 15.0],
            ],
        ]);

        // Shipment 2 tries to allocate 6 pcs (15 + 6 = 21 > 20)
        $shp2 = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds remaining ordered');

        $this->shipmentService->submitShipment($shp2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 6, 'actual_weight_kg' => 6.0],
            ],
        ]);
    }

    public function test_cancelled_shipment_releases_allocation(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 15, 'actual_weight_kg' => 15.0],
            ],
        ]);

        $this->assertEquals(5, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);

        // Cancel Shipment 1
        $this->shipmentService->cancelShipment($shp1, $this->supplierA);

        // Allocation should be released back to 20
        $this->assertEquals(20, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);
    }

    public function test_concurrency_race_condition_protection(): void
    {
        // Ordered: 10 pcs
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        // Draft A requests 8 pcs
        $shpA = $this->shipmentService->createDraft($this->supplierA);
        // Draft B requests 7 pcs
        $shpB = $this->shipmentService->createDraft($this->supplierA);

        // Submit A succeeds (allocated 8 pcs, remaining 2 pcs)
        $this->shipmentService->submitShipment($shpA, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 8, 'actual_weight_kg' => 8.0],
            ],
        ]);

        // Submit B with 7 pcs must fail because only 2 pcs remain!
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds remaining ordered');

        $this->shipmentService->submitShipment($shpB, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 7, 'actual_weight_kg' => 7.0],
            ],
        ]);
    }

    public function test_saved_draft_submission_locks_lines_before_any_snapshot_and_revalidates_balance(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $items = [['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 10, 'actual_weight_kg' => 10.0]];
        $first = $this->shipmentService->createDraft($this->supplierA, ['items' => $items]);
        $second = $this->shipmentService->createDraft($this->supplierA, ['items' => $items]);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });
        $this->actingAs($this->supplierA)->post(route('supplier.shipments.submit', $first))
            ->assertRedirect()->assertSessionHas('success');
        $this->post(route('supplier.shipments.submit', $second))->assertRedirect()->assertSessionHas('error');
        $this->assertSame('submitted', $first->fresh()->status);
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertNull($second->fresh()->submitted_at);
        $this->assertSame(10, $second->items()->sole()->shipped_qty);
        $this->assertSame(10, $po->itemFulfillmentStatus($qItem->id)['allocated_qty']);
        $poLock = collect($queries)->search(fn ($sql) => str_contains($sql, 'from `purchase_orders`') && str_contains($sql, 'for update'));
        $this->assertNotFalse($poLock);
        $earlySelects = collect(array_slice($queries, 0, $poLock))
            ->filter(fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'shipment_items'));
        $this->assertNotEmpty($earlySelects);
        foreach ($earlySelects as $sql) {
            $this->assertStringContainsString('for update', $sql);
        }
    }

    public function test_cannot_allocate_item_belonging_to_different_po(): void
    {
        $poA = $this->createPoForSupplier($this->supplierA, 10.0);
        $poB = $this->createPoForSupplier($this->supplierA, 10.0);

        $qItemB = $poB->awards->first()->quotationItem;

        $shp = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to Purchase Order');

        $this->shipmentService->submitShipment($shp, [
            'items' => [
                ['purchase_order_id' => $poA->id, 'quotation_item_id' => $qItemB->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
            ],
        ]);
    }

    public function test_cannot_allocate_item_belonging_to_another_supplier(): void
    {
        $poA = $this->createPoForSupplier($this->supplierA, 10.0);
        $poB = $this->createPoForSupplier($this->supplierB, 10.0);

        $qItemB = $poB->awards->first()->quotationItem;

        $shp = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);

        $this->shipmentService->submitShipment($shp, [
            'items' => [
                ['purchase_order_id' => $poA->id, 'quotation_item_id' => $qItemB->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
            ],
        ]);
    }

    public function test_cannot_sync_draft_with_mismatched_po_and_item(): void
    {
        $poA = $this->createPoForSupplier($this->supplierA, 10.0);
        $poB = $this->createPoForSupplier($this->supplierA, 10.0);

        $qItemB = $poB->awards->first()->quotationItem;

        $shp = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not belong to Purchase Order');

        $this->shipmentService->syncDraftItems($shp, [
            ['purchase_order_id' => $poA->id, 'quotation_item_id' => $qItemB->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
        ]);
    }

    public function test_duplicate_shipment_line_rejected_in_draft_sync(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        $shp = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate item entries detected');

        $this->shipmentService->syncDraftItems($shp, [
            ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 3, 'actual_weight_kg' => 3.0],
            ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 4, 'actual_weight_kg' => 4.0],
        ]);
    }

    public function test_duplicate_shipment_line_cannot_bypass_quantity_ceiling(): void
    {
        // 8 pcs remaining
        $po = $this->createPoForSupplier($this->supplierA, 8.0);
        $qItem = $po->awards->first()->quotationItem;

        $shp = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate item entries detected');

        // Payload with 5 + 5 for an 8 unit remaining limit
        $this->shipmentService->submitShipment($shp, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
            ],
        ]);
    }

    public function test_database_uniqueness_constraint_prevents_duplicate_shipment_line(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shp = $this->shipmentService->createDraft($this->supplierA);

        ShipmentItem::create([
            'shipment_id' => $shp->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 3,
            'actual_weight_kg' => 3.0,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ShipmentItem::create([
            'shipment_id' => $shp->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 4,
            'actual_weight_kg' => 4.0,
        ]);
    }

    public function test_database_constraints_reject_non_positive_quantity_and_duplicate_document_type(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        // Reject zero shipped_qty
        try {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'pr_item_award_id' => $po->awards->first()->id,
                'shipped_qty' => 0,
                'actual_weight_kg' => 10.0,
            ]);
            $this->fail('Expected the database shipped_qty CHECK constraint to reject zero.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('shipment_items', [
                'shipment_id' => $shipment->id,
                'quotation_item_id' => $qItem->id,
            ]);
        }

        // Reject zero actual_weight_kg
        try {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'pr_item_award_id' => $po->awards->first()->id,
                'shipped_qty' => 1,
                'actual_weight_kg' => 0.0,
            ]);
            $this->fail('Expected the database actual_weight_kg CHECK constraint to reject zero.');
        } catch (QueryException) {
            $this->assertDatabaseMissing('shipment_items', [
                'shipment_id' => $shipment->id,
                'quotation_item_id' => $qItem->id,
            ]);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        ShipmentDocument::create([
            'shipment_id' => $shipment->id,
            'doc_type' => ShipmentDocument::DOC_TYPE_INVOICE,
            'status' => ShipmentDocument::STATUS_PENDING,
        ]);
    }

    public function test_delivery_progress_attribute_for_partial_and_full_deliveries_and_legacy_po(): void
    {
        // 1. Shipment-aware PO: Ordered 20 pcs
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        // 0 / 20 shipped -> not_shipped
        $this->assertSame('not_shipped', $po->fresh()->delivery_progress);

        // Submit 5 pcs -> partially_shipped
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 5, 'actual_weight_kg' => 5.0],
            ],
        ]);
        $this->assertSame('partially_shipped', $po->fresh()->delivery_progress);

        // Confirm arrival of 5 pcs -> purchase_orders.actual_arrival is set!
        $this->shipmentService->confirmArrival($shp1, $this->purchasing);
        $po->refresh();
        $this->assertNotNull($po->actual_arrival);
        $this->assertSame('partially_shipped', $po->delivery_progress);

        // Submit remaining 15 pcs -> fully_shipped (total active = 20 pcs, but only 5 arrived)
        $shp2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 15, 'actual_weight_kg' => 15.0],
            ],
        ]);
        $this->assertSame('fully_shipped', $po->fresh()->delivery_progress);

        // Confirm arrival of second shipment (now 20 / 20 arrived) -> received!
        $this->shipmentService->confirmArrival($shp2, $this->purchasing);
        $this->assertSame('received', $po->fresh()->delivery_progress);

        // 2. Legacy PO without shipment records
        $legacyPo = PurchaseOrder::create([
            'po_number' => 'PO/09/2026/999',
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'actual_arrival' => null,
        ]);
        $this->assertSame('not_shipped', $legacyPo->delivery_progress);

        $legacyPo->update(['actual_arrival' => now()->toDateString()]);
        $this->assertSame('received', $legacyPo->fresh()->delivery_progress);
    }

    public function test_supplier_can_edit_and_update_only_own_draft_shipment(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(7)->toDateString(),
            'notes' => 'Original draft',
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]],
        ]);

        $this->actingAs($this->supplierA)
            ->get(route('supplier.shipments.edit', $shipment))
            ->assertOk()
            ->assertSee('Original draft');
        $this->actingAs($this->supplierB)
            ->get(route('supplier.shipments.edit', $shipment))
            ->assertForbidden();

        $response = $this->actingAs($this->supplierA)
            ->put(route('supplier.shipments.update', $shipment), [
                'shipment_date' => now()->addDay()->toDateString(),
                'estimated_arrival_date' => now()->addDays(8)->toDateString(),
                'notes' => 'Updated draft',
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 6,
                    'actual_weight_kg' => 6.0,
                ]],
            ]);

        $response->assertRedirect(route('supplier.shipments.show', $shipment))->assertSessionHas('success');
        $this->assertSame('Updated draft', $shipment->fresh()->notes);
        $this->assertSame(6, $shipment->items()->firstOrFail()->shipped_qty);
    }

    public function test_non_draft_shipment_cannot_be_updated_and_destroy_route_is_not_registered(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);
        $shipment = $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]],
        ]);

        $response = $this->actingAs($this->supplierA)
            ->put(route('supplier.shipments.update', $shipment), [
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                'notes' => 'Illegal update',
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 5,
                    'actual_weight_kg' => 5.0,
                ]],
            ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertNotSame('Illegal update', $shipment->fresh()->notes);
        $this->assertFalse(Route::has('supplier.shipments.destroy'));
    }

    public function test_pr_qty_ten_with_supplier_available_qty_eight_resolves_authoritative_ordered_qty_eight(): void
    {
        // PR quantity 10, supplier available_qty 8 -> PO line authoritative ordered Qty is 8
        $po = $this->createPoForSupplier($this->supplierA, 80.0, 10, 8);
        $qItem = $po->awards->first()->quotationItem;

        $this->assertSame(8, $qItem->fulfillment_quantity);
        $status = $po->itemFulfillmentStatus($qItem->id);
        $this->assertSame(8, $status['ordered_qty']);
        $this->assertSame(8, $status['remaining_qty']);
        $this->assertSame(0, $status['allocated_qty']);
        $this->assertSame(8, $po->total_ordered_quantity);
    }

    public function test_positive_integer_shipment_qty_accepted_and_persisted(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(7)->toDateString(),
            'action' => 'submit',
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 5,
                'actual_weight_kg' => 12.3456,
            ]],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('shipment_items', [
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 5,
            'actual_weight_kg' => '12.3456',
        ]);
    }

    public function test_fractional_shipment_qty_rejected_in_http_validation(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        foreach (['1.5', '2.0001', '0.5'] as $fractional) {
            $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                'action' => 'draft',
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => $fractional,
                    'actual_weight_kg' => 10.0,
                ]],
            ]);

            $response->assertSessionHasErrors('items.0.shipped_qty');
        }
    }

    public function test_zero_and_negative_shipment_qty_rejected_in_http_validation(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        foreach ([0, -1, -5] as $invalidQty) {
            $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                'action' => 'draft',
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => $invalidQty,
                    'actual_weight_kg' => 10.0,
                ]],
            ]);

            $response->assertSessionHasErrors('items.0.shipped_qty');
        }
    }

    public function test_actual_weight_kg_http_validation_rules(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        // Rejects 0, negative, or more than 4 decimal places
        foreach (['0', '-1', '10.12345'] as $invalidWeight) {
            $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                'action' => 'draft',
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 5,
                    'actual_weight_kg' => $invalidWeight,
                ]],
            ]);

            $response->assertSessionHasErrors('items.0.actual_weight_kg');
        }
    }

    public function test_missing_actual_weight_is_rejected_at_http_boundary(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        foreach (['draft', 'submit'] as $action) {
            $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
                'shipment_date' => now()->toDateString(),
                'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                'action' => $action,
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 5,
                ]],
            ]);

            $response->assertSessionHasErrors('items.0.actual_weight_kg');
        }

        $this->assertDatabaseCount('shipment_items', 0);
    }

    public function test_legacy_shipped_quantity_alias_cannot_satisfy_qty_or_weight_over_http(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        $response = $this->actingAs($this->supplierA)->post(route('supplier.shipments.store'), [
            'shipment_date' => now()->toDateString(),
            'estimated_arrival_date' => now()->addDays(7)->toDateString(),
            'action' => 'submit',
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => 5,
            ]],
        ]);

        $response->assertSessionHasErrors(['items.0.shipped_qty', 'items.0.actual_weight_kg']);
        $this->assertDatabaseCount('shipment_items', 0);
    }

    public function test_draft_sync_rejects_item_without_explicit_actual_weight(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Actual weight (kg) must be supplied explicitly');

        $this->shipmentService->syncDraftItems($shipment, [[
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_quantity' => 5,
        ]]);
    }

    public function test_submit_rejects_item_without_explicit_actual_weight(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Actual weight (kg) must be supplied explicitly');

        $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 5,
            ]],
        ]);
    }

    public function test_piece_count_is_never_persisted_as_actual_weight(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 100.0, 100, 100);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 100,
                'actual_weight_kg' => '12.3456',
            ]],
        ]);

        $item = ShipmentItem::where('shipment_id', $shipment->id)->firstOrFail();

        $this->assertSame(100, (int) $item->shipped_qty);
        $this->assertSame('12.3456', (string) $item->actual_weight_kg);
        $this->assertNotEquals(100.0, (float) $item->actual_weight_kg);
    }

    public function test_partial_delivery_three_plus_five_completes_qty_eight(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 8.0, 8, 8);
        $qItem = $po->awards->first()->quotationItem;

        // Shipment 1: 3 pcs
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 3, 'actual_weight_kg' => 30.0]],
        ]);
        $this->assertSame(3, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['allocated_qty']);
        $this->assertSame(5, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining_qty']);

        // Shipment 2: 5 pcs
        $shp2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp2, [
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 5, 'actual_weight_kg' => 50.0]],
        ]);
        $status = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertSame(8, $status['allocated_qty']);
        $this->assertSame(0, $status['remaining_qty']);
        $this->assertTrue($status['is_fully_allocated']);
    }

    public function test_actual_kg_differences_do_not_change_remaining_qty_or_completion(): void
    {
        // PO ordered 8 pcs
        $po = $this->createPoForSupplier($this->supplierA, 8.0, 8, 8);
        $qItem = $po->awards->first()->quotationItem;

        // Ship 4 pcs with large physical weight
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 4, 'actual_weight_kg' => 500.0]],
        ]);
        $this->shipmentService->confirmArrival($shp1, $this->purchasing);

        // Remaining must be exactly 4 pcs, unaffected by 500 kg
        $this->assertSame(4, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining_qty']);
        $this->assertSame(4, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['allocated_qty']);

        // Ship remaining 4 pcs with tiny physical weight
        $shp2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp2, [
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 4, 'actual_weight_kg' => 0.5]],
        ]);
        $this->shipmentService->confirmArrival($shp2, $this->purchasing);

        // Entire 8 pcs arrived, remaining is 0 pcs
        $this->assertSame(0, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining_qty']);
        $this->assertSame('received', $po->fresh()->delivery_progress);
    }

    public function test_draft_shipment_does_not_consume_qty_and_submitted_shipment_consumes_qty(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        $draft = $this->shipmentService->createDraft($this->supplierA, [
            'items' => [['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_qty' => 6, 'actual_weight_kg' => 6.0]],
        ]);

        // Draft state does NOT allocate
        $this->assertSame(0, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['allocated_qty']);
        $this->assertSame(10, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining_qty']);

        // Submit consumes allocation
        $this->shipmentService->submitShipment($draft);
        $this->assertSame(6, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['allocated_qty']);
        $this->assertSame(4, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining_qty']);
    }

    public function test_award_based_po_without_shipments_cannot_use_legacy_arrival(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po));

        $response->assertRedirect(route('purchasing.purchase-orders.show', $po))
            ->assertSessionHas(
                'error',
                'This Purchase Order uses shipment-based receiving. Confirm physical arrival from the relevant Shipment.'
            );

        $po->refresh();
        $this->assertNull($po->actual_arrival);
        $this->assertSame('active', $po->status);
        $this->assertSame(0, $qc->notifications()->count());
    }

    public function test_award_based_po_with_a_draft_shipment_cannot_use_legacy_arrival(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $po = $po->fresh();
        $this->assertNull($po->actual_arrival);
        $this->assertSame('active', $po->status);
        $this->assertSame(Shipment::STATUS_DRAFT, $shipment->fresh()->status);
    }

    public function test_legacy_arrival_remains_available_for_genuine_legacy_po(): void
    {
        $qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
        $po = PurchaseOrder::create([
            'po_number' => 'PO/09/2026/LEGACY',
            'supplier_id' => $this->supplierA->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'actual_arrival' => null,
        ]);

        $this->assertTrue($po->isLegacyArrivalEligible());

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertSee('id="btnConfirmArrival"', false);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect(route('purchasing.purchase-orders.show', $po));

        $po->refresh();
        $this->assertNotNull($po->actual_arrival);
        $this->assertSame('waiting_qc', $po->status);
        $this->assertSame('po.material_arrived', $qc->notifications()->sole()->data['event']);
    }

    public function test_legacy_arrival_is_blocked_once_a_po_has_shipment_items(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $po->awards()->delete();
        $this->assertFalse($po->awards()->exists());
        $shipment = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 5,
                'actual_weight_kg' => 5.0,
            ]],
        ]);

        $response = $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po));

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertNull($po->fresh()->actual_arrival);
        $this->assertSame('active', $po->fresh()->status);
        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->status);
    }

    public function test_legacy_arrival_is_blocked_after_arrived_shipment(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 10,
                'actual_weight_kg' => 10.0,
            ]],
        ]);
        $this->shipmentService->confirmArrival($shipment, $this->purchasing);

        $po->refresh();
        $arrivalDate = $po->actual_arrival;
        $status = $po->status;

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame($arrivalDate?->toDateString(), $po->fresh()->actual_arrival?->toDateString());
        $this->assertSame($status, $po->fresh()->status);
        $this->assertSame(Shipment::STATUS_ARRIVED, $shipment->fresh()->status);
    }

    public function test_legacy_arrival_is_blocked_after_cancelled_shipment_history(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 5,
                'actual_weight_kg' => 5.0,
            ]],
        ]);
        $this->shipmentService->cancelShipment($shipment, $this->supplierA);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($po->fresh()->actual_arrival);
        $this->assertSame('active', $po->fresh()->status);
        $this->assertSame(Shipment::STATUS_CANCELLED, $shipment->fresh()->status);
    }

    public function test_po_detail_hides_legacy_arrival_for_award_based_po(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk()
            ->assertDontSee('id="btnConfirmArrival"', false)
            ->assertDontSee('id="arrivalForm"', false);
    }

    public function test_legacy_arrival_first_rejects_first_shipment_and_rolls_back_draft(): void
    {
        [$po, $qItem] = $this->createLegacyPoForShipment($this->supplierA, 10.0);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect();

        $po->refresh();
        $shipmentsBefore = Shipment::count();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already received through the legacy receiving flow');

        try {
            $this->shipmentService->createDraft($this->supplierA, [
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_qty' => 4,
                    'actual_weight_kg' => 4.0,
                ]],
            ]);
        } finally {
            $this->assertSame($shipmentsBefore, Shipment::count());
            $this->assertDatabaseMissing('shipment_items', [
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
            ]);
            $this->assertNotNull($po->fresh()->actual_arrival);
            $this->assertSame('waiting_qc', $po->fresh()->status);
        }
    }

    public function test_first_shipment_participation_blocks_legacy_arrival(): void
    {
        [$po, $qItem] = $this->createLegacyPoForShipment($this->supplierA, 10.0);
        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]],
        ]);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($po->fresh()->actual_arrival);
        $this->assertSame('active', $po->fresh()->status);
        $this->assertSame(Shipment::STATUS_DRAFT, $shipment->fresh()->status);
        $this->assertDatabaseHas('shipment_items', [
            'shipment_id' => $shipment->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
        ]);
    }

    public function test_submit_rejects_pre_existing_mixed_legacy_arrival_draft(): void
    {
        [$po, $qItem] = $this->createLegacyPoForShipment($this->supplierA, 10.0);
        $shipment = $this->shipmentService->createDraft($this->supplierA, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]],
        ]);
        $po->update([
            'actual_arrival' => now()->toDateString(),
            'status' => 'waiting_qc',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already received through the legacy receiving flow');

        try {
            $this->shipmentService->submitShipment($shipment);
        } finally {
            $this->assertSame(Shipment::STATUS_DRAFT, $shipment->fresh()->status);
            $this->assertNull($shipment->fresh()->submitted_at);
            $this->assertSame('waiting_qc', $po->fresh()->status);
            $this->assertNotNull($po->fresh()->actual_arrival);
            $this->assertDatabaseHas('shipment_items', [
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
            ]);
        }
    }

    public function test_partial_shipment_after_shipment_arrival_remains_allowed_for_legacy_po(): void
    {
        [$po, $qItem] = $this->createLegacyPoForShipment($this->supplierA, 10.0);
        $first = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($first, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]],
        ]);
        $this->shipmentService->confirmArrival($first, $this->purchasing);

        $this->assertNotNull($po->fresh()->actual_arrival);

        $second = $this->shipmentService->createDraft($this->supplierA);
        $submitted = $this->shipmentService->submitShipment($second, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 6,
                'actual_weight_kg' => 6.0,
            ]],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertDatabaseHas('shipment_items', [
            'shipment_id' => $second->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 6,
        ]);
    }

    public function test_cancelled_shipment_history_blocks_legacy_fallback_and_allows_new_allocation(): void
    {
        [$po, $qItem] = $this->createLegacyPoForShipment($this->supplierA, 10.0);
        $cancelled = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($cancelled, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]],
        ]);
        $this->shipmentService->cancelShipment($cancelled, $this->supplierA);

        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull($po->fresh()->actual_arrival);
        $this->assertSame(Shipment::STATUS_CANCELLED, $cancelled->fresh()->status);

        $replacement = $this->shipmentService->createDraft($this->supplierA);
        $submitted = $this->shipmentService->submitShipment($replacement, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 10,
                'actual_weight_kg' => 10.0,
            ]],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
    }

    public function test_sync_draft_items_rejects_legacy_only_arrival_without_outer_transaction(): void
    {
        [$po, $qItem] = $this->createLegacyPoForShipment($this->supplierA, 10.0);
        $this->actingAs($this->purchasing)
            ->post(route('purchasing.purchase-orders.confirm-arrival', $po))
            ->assertRedirect();
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('already received through the legacy receiving flow');

        try {
            $this->shipmentService->syncDraftItems($shipment, [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_qty' => 4,
                'actual_weight_kg' => 4.0,
            ]]);
        } finally {
            $this->assertDatabaseMissing('shipment_items', [
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
            ]);
        }
    }

    private function createPoForSupplier(User $supplier, float $totalWeight, ?int $quantity = null, ?int $availableQty = null): PurchaseOrder
    {
        $orderedQty = $quantity ?? (int) $totalWeight;
        $availQty = $availableQty ?? (int) $totalWeight;

        $period = Period::create([
            'name' => 'Shipment Period '.rand(100, 9999),
            'month' => 9,
            'year' => 2026,
            'status' => 'open',
            'created_by' => $this->purchasing->id,
        ]);

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'created_by' => $this->purchasing->id,
            'pr_number' => 'REQ/09/2026/'.str_pad(
                (string) (PurchaseRequisition::withTrashed()->count() + 1),
                3,
                '0',
                STR_PAD_LEFT
            ),
            'status' => 'bidding',
            'notes' => 'Shipment test requisition',
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Steel Plate '.rand(1, 100),
            'quantity' => $orderedQty,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 2.0,
            'width' => 100,
            'length' => 200,
            'weight_needed' => $totalWeight,
        ]);

        $q = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'exchange_rate_id' => $this->exchangeRate->id,
            'status' => Quotation::STATUS_SUBMITTED,
            'estimated_delivery' => now()->addDays(14),
            'payment_terms' => 'TT 30 Days',
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        $qItem = $q->items()->create([
            'pr_item_id' => $prItem->id,
            'is_available' => true,
            'price_per_kg' => 2.5,
            'amount' => 2.5 * $totalWeight,
            'available_qty' => $availQty,
        ]);

        $award = $this->awardService->awardItem($prItem, $qItem, $this->purchasing);
        $pos = $this->poService->generateFromAwards(collect([$award]), $this->purchasing);

        return $pos->first();
    }

    private function createLegacyPoForShipment(User $supplier, float $totalWeight, ?int $quantity = null, ?int $availableQty = null): array
    {
        $po = $this->createPoForSupplier($supplier, $totalWeight, $quantity, $availableQty);
        $award = $po->awards()->with('quotationItem')->firstOrFail();
        $qItem = $award->quotationItem;

        $po->awards()->delete();
        $po->unsetRelation('awards');

        return [$po->fresh(), $qItem];
    }
}
