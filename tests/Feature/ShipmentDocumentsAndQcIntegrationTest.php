<?php

namespace Tests\Feature;

use App\Models\Attachment;
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
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\ShipmentService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class ShipmentDocumentsAndQcIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasingUser;

    private User $qcUser;

    private User $supplierUserA;

    private User $supplierUserB;

    private Period $period;

    private ExchangeRate $usdRate;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->purchasingUser = User::factory()->create([
            'role' => 'purchasing',
            'is_active' => true,
        ]);

        $this->qcUser = User::factory()->create([
            'role' => 'qc',
            'is_active' => true,
        ]);

        $this->supplierUserA = User::factory()->create([
            'role' => 'supplier',
            'name' => 'Supplier Alpha Ltd',
            'is_active' => true,
        ]);

        $this->supplierUserB = User::factory()->create([
            'role' => 'supplier',
            'name' => 'Supplier Beta Inc',
            'is_active' => true,
        ]);

        $this->period = Period::create([
            'name' => '2026-09 Period',
            'year' => 2026,
            'month' => 9,
            'status' => 'open',
            'created_by' => $this->purchasingUser->id,
        ]);

        $this->usdRate = ExchangeRate::create([
            'currency' => 'USD',
            'rate_to_idr' => 16000,
            'valid_from' => now()->subDay(),
            'created_by' => $this->purchasingUser->id,
        ]);
    }

    private function createPrWithQuotation(User $supplier, float $orderedWeight = 20.0): array
    {
        $pr = PurchaseRequisition::create([
            'period_id' => $this->period->id,
            'pr_number' => 'REQ/09/2026/'.str_pad((string) rand(100, 999), 3, '0', STR_PAD_LEFT),
            'status' => 'bidding',
            'created_by' => $this->purchasingUser->id,
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'SKD11 Tool Steel Bar',
            'shape' => 'round',
            'quantity' => 1,
            'weight_needed' => $orderedWeight,
            'd_outer' => 120.0,
            'length' => 1000.0,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
            'validity_period' => now()->addDays(30),
            'submitted_at' => now(),
        ]);

        $qItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 15.0,
            'amount' => 15.0 * $orderedWeight,
            'is_available' => true,
        ]);

        return [$pr, $prItem, $quotation, $qItem];
    }

    /**
     * Test 1: Multi-PO shipment shares single set of shipping documents.
     */
    public function test_multi_po_shipment_shares_single_set_of_documents_and_supplier_can_upload(): void
    {
        [$pr1, $prItem1, $q1, $qItem1] = $this->createPrWithQuotation($this->supplierUserA, 20.0);
        [$pr2, $prItem2, $q2, $qItem2] = $this->createPrWithQuotation($this->supplierUserA, 30.0);

        $awardService = app(PrItemAwardService::class);
        $poGenService = app(PurchaseOrderGenerationService::class);

        $awardService->saveAwards($pr1, [$prItem1->id => $qItem1->id], $this->purchasingUser);
        $awardService->saveAwards($pr2, [$prItem2->id => $qItem2->id], $this->purchasingUser);

        $po1 = $poGenService->generatePurchaseOrdersForAwards([$prItem1->award], $this->purchasingUser)[0];
        $po2 = $poGenService->generatePurchaseOrdersForAwards([$prItem2->award], $this->purchasingUser)[0];

        $shipmentService = app(ShipmentService::class);
        $draft = $shipmentService->createDraft($this->supplierUserA);

        $this->assertCount(4, $draft->documents);
        $docTypes = $draft->documents->pluck('doc_type')->all();
        $this->assertEqualsCanonicalizing(['invoice', 'packing_list', 'bl', 'form_e'], $docTypes);

        $invoiceDoc = $draft->documents->where('doc_type', 'invoice')->first();
        $file = UploadedFile::fake()->create('commercial_invoice.pdf', 500, 'application/pdf');

        $response = $this->actingAs($this->supplierUserA)
            ->post(route('supplier.shipments.documents.upload', [
                'id' => $draft,
                'document_id' => $invoiceDoc->id,
            ]), [
                'file' => $file,
                'document_number' => 'INV-2026-ALPHA-01',
            ]);

        $response->assertRedirect();
        $invoiceDoc->refresh();
        $this->assertSame('INV-2026-ALPHA-01', $invoiceDoc->document_number);
        $this->assertSame('received', $invoiceDoc->status);
        $this->assertTrue($invoiceDoc->attachments()->exists());
    }

    /**
     * Test 2: Purchasing can update document verification status.
     */
    public function test_purchasing_can_update_document_status_and_supplier_cannot(): void
    {
        $shipmentService = app(ShipmentService::class);
        $draft = $shipmentService->createDraft($this->supplierUserA);
        $invoiceDoc = $draft->documents->where('doc_type', 'invoice')->first();

        // Purchasing can verify
        $response = $this->actingAs($this->purchasingUser)
            ->put(route('purchasing.shipments.documents.status', [
                'id' => $draft,
                'document_id' => $invoiceDoc->id,
            ]), [
                'status' => 'verified',
                'notes' => 'Invoice verified against commercial contract',
            ]);

        $response->assertRedirect();
        $invoiceDoc->refresh();
        $this->assertSame('verified', $invoiceDoc->status);

        // Supplier cannot hit purchasing document status route
        $supplierResponse = $this->actingAs($this->supplierUserA)
            ->put(route('purchasing.shipments.documents.status', [
                'id' => $draft,
                'document_id' => $invoiceDoc->id,
            ]), [
                'status' => 'done',
            ]);

        $supplierResponse->assertForbidden();
    }

    /**
     * Test 3: Purchasing confirms physical arrival and notifies QC.
     */
    public function test_purchasing_confirms_physical_arrival_and_pos_transition_to_waiting_qc(): void
    {
        [$pr, $prItem, $q, $qItem] = $this->createPrWithQuotation($this->supplierUserA, 20.0);

        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItem->award], $this->purchasingUser)[0];

        $shipmentService = app(ShipmentService::class);
        $draft = $shipmentService->createDraft($this->supplierUserA);

        $submitted = $shipmentService->submitShipment($draft, [
            'shipment_date' => now()->toDateString(),
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 10.0,
                ],
            ],
        ], $this->supplierUserA);

        $response = $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.shipments.confirm-arrival', $submitted), [
                'actual_arrival_date' => now()->toDateString(),
            ]);

        $response->assertRedirect();
        $submitted->refresh();
        $po->refresh();

        $this->assertSame('arrived', $submitted->status);
        $this->assertSame('waiting_qc', $po->status);
        $this->assertNotNull($po->actual_arrival);
    }

    public function test_arrival_confirmation_rejects_future_actual_date_without_state_change(): void
    {
        [, , , $qItem, $po] = $this->createAwardedPo(10.0);
        $shipment = $this->createShipment($po, $qItem, 10.0, false);

        $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.shipments.confirm-arrival', $shipment), [
                'actual_arrival_date' => now()->addDay()->toDateString(),
            ])
            ->assertSessionHasErrors('actual_arrival_date');

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment->fresh()->status);
        $this->assertNull($shipment->fresh()->actual_arrival_date);
    }

    /**
     * Test 4: QC inspects partial shipment with OK and PO remains active (not falsely completed).
     */
    public function test_qc_inspects_partial_shipment_with_ok_and_po_remains_active(): void
    {
        [$pr, $prItem, $q, $qItem] = $this->createPrWithQuotation($this->supplierUserA, 20.0);

        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItem->award], $this->purchasingUser)[0];

        $shipmentService = app(ShipmentService::class);
        $draft1 = $shipmentService->createDraft($this->supplierUserA);
        $shipment1 = $shipmentService->submitShipment($draft1, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 8.0,
                ],
            ],
        ], $this->supplierUserA);

        $shipmentService->confirmArrival($shipment1, $this->purchasingUser);

        // QC performs inspection on partial shipment 1
        $response = $this->actingAs($this->qcUser)
            ->post(route('qc.inspections.store', $po), [
                'shipment_id' => $shipment1->id,
                'items' => [
                    [
                        'pr_item_id' => $prItem->id,
                        'actual_thickness' => null,
                        'actual_d_outer' => 120.0,
                        'actual_length' => 1000.0,
                        'actual_weight' => 8.0,
                        'status' => 'ok',
                        'notes' => 'First partial shipment 8kg verified OK',
                    ],
                ],
            ]);

        $response->assertRedirect();
        $po->refresh();

        // Invariant: First partial shipment must NOT falsely mark the complete PO as completed!
        $this->assertSame('active', $po->status);

        $inspection = QcInspection::where('po_id', $po->id)->first();
        $this->assertNotNull($inspection);
        $this->assertSame($shipment1->id, $inspection->shipment_id);
        $this->assertSame('ok', $inspection->status);

        $qcItem = $inspection->items->first();
        $this->assertNotNull($qcItem->shipment_item_id);
    }

    /**
     * Test 5: Second shipment delivers remaining quantity and QC marks PO completed.
     */
    public function test_second_shipment_delivers_remaining_quantity_and_qc_marks_po_completed(): void
    {
        [$pr, $prItem, $q, $qItem] = $this->createPrWithQuotation($this->supplierUserA, 20.0);

        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItem->award], $this->purchasingUser)[0];

        $shipmentService = app(ShipmentService::class);

        // Shipment 1: 8 kg
        $draft1 = $shipmentService->createDraft($this->supplierUserA);
        $shipment1 = $shipmentService->submitShipment($draft1, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 8.0,
                ],
            ],
        ], $this->supplierUserA);
        $shipmentService->confirmArrival($shipment1, $this->purchasingUser);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment1->id,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_d_outer' => 120.0,
                    'actual_length' => 1000.0,
                    'actual_weight' => 8.0,
                    'status' => 'ok',
                ],
            ],
        ]);

        $po->refresh();
        $this->assertSame('active', $po->status);

        // Shipment 2: 12 kg (completing the 20 kg ordered)
        $draft2 = $shipmentService->createDraft($this->supplierUserA);
        $shipment2 = $shipmentService->submitShipment($draft2, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 12.0,
                ],
            ],
        ], $this->supplierUserA);
        $shipmentService->confirmArrival($shipment2, $this->purchasingUser);

        $po->refresh();
        $this->assertSame('waiting_qc', $po->status);

        // QC performs inspection on Shipment 2
        $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment2->id,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_d_outer' => 120.0,
                    'actual_length' => 1000.0,
                    'actual_weight' => 12.0,
                    'status' => 'ok',
                ],
            ],
        ]);

        $response->assertRedirect();
        $po->refresh();

        // With 8 + 12 = 20kg delivered and inspected OK, PO now reaches completed!
        $this->assertSame('completed', $po->status);

        // Assert 2 distinct QC inspection records exist for this PO
        $inspections = QcInspection::where('po_id', $po->id)->get();
        $this->assertCount(2, $inspections);
        $this->assertEqualsCanonicalizing([$shipment1->id, $shipment2->id], $inspections->pluck('shipment_id')->all());
    }

    /**
     * Test 6: QC inspection NG marks PO as claim_needed.
     */
    public function test_qc_inspection_ng_marks_po_claim_needed(): void
    {
        [$pr, $prItem, $q, $qItem] = $this->createPrWithQuotation($this->supplierUserA, 20.0);

        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItem->award], $this->purchasingUser)[0];

        $shipmentService = app(ShipmentService::class);
        $draft = $shipmentService->createDraft($this->supplierUserA);
        $shipment = $shipmentService->submitShipment($draft, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 20.0,
                ],
            ],
        ], $this->supplierUserA);
        $shipmentService->confirmArrival($shipment, $this->purchasingUser);

        $evidencePhoto = UploadedFile::fake()->image('defect_crack.jpg');

        $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->id,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_d_outer' => 110.0, // Major dimension undersize defect
                    'actual_length' => 1000.0,
                    'actual_weight' => 20.0,
                    'status' => 'ng',
                    'notes' => 'Outer diameter undersize by 10mm. Severe defect.',
                ],
            ],
            'attachments' => [
                0 => [$evidencePhoto],
            ],
        ]);

        $response->assertRedirect();
        $po->refresh();

        $this->assertSame('claim_needed', $po->status);

        $inspection = QcInspection::where('po_id', $po->id)->first();
        $this->assertSame('ng', $inspection->status);
        $this->assertTrue($inspection->attachments()->exists());
    }

    /**
     * Test 7: Supplier data isolation on shipments.
     */
    public function test_supplier_data_isolation_on_shipments(): void
    {
        $shipmentService = app(ShipmentService::class);
        $draftA = $shipmentService->createDraft($this->supplierUserA);

        // Supplier B cannot view Supplier A's shipment
        $this->actingAs($this->supplierUserB)
            ->get(route('supplier.shipments.show', $draftA))
            ->assertForbidden();

        // Supplier B cannot upload document to Supplier A's shipment
        $file = UploadedFile::fake()->create('fake_invoice.pdf', 100);
        $invoiceDoc = $draftA->documents->first();
        $this->actingAs($this->supplierUserB)
            ->post(route('supplier.shipments.documents.upload', [
                'id' => $draftA,
                'document_id' => $invoiceDoc->id,
            ]), [
                'file' => $file,
            ])
            ->assertForbidden();

        // Supplier B cannot cancel Supplier A's shipment
        $this->actingAs($this->supplierUserB)
            ->post(route('supplier.shipments.cancel', $draftA))
            ->assertForbidden();

        // Supplier A cannot confirm arrival (Purchasing only)
        $this->actingAs($this->supplierUserA)
            ->post(route('purchasing.shipments.confirm-arrival', $draftA))
            ->assertForbidden();
    }

    public function test_supplier_can_download_own_shipment_document_attachment_but_other_supplier_cannot(): void
    {
        $shipment = app(ShipmentService::class)->createDraft($this->supplierUserA);
        $document = $shipment->documents->first();
        $attachment = app(ShipmentService::class)->uploadDocument(
            $document,
            UploadedFile::fake()->create('owner-invoice.pdf', 20, 'application/pdf'),
            $this->supplierUserA
        );

        $this->actingAs($this->supplierUserA)
            ->get(route('attachments.show', $attachment))
            ->assertOk();
        $this->actingAs($this->supplierUserB)
            ->get(route('attachments.show', $attachment))
            ->assertForbidden();
    }

    public function test_failed_document_physical_write_creates_no_attachment_row(): void
    {
        $shipment = app(ShipmentService::class)->createDraft($this->supplierUserA);
        $document = $shipment->documents->first();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturnFalse();
        $disk->shouldReceive('delete')->once()->andReturnTrue();
        Storage::shouldReceive('disk')->with('private')->once()->andReturn($disk);

        try {
            app(ShipmentService::class)->uploadDocument(
                $document,
                UploadedFile::fake()->create('failed.pdf', 20, 'application/pdf'),
                $this->supplierUserA
            );
            $this->fail('Expected a failed private-disk write to abort document persistence.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('store', strtolower($exception->getMessage()));
        }

        $this->assertDatabaseMissing('attachments', [
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $document->id,
        ]);
        $this->assertSame(ShipmentDocument::STATUS_PENDING, $document->fresh()->status);
    }

    public function test_document_database_failure_deletes_only_new_physical_file(): void
    {
        $shipment = app(ShipmentService::class)->createDraft($this->supplierUserA);
        $document = $shipment->documents->first();
        $existing = app(ShipmentService::class)->uploadDocument(
            $document,
            UploadedFile::fake()->create('existing.pdf', 20, 'application/pdf'),
            $this->supplierUserA
        );

        $newFile = UploadedFile::fake()->create('new-version.pdf', 20, 'application/pdf');
        $newPath = 'attachments/'.now()->format('Y/m').'/'.$newFile->hashName();
        Event::listen('eloquent.creating: '.Attachment::class, function () {
            throw new \RuntimeException('Forced attachment insert failure.');
        });

        try {
            app(ShipmentService::class)->uploadDocument($document, $newFile, $this->supplierUserA);
            $this->fail('Expected the forced attachment insert failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Forced attachment insert failure.', $exception->getMessage());
        } finally {
            Event::forget('eloquent.creating: '.Attachment::class);
        }

        Storage::disk('private')->assertExists($existing->file_path);
        Storage::disk('private')->assertMissing($newPath);
        $this->assertSame(1, $document->attachments()->count());
    }

    public function test_reupload_preserves_history_resets_review_and_both_roles_resolve_latest_version(): void
    {
        $shipment = app(ShipmentService::class)->createDraft($this->supplierUserA);
        $document = $shipment->documents->first();
        $first = app(ShipmentService::class)->uploadDocument(
            $document,
            UploadedFile::fake()->create('version-one.pdf', 20, 'application/pdf'),
            $this->supplierUserA
        );
        $document->update(['status' => ShipmentDocument::STATUS_VERIFIED]);

        $second = app(ShipmentService::class)->uploadDocument(
            $document,
            UploadedFile::fake()->create('version-two.pdf', 20, 'application/pdf'),
            $this->supplierUserA
        );

        $this->assertSame(2, $document->attachments()->count());
        $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $document->fresh()->status);
        $this->assertSame($second->id, $document->fresh()->latestAttachment->id);
        Storage::disk('private')->assertExists($first->file_path);
        Storage::disk('private')->assertExists($second->file_path);

        $this->actingAs($this->supplierUserA)
            ->get(route('supplier.shipments.show', $shipment))
            ->assertOk()
            ->assertSee('version-two.pdf')
            ->assertDontSee('version-one.pdf');
        $this->actingAs($this->purchasingUser)
            ->get(route('purchasing.shipments.show', $shipment))
            ->assertOk()
            ->assertSee('version-two.pdf')
            ->assertDontSee('version-one.pdf');
    }

    public function test_qc_inspection_store_rejects_mismatched_shipment_without_items_for_po(): void
    {
        // PO A
        [$prA, $prItemA, $qA, $qItemA] = $this->createPrWithQuotation($this->supplierUserA, 20.0);
        app(PrItemAwardService::class)->saveAwards($prA, [$prItemA->id => $qItemA->id], $this->purchasingUser);
        $poA = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItemA->award], $this->purchasingUser)[0];

        // PO B
        [$prB, $prItemB, $qB, $qItemB] = $this->createPrWithQuotation($this->supplierUserA, 15.0);
        app(PrItemAwardService::class)->saveAwards($prB, [$prItemB->id => $qItemB->id], $this->purchasingUser);
        $poB = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItemB->award], $this->purchasingUser)[0];

        // Create Shipment B containing only items for PO B
        $shipmentService = app(ShipmentService::class);
        $draftB = $shipmentService->createDraft($this->supplierUserA);
        $shipmentB = $shipmentService->submitShipment($draftB, [
            'items' => [
                [
                    'purchase_order_id' => $poB->id,
                    'quotation_item_id' => $qItemB->id,
                    'shipped_quantity' => 15.0,
                ],
            ],
        ], $this->supplierUserA);
        $shipmentService->confirmArrival($shipmentB, $this->purchasingUser);

        // Put PO A in waiting_qc as well
        $poA->update(['status' => 'waiting_qc']);

        // Crafted direct POST: Inspecting PO A, but supplying Shipment B (which contains no items for PO A)
        $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $poA), [
            'shipment_id' => $shipmentB->hash,
            'items' => [
                [
                    'pr_item_id' => $prItemA->id,
                    'actual_thickness' => 10.0,
                    'actual_width' => 100.0,
                    'actual_length' => 1000.0,
                    'actual_weight' => 20.0,
                    'status' => 'ok',
                ],
            ],
        ]);

        $response->assertSessionHas('error');
        $this->assertStringContainsString('does not contain items for this Purchase Order', session('error'));

        // Assert no inspection was created for PO A
        $this->assertFalse(QcInspection::where('po_id', $poA->id)->exists());
    }

    public function test_qc_inspection_store_supports_legitimate_multi_po_shipment(): void
    {
        // PO A
        [$prA, $prItemA, $qA, $qItemA] = $this->createPrWithQuotation($this->supplierUserA, 10.0);
        app(PrItemAwardService::class)->saveAwards($prA, [$prItemA->id => $qItemA->id], $this->purchasingUser);
        $poA = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItemA->award], $this->purchasingUser)[0];

        // PO B
        [$prB, $prItemB, $qB, $qItemB] = $this->createPrWithQuotation($this->supplierUserA, 15.0);
        app(PrItemAwardService::class)->saveAwards($prB, [$prItemB->id => $qItemB->id], $this->purchasingUser);
        $poB = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItemB->award], $this->purchasingUser)[0];

        // Shipment containing items for both PO A and PO B
        $shipmentService = app(ShipmentService::class);
        $draft = $shipmentService->createDraft($this->supplierUserA);
        $shipment = $shipmentService->submitShipment($draft, [
            'items' => [
                [
                    'purchase_order_id' => $poA->id,
                    'quotation_item_id' => $qItemA->id,
                    'shipped_quantity' => 10.0,
                ],
                [
                    'purchase_order_id' => $poB->id,
                    'quotation_item_id' => $qItemB->id,
                    'shipped_quantity' => 15.0,
                ],
            ],
        ], $this->supplierUserA);
        $shipmentService->confirmArrival($shipment, $this->purchasingUser);

        $poA->refresh();
        $poB->refresh();
        $this->assertSame('waiting_qc', $poA->status);
        $this->assertSame('waiting_qc', $poB->status);

        // Inspect PO A
        $responseA = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $poA), [
            'shipment_id' => $shipment->hash,
            'items' => [
                [
                    'pr_item_id' => $prItemA->id,
                    'actual_thickness' => 10.0,
                    'actual_width' => 100.0,
                    'actual_length' => 1000.0,
                    'actual_weight' => 10.0,
                    'status' => 'ok',
                ],
            ],
        ]);
        $responseA->assertRedirect();
        $poA->refresh();
        $this->assertSame('completed', $poA->status);

        // Inspect PO B
        $responseB = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $poB), [
            'shipment_id' => $shipment->hash,
            'items' => [
                [
                    'pr_item_id' => $prItemB->id,
                    'actual_thickness' => 10.0,
                    'actual_width' => 100.0,
                    'actual_length' => 1000.0,
                    'actual_weight' => 15.0,
                    'status' => 'ok',
                ],
            ],
        ]);
        $responseB->assertRedirect();
        $poB->refresh();
        $this->assertSame('completed', $poB->status);

        // Verify both inspections exist for this shipment
        $this->assertSame(2, QcInspection::where('shipment_id', $shipment->id)->count());
    }

    public function test_shipment_aware_qc_requires_shipment_id(): void
    {
        [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
        $shipment = $this->createShipment($po, $qItem, 20.0, true);

        $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'items' => [$this->qcItemPayload($prItem, 20.0)],
        ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('qc_inspections', ['po_id' => $po->id]);
        $this->assertSame(Shipment::STATUS_ARRIVED, $shipment->fresh()->status);
    }

    public function test_shipment_aware_qc_rejects_non_arrived_shipment(): void
    {
        foreach ([Shipment::STATUS_DRAFT, Shipment::STATUS_SUBMITTED] as $status) {
            [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
            $shipmentService = app(ShipmentService::class);
            $shipment = $shipmentService->createDraft($this->supplierUserA);

            if ($status === Shipment::STATUS_SUBMITTED) {
                $shipment = $shipmentService->submitShipment($shipment, [
                    'items' => [[
                        'purchase_order_id' => $po->id,
                        'quotation_item_id' => $qItem->id,
                        'shipped_quantity' => 20.0,
                    ]],
                ]);
            } else {
                $shipmentService->syncDraftItems($shipment, [[
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 20.0,
                ]]);
            }

            $po->update(['status' => 'waiting_qc']);

            $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
                'shipment_id' => $shipment->hash,
                'items' => [$this->qcItemPayload($prItem, 20.0)],
            ]);

            $response->assertRedirect()->assertSessionHas('error');
            $this->assertDatabaseMissing('qc_inspections', [
                'po_id' => $po->id,
                'shipment_id' => $shipment->id,
            ]);
        }
    }

    public function test_shipment_aware_qc_locks_shipment_before_purchase_order(): void
    {
        [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
        $shipment = $this->createShipment($po, $qItem, 20.0, true);
        $lockQueries = [];

        DB::listen(function ($query) use (&$lockQueries): void {
            if (str_contains(strtolower($query->sql), 'for update')) {
                $lockQueries[] = strtolower($query->sql);
            }
        });

        $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [$this->qcItemPayload($prItem, 20.0)],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('qc_inspections', [
            'po_id' => $po->id,
            'shipment_id' => $shipment->id,
        ]);

        $shipmentLockIndex = collect($lockQueries)->search(
            fn (string $sql): bool => str_contains($sql, '`shipments`')
        );
        $purchaseOrderLockIndex = collect($lockQueries)->search(
            fn (string $sql): bool => str_contains($sql, '`purchase_orders`')
        );

        $this->assertNotFalse($shipmentLockIndex, 'Expected shipment row to be locked for update.');
        $this->assertNotFalse($purchaseOrderLockIndex, 'Expected Purchase Order row to be locked for update.');
        $this->assertLessThan(
            $purchaseOrderLockIndex,
            $shipmentLockIndex,
            'Shipment-aware QC must lock Shipment before Purchase Order to match the Shipment lifecycle lock order.'
        );
    }

    public function test_shipment_aware_qc_requires_every_expected_line_exactly_once(): void
    {
        [$po, $prItems, $qItems] = $this->createTwoItemAwardedPo();
        $shipmentService = app(ShipmentService::class);
        $shipment = $shipmentService->createDraft($this->supplierUserA);
        $shipment = $shipmentService->submitShipment($shipment, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItems[0]->id,
                    'shipped_quantity' => 10.0,
                ],
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItems[1]->id,
                    'shipped_quantity' => 12.0,
                ],
            ],
        ]);
        $shipmentService->confirmArrival($shipment, $this->purchasingUser);

        $omitted = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [$this->qcItemPayload($prItems[0], 10.0)],
        ]);
        $omitted->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('qc_inspections', ['po_id' => $po->id]);

        $duplicate = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [
                $this->qcItemPayload($prItems[0], 10.0),
                $this->qcItemPayload($prItems[0], 10.0),
                $this->qcItemPayload($prItems[1], 12.0),
            ],
        ]);
        $duplicate->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('qc_inspections', ['po_id' => $po->id]);
    }

    public function test_shipment_aware_qc_rejects_same_po_item_not_present_in_selected_shipment(): void
    {
        [$po, $prItems, $qItems] = $this->createTwoItemAwardedPo();
        $shipment = $this->createShipment($po, $qItems[0], 10.0, true);

        $response = $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [$this->qcItemPayload($prItems[1], 12.0)],
        ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseMissing('qc_inspections', ['po_id' => $po->id]);
        $this->assertDatabaseMissing('qc_items', ['pr_item_id' => $prItems[1]->id]);
    }

    public function test_claim_resolution_on_partial_ng_delivery_does_not_complete_po_and_allows_workflow_continuation(): void
    {
        // 20 kg ordered
        [$pr, $prItem, $q, $qItem] = $this->createPrWithQuotation($this->supplierUserA, 20.0);
        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards([$prItem->award], $this->purchasingUser)[0];

        // 5 kg delivered in shipment 1
        $shipmentService = app(ShipmentService::class);
        $draft1 = $shipmentService->createDraft($this->supplierUserA);
        $shipment1 = $shipmentService->submitShipment($draft1, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 5.0,
                ],
            ],
        ], $this->supplierUserA);

        $shipmentService->confirmArrival($shipment1, $this->purchasingUser);
        $po->refresh();
        $this->assertSame('waiting_qc', $po->status);

        // 5 kg NG in QC
        $evidencePhoto = UploadedFile::fake()->image('ng_defect.jpg', 640, 480);
        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment1->hash,
            'items' => [
                [
                    'pr_item_id' => $prItem->id,
                    'actual_thickness' => 5.0,
                    'actual_width' => 100.0,
                    'actual_length' => 1000.0,
                    'actual_weight' => 5.0,
                    'status' => 'ng',
                    'notes' => 'Severe surface crack',
                ],
            ],
            'attachments' => [
                0 => [$evidencePhoto],
            ],
        ]);

        $po->refresh();
        $this->assertSame('claim_needed', $po->status);
        $inspection = QcInspection::where('po_id', $po->id)->first();

        // Create and respond to claim
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasingUser->id,
            'supplier_id' => $this->supplierUserA->id,
            'status' => 'responded',
            'description' => 'Defective material',
            'resolution_expected' => 'replacement',
            'deadline' => now()->addDays(7),
            'supplier_response' => 'We will provide replacement delivery.',
        ]);

        // Purchasing resolves the claim
        $resolveResponse = $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.claims.resolve', $claim));
        $resolveResponse->assertRedirect();

        $claim->refresh();
        $po->refresh();
        $this->assertSame('resolved', $claim->status);

        // FIX 7: PO must NOT become completed! It must be active!
        $this->assertSame('active', $po->status);
        $this->assertNotSame('completed', $po->status);
        $this->assertSame(20.0, $shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);

        // Verify remaining delivery can continue:
        // Supplier can submit remaining 15 kg
        $draft2 = $shipmentService->createDraft($this->supplierUserA);
        $shipment2 = $shipmentService->submitShipment($draft2, [
            'items' => [
                [
                    'purchase_order_id' => $po->id,
                    'quotation_item_id' => $qItem->id,
                    'shipped_quantity' => 15.0,
                ],
            ],
        ], $this->supplierUserA);

        $this->assertSame(Shipment::STATUS_SUBMITTED, $shipment2->status);

        $shipmentService->confirmArrival($shipment2, $this->purchasingUser);
        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment2->hash,
            'items' => [$this->qcItemPayload($prItem, 15.0)],
        ])->assertRedirect();

        $po->refresh();
        $this->assertSame('active', $po->status);
        $this->assertSame(5.0, $shipmentService->getItemDeliveryStatus($po->id, $qItem->id)['remaining']);

        $replacement = $this->createShipment($po, $qItem, 5.0, true);
        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $replacement->hash,
            'items' => [$this->qcItemPayload($prItem, 5.0)],
        ])->assertRedirect();

        $this->assertSame('completed', $po->fresh()->status);
        $this->assertTrue($po->fresh()->isFullyFulfilledAndInspected());
    }

    public function test_mixed_ok_and_ng_lines_count_fulfillment_per_shipment_item(): void
    {
        [$po, $prItems, $qItems] = $this->createTwoItemAwardedPo();
        $shipmentService = app(ShipmentService::class);
        $shipment = $shipmentService->createDraft($this->supplierUserA);
        $shipment = $shipmentService->submitShipment($shipment, [
            'items' => [
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItems[0]->id, 'shipped_quantity' => 10.0],
                ['purchase_order_id' => $po->id, 'quotation_item_id' => $qItems[1]->id, 'shipped_quantity' => 12.0],
            ],
        ]);
        $shipmentService->confirmArrival($shipment, $this->purchasingUser);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $shipment->hash,
            'items' => [
                $this->qcItemPayload($prItems[0], 10.0, 'ok'),
                $this->qcItemPayload($prItems[1], 12.0, 'ng'),
            ],
            'attachments' => [
                1 => [UploadedFile::fake()->image('mixed-ng.jpg')],
            ],
        ])->assertRedirect();

        $inspection = QcInspection::where('po_id', $po->id)->firstOrFail();
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasingUser->id,
            'supplier_id' => $this->supplierUserA->id,
            'status' => 'responded',
            'description' => 'Mixed shipment NG line',
            'resolution_expected' => 'replacement',
            'deadline' => now()->addDays(7),
            'supplier_response' => 'Replacement accepted.',
        ]);
        $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.claims.resolve', $claim))
            ->assertRedirect();

        $itemA = $po->itemFulfillmentStatus($qItems[0]->id);
        $itemB = $po->itemFulfillmentStatus($qItems[1]->id);
        $this->assertSame(10.0, $itemA['accepted']);
        $this->assertSame(0.0, $itemA['remaining']);
        $this->assertSame(0.0, $itemB['accepted']);
        $this->assertSame(12.0, $itemB['replacement_eligible']);
        $this->assertSame(12.0, $itemB['remaining']);

        $replacement = $this->createShipment($po, $qItems[1], 12.0, true);
        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $replacement->hash,
            'items' => [$this->qcItemPayload($prItems[1], 12.0)],
        ])->assertRedirect();

        $this->assertSame('completed', $po->fresh()->status);
    }

    public function test_supplier_cannot_respond_to_resolved_claim(): void
    {
        [, , , , $po] = $this->createAwardedPo(20.0);
        $inspection = QcInspection::create([
            'po_id' => $po->id,
            'inspected_by' => $this->qcUser->id,
            'status' => 'ng',
            'inspected_at' => now(),
        ]);
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasingUser->id,
            'supplier_id' => $this->supplierUserA->id,
            'status' => 'resolved',
            'description' => 'Resolved claim',
            'resolution_expected' => 'replacement',
            'deadline' => now()->addDays(7),
        ]);

        $response = $this->actingAs($this->supplierUserA)
            ->post(route('supplier.claims.respond', $claim), [
                'supplier_response' => 'Attempted late response',
            ]);

        $response->assertRedirect()->assertSessionHas('error');
        $this->assertSame('resolved', $claim->fresh()->status);
        $this->assertNull($claim->fresh()->supplier_response);
    }

    public function test_arrival_and_qc_ok_do_not_override_active_claim_state(): void
    {
        [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
        $shipmentService = app(ShipmentService::class);
        $first = $this->createShipment($po, $qItem, 5.0, true);
        $second = $this->createShipment($po, $qItem, 5.0, false);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $first->hash,
            'items' => [$this->qcItemPayload($prItem, 5.0, 'ng')],
            'attachments' => [0 => [UploadedFile::fake()->image('claim-ng.jpg')]],
        ])->assertRedirect();
        $inspection = QcInspection::where('shipment_id', $first->id)->firstOrFail();
        MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasingUser->id,
            'supplier_id' => $this->supplierUserA->id,
            'status' => 'pending',
            'description' => 'Active claim',
            'resolution_expected' => 'replacement',
            'deadline' => now()->addDays(7),
        ]);

        $shipmentService->confirmArrival($second, $this->purchasingUser);
        $this->assertSame('claim_needed', $po->fresh()->status);

        $this->actingAs($this->qcUser)
            ->get(route('qc.inspections.create', ['po_id' => $po, 'shipment_id' => $second]))
            ->assertOk();

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $second->hash,
            'items' => [$this->qcItemPayload($prItem, 5.0)],
        ])->assertRedirect();

        $this->assertDatabaseHas('qc_inspections', [
            'po_id' => $po->id,
            'shipment_id' => $second->id,
            'status' => 'ok',
        ]);
        $this->assertSame('claim_needed', $po->fresh()->status);
    }

    public function test_resolving_one_of_multiple_claims_preserves_claim_needed_until_all_resolve(): void
    {
        [, , , , $po] = $this->createAwardedPo(20.0);
        $claims = collect([1, 2])->map(function (int $index) use ($po) {
            $inspection = QcInspection::create([
                'po_id' => $po->id,
                'inspected_by' => $this->qcUser->id,
                'status' => 'ng',
                'inspected_at' => now(),
            ]);

            return MaterialClaim::create([
                'inspection_id' => $inspection->id,
                'po_id' => $po->id,
                'submitted_by' => $this->purchasingUser->id,
                'supplier_id' => $this->supplierUserA->id,
                'status' => 'responded',
                'description' => "Claim {$index}",
                'resolution_expected' => 'replacement',
                'deadline' => now()->addDays(7),
                'supplier_response' => 'Accepted',
            ]);
        });
        $po->update(['status' => 'claim_needed']);

        $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.claims.resolve', $claims[0]))
            ->assertRedirect();
        $this->assertSame('claim_needed', $po->fresh()->status);

        $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.claims.resolve', $claims[1]))
            ->assertRedirect();
        $this->assertSame('active', $po->fresh()->status);
    }

    public function test_legacy_po_without_shipment_items_still_allows_null_shipment_qc(): void
    {
        [, $prItem, , , $po] = $this->createAwardedPo(20.0);
        $po->update([
            'status' => 'waiting_qc',
            'actual_arrival' => now()->toDateString(),
        ]);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'items' => [$this->qcItemPayload($prItem, 20.0)],
        ])->assertRedirect();

        $inspection = QcInspection::where('po_id', $po->id)->firstOrFail();
        $this->assertNull($inspection->shipment_id);
        $this->assertNull($inspection->items()->firstOrFail()->shipment_item_id);
        $this->assertSame('completed', $po->fresh()->status);
    }

    public function test_final_claim_resolution_returns_to_waiting_qc_when_an_arrived_shipment_is_uninspected(): void
    {
        [, $prItem, , $qItem, $po] = $this->createAwardedPo(20.0);
        $shipmentService = app(ShipmentService::class);
        $ngShipment = $this->createShipment($po, $qItem, 5.0, false);
        $waitingShipment = $this->createShipment($po, $qItem, 5.0, false);
        $shipmentService->confirmArrival($ngShipment, $this->purchasingUser);
        $shipmentService->confirmArrival($waitingShipment, $this->purchasingUser);

        $this->actingAs($this->qcUser)->post(route('qc.inspections.store', $po), [
            'shipment_id' => $ngShipment->hash,
            'items' => [$this->qcItemPayload($prItem, 5.0, 'ng')],
            'attachments' => [0 => [UploadedFile::fake()->image('waiting-ng.jpg')]],
        ])->assertRedirect();
        $inspection = QcInspection::where('shipment_id', $ngShipment->id)->firstOrFail();
        $claim = MaterialClaim::create([
            'inspection_id' => $inspection->id,
            'po_id' => $po->id,
            'submitted_by' => $this->purchasingUser->id,
            'supplier_id' => $this->supplierUserA->id,
            'status' => 'responded',
            'description' => 'Await replacement',
            'resolution_expected' => 'replacement',
            'deadline' => now()->addDays(7),
            'supplier_response' => 'Accepted',
        ]);

        $this->actingAs($this->purchasingUser)
            ->post(route('purchasing.claims.resolve', $claim))
            ->assertRedirect();

        $this->assertSame('waiting_qc', $po->fresh()->status);
    }

    private function createAwardedPo(float $orderedWeight): array
    {
        [$pr, $prItem, $quotation, $qItem] = $this->createPrWithQuotation($this->supplierUserA, $orderedWeight);
        app(PrItemAwardService::class)->saveAwards($pr, [$prItem->id => $qItem->id], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)
            ->generatePurchaseOrdersForAwards([$prItem->fresh()->award], $this->purchasingUser)
            ->firstOrFail();

        return [$pr, $prItem, $quotation, $qItem, $po];
    }

    private function createShipment(PurchaseOrder $po, QuotationItem $qItem, float $quantity, bool $arrive): Shipment
    {
        $shipmentService = app(ShipmentService::class);
        $shipment = $shipmentService->createDraft($this->supplierUserA);
        $shipment = $shipmentService->submitShipment($shipment, [
            'items' => [[
                'purchase_order_id' => $po->id,
                'quotation_item_id' => $qItem->id,
                'shipped_quantity' => $quantity,
            ]],
        ]);

        return $arrive
            ? $shipmentService->confirmArrival($shipment, $this->purchasingUser)
            : $shipment;
    }

    private function qcItemPayload(PrItem $prItem, float $weight, string $status = 'ok'): array
    {
        return [
            'pr_item_id' => $prItem->id,
            'actual_d_outer' => $prItem->d_outer,
            'actual_length' => $prItem->length,
            'actual_weight' => $weight,
            'status' => $status,
        ];
    }

    private function createTwoItemAwardedPo(): array
    {
        [$pr, $firstPrItem, $quotation, $firstQItem] = $this->createPrWithQuotation($this->supplierUserA, 10.0);

        $secondPrItem = PrItem::create([
            'pr_id' => $pr->id,
            'material_name' => 'SKD11 Tool Steel Bar B',
            'shape' => 'round',
            'quantity' => 1,
            'weight_needed' => 12.0,
            'd_outer' => 130.0,
            'length' => 900.0,
        ]);
        $secondQItem = QuotationItem::create([
            'quotation_id' => $quotation->id,
            'pr_item_id' => $secondPrItem->id,
            'price_per_kg' => 16.0,
            'amount' => 192.0,
            'is_available' => true,
        ]);

        app(PrItemAwardService::class)->saveAwards($pr, [
            $firstPrItem->id => $firstQItem->id,
            $secondPrItem->id => $secondQItem->id,
        ], $this->purchasingUser);
        $po = app(PurchaseOrderGenerationService::class)->generatePurchaseOrdersForAwards(
            [$firstPrItem->fresh()->award, $secondPrItem->fresh()->award],
            $this->purchasingUser
        )->firstOrFail();

        return [$po, [$firstPrItem, $secondPrItem], [$firstQItem, $secondQItem]];
    }
}
