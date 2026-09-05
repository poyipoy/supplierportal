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
                    'shipped_quantity' => '8.0000',
                ],
                1 => [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $quotationItem->id,
                    'shipped_quantity' => '',
                ],
                2 => [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $quotationItem->id,
                    'shipped_quantity' => null,
                ],
            ],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('shipments', 1);
        $this->assertDatabaseHas('shipment_items', [
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $quotationItem->id,
            'shipped_quantity' => '8.0000',
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
                    'shipped_quantity' => 20.0,
                ],
            ],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertCount(1, $submitted->items);
        $this->assertEquals(20.0, $submitted->items->first()->shipped_quantity);

        $deliveryStatus = $this->shipmentService->getItemDeliveryStatus($poA->id, $qItemA->id);
        $this->assertEquals(20.0, $deliveryStatus['ordered']);
        $this->assertEquals(20.0, $deliveryStatus['allocated']);
        $this->assertEquals(0.0, $deliveryStatus['remaining']);
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
                    'shipped_quantity' => 12.0,
                ],
                [
                    'purchase_order_id' => $po2->id,
                    'quotation_item_id' => $qItem2->id,
                    'shipped_quantity' => 10.0,
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
                    'shipped_quantity' => 10.0,
                ],
                [
                    'purchase_order_id' => $poB->id,
                    'quotation_item_id' => $qItemB->id,
                    'shipped_quantity' => 5.0,
                ],
            ],
        ]);
    }

    public function test_partial_delivery_across_multiple_shipments(): void
    {
        // Ordered: 20 ton
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        // Shipment 1: 8 ton
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 8.0],
            ],
        ]);

        $status1 = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertEquals(8.0, $status1['allocated']);
        $this->assertEquals(12.0, $status1['remaining']);
        $this->assertFalse($status1['is_fully_allocated']);

        // Shipment 2: 7 ton
        $shp2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 7.0],
            ],
        ]);

        $status2 = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertEquals(15.0, $status2['allocated']);
        $this->assertEquals(5.0, $status2['remaining']);

        // Shipment 3: 5 ton (remaining fulfilled)
        $shp3 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp3, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 5.0],
            ],
        ]);

        $status3 = $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id);
        $this->assertEquals(20.0, $status3['allocated']);
        $this->assertEquals(0.0, $status3['remaining']);
        $this->assertTrue($status3['is_fully_allocated']);
    }

    public function test_over_allocation_strictly_rejected(): void
    {
        // Ordered: 20 ton
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        // Shipment 1: 15 ton
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 15.0],
            ],
        ]);

        // Shipment 2 tries to allocate 6 ton (15 + 6 = 21 > 20)
        $shp2 = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds remaining ordered balance');

        $this->shipmentService->submitShipment($shp2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 6.0],
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
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 15.0],
            ],
        ]);

        $this->assertEquals(5.0, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);

        // Cancel Shipment 1
        $this->shipmentService->cancelShipment($shp1, $this->supplierA);

        // Allocation should be released back to 20.0
        $this->assertEquals(20.0, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);
    }

    public function test_concurrency_race_condition_protection(): void
    {
        // Ordered: 10 ton
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        // Draft A requests 8 ton
        $shpA = $this->shipmentService->createDraft($this->supplierA);
        // Draft B requests 7 ton
        $shpB = $this->shipmentService->createDraft($this->supplierA);

        // Submit A succeeds (allocated 8 ton, remaining 2 ton)
        $this->shipmentService->submitShipment($shpA, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 8.0],
            ],
        ]);

        // Submit B with 7 ton must fail because only 2 ton remain!
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds remaining ordered balance');

        $this->shipmentService->submitShipment($shpB, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 7.0],
            ],
        ]);
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
                ['purchase_order_id' => $poA->id, 'quotation_item_id' => $qItemB->id, 'shipped_quantity' => 5.0],
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
                ['purchase_order_id' => $poA->id, 'quotation_item_id' => $qItemB->id, 'shipped_quantity' => 5.0],
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
            ['purchase_order_id' => $poA->id, 'quotation_item_id' => $qItemB->id, 'shipped_quantity' => 5.0],
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
            ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 3.0],
            ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 4.0],
        ]);
    }

    public function test_duplicate_shipment_line_cannot_bypass_quantity_ceiling(): void
    {
        // 8 kg remaining
        $po = $this->createPoForSupplier($this->supplierA, 8.0);
        $qItem = $po->awards->first()->quotationItem;

        $shp = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate item entries detected');

        // Payload with 5 + 5 for an 8 unit remaining limit
        $this->shipmentService->submitShipment($shp, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 5.0],
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 5.0],
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
            'shipped_quantity' => 3.0,
        ]);

        $this->expectException(UniqueConstraintViolationException::class);

        ShipmentItem::create([
            'shipment_id' => $shp->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_quantity' => 4.0,
        ]);
    }

    public function test_database_constraints_reject_non_positive_quantity_and_duplicate_document_type(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        try {
            ShipmentItem::create([
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'pr_item_award_id' => $po->awards->first()->id,
                'shipped_quantity' => '0.0000',
            ]);
            $this->fail('Expected the database quantity CHECK constraint to reject zero.');
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
        // 1. Shipment-aware PO: Ordered 20 kg
        $po = $this->createPoForSupplier($this->supplierA, 20.0);
        $qItem = $po->awards->first()->quotationItem;

        // 0 / 20 shipped -> not_shipped
        $this->assertSame('not_shipped', $po->fresh()->delivery_progress);

        // Submit 5 kg -> partially_shipped
        $shp1 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp1, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 5.0],
            ],
        ]);
        $this->assertSame('partially_shipped', $po->fresh()->delivery_progress);

        // Confirm arrival of 5 kg -> purchase_orders.actual_arrival is set!
        $this->shipmentService->confirmArrival($shp1, $this->purchasing);
        $po->refresh();
        $this->assertNotNull($po->actual_arrival);
        // FIX 5: delivery_progress must STILL be partially_shipped, NOT received!
        $this->assertSame('partially_shipped', $po->delivery_progress);

        // Submit remaining 15 kg -> fully_shipped (total active = 20 kg, but only 5 arrived)
        $shp2 = $this->shipmentService->createDraft($this->supplierA);
        $this->shipmentService->submitShipment($shp2, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItem->id, 'shipped_quantity' => 15.0],
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
                'shipped_quantity' => '4.0000',
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
                    'shipped_quantity' => '6.0000',
                ]],
            ]);

        $response->assertRedirect(route('supplier.shipments.show', $shipment))->assertSessionHas('success');
        $this->assertSame('Updated draft', $shipment->fresh()->notes);
        $this->assertSame('6.0000', $shipment->items()->firstOrFail()->shipped_quantity);
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
                'shipped_quantity' => '4.0000',
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
                    'shipped_quantity' => '5.0000',
                ]],
            ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertNotSame('Illegal update', $shipment->fresh()->notes);
        $this->assertFalse(Route::has('supplier.shipments.destroy'));
    }

    public function test_quantity_comparison_accepts_exact_four_decimal_balance_and_rejects_overage(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 1.0);
        $qItem = $po->awards->first()->quotationItem;
        $exact = $this->shipmentService->createDraft($this->supplierA);

        $this->shipmentService->submitShipment($exact, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => '1.0000',
            ]],
        ]);

        $overage = $this->shipmentService->createDraft($this->supplierA);
        $this->expectException(InvalidArgumentException::class);
        $this->shipmentService->submitShipment($overage, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => '0.0001',
            ]],
        ]);
    }

    public function test_quantity_with_more_than_four_decimal_places_is_rejected(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 1.0);
        $qItem = $po->awards->first()->quotationItem;
        $shipment = $this->shipmentService->createDraft($this->supplierA);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('maximum of 4 decimal places');
        $this->shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => '1.00001',
            ]],
        ]);
    }

    public function test_exact_decimal_accumulation_rejects_overage_by_one_ten_thousandth(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 1.0);
        $qItem = $po->awards->first()->quotationItem;

        foreach (['0.3333', '0.6667'] as $quantity) {
            $shipment = $this->shipmentService->createDraft($this->supplierA);
            $this->shipmentService->submitShipment($shipment, [
                'items' => [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => $quantity,
                ]],
            ]);
        }

        $this->assertSame(0.0, $this->shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);

        $overage = $this->shipmentService->createDraft($this->supplierA);
        $this->expectException(InvalidArgumentException::class);
        $this->shipmentService->submitShipment($overage, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => '0.0001',
            ]],
        ]);
    }

    public function test_http_shipment_quantity_validation_rejects_zero_negative_and_excess_scale(): void
    {
        $po = $this->createPoForSupplier($this->supplierA, 10.0);
        $qItem = $po->awards->first()->quotationItem;

        foreach (['0', '-0.0001', '1.00001'] as $quantity) {
            $response = $this->actingAs($this->supplierA)
                ->post(route('supplier.shipments.store'), [
                    'shipment_date' => now()->toDateString(),
                    'estimated_arrival_date' => now()->addDays(7)->toDateString(),
                    'items' => [[
                        'purchase_order_id' => $po->id,
                        'quotation_item_id' => $qItem->id,
                        'shipped_quantity' => $quantity,
                    ]],
                    'action' => 'draft',
                ]);

            $response->assertSessionHasErrors('items.0.shipped_quantity');
        }

        $this->assertDatabaseCount('shipments', 0);
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
                'shipped_quantity' => 5.0,
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
                'shipped_quantity' => 10.0,
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
                'shipped_quantity' => 5.0,
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
                    'shipped_quantity' => 4.0,
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
                'shipped_quantity' => 4.0,
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
                'shipped_quantity' => 4.0,
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
                'shipped_quantity' => 4.0,
            ]],
        ]);
        $this->shipmentService->confirmArrival($first, $this->purchasing);

        $this->assertNotNull($po->fresh()->actual_arrival);

        $second = $this->shipmentService->createDraft($this->supplierA);
        $submitted = $this->shipmentService->submitShipment($second, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => 6.0,
            ]],
        ]);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $submitted->status);
        $this->assertDatabaseHas('shipment_items', [
            'shipment_id' => $second->id,
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_quantity' => '6.0000',
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
                'shipped_quantity' => 4.0,
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
                'shipped_quantity' => 10.0,
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
                'shipped_quantity' => 4.0,
            ]]);
        } finally {
            $this->assertDatabaseMissing('shipment_items', [
                'shipment_id' => $shipment->id,
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
            ]);
        }
    }

    private function createPoForSupplier(User $supplier, float $totalWeight): PurchaseOrder
    {
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
            'quantity' => 1,
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
        ]);

        $award = $this->awardService->awardItem($prItem, $qItem, $this->purchasing);
        $pos = $this->poService->generateFromAwards(collect([$award]), $this->purchasing);

        return $pos->first();
    }

    private function createLegacyPoForShipment(User $supplier, float $totalWeight): array
    {
        $po = $this->createPoForSupplier($supplier, $totalWeight);
        $award = $po->awards()->with('quotationItem')->firstOrFail();
        $qItem = $award->quotationItem;

        $po->awards()->delete();
        $po->unsetRelation('awards');

        return [$po->fresh(), $qItem];
    }
}
