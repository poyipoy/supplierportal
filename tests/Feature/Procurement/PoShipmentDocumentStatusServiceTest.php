<?php

namespace Tests\Feature\Procurement;

use App\Exceptions\PoShipmentDocumentBatchRefused;
use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\PoShipmentDocumentStatusService;
use App\Support\Procurement\CustomsDocumentationProgressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PoShipmentDocumentStatusServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private PoShipmentDocumentStatusService $service;

    private CustomsDocumentationProgressCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->service = app(PoShipmentDocumentStatusService::class);
        $this->calculator = app(CustomsDocumentationProgressCalculator::class);
    }

    public function test_projection_calculator_is_pure_read_and_computes_partial_and_complete_states(): void
    {
        [$po, $s1, $qItem] = $this->createPoWithShipment('SHP/10/2026/001');
        $s2 = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/002', Shipment::STATUS_ARRIVED);
        $sDraft = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/003', Shipment::STATUS_DRAFT);
        $sCancelled = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/004', Shipment::STATUS_CANCELLED);

        // Delete form_e row on s2 to verify missing_row handling
        $s2->documents()->where('doc_type', 'form_e')->delete();

        // Mark s1 invoice verified with valid attachment; s2 invoice received without attachment (evidence gap)
        $s1Invoice = $s1->documents()->where('doc_type', 'invoice')->firstOrFail();
        $this->attachValidatedFile($s1Invoice, 'inv-s1.pdf');
        $s1Invoice->update(['status' => ShipmentDocument::STATUS_VERIFIED]);

        $s2Invoice = $s2->documents()->where('doc_type', 'invoice')->firstOrFail();
        $s2Invoice->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        // BL on s1 is 'received' (counts as received, not verified until 'done')
        $s1Bl = $s1->documents()->where('doc_type', 'bl')->firstOrFail();
        $this->attachValidatedFile($s1Bl, 'bl-s1.pdf');
        $s1Bl->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $writes = [];
        DB::listen(function ($query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $progress = $po->fresh()->customsDocumentationProgress();

        $this->assertSame([], $writes, 'customsDocumentationProgress() must not write to the database.');
        $this->assertTrue($progress['uses_shipment_workflow']);
        $this->assertSame(2, $progress['active_shipment_count']);
        $this->assertSame(1, $progress['draft_shipment_count']);
        $this->assertSame(1, $progress['cancelled_shipment_count']);
        $this->assertSame(4, $progress['total_shipment_count']);

        $inv = $progress['documents']['invoice'];
        $this->assertSame(2, $inv['received_count']);
        $this->assertSame(1, $inv['verified_count']);
        $this->assertSame(1, $inv['files_present']);
        $this->assertSame(1, $inv['evidence_gap_count']);
        $this->assertTrue($inv['all_received']);
        $this->assertFalse($inv['all_verified']);
        $this->assertTrue($inv['partial_verified']);
        $this->assertCount(4, $inv['rows']);

        // Draft and cancelled rows are present but ineligible
        $draftRow = collect($inv['rows'])->firstWhere('shipment_id', $sDraft->id);
        $cancelledRow = collect($inv['rows'])->firstWhere('shipment_id', $sCancelled->id);
        $this->assertFalse($draftRow['eligible']);
        $this->assertFalse($cancelledRow['eligible']);

        // BL 'received' is counted in received_count (1) but verified_count is 0 until 'done'
        $bl = $progress['documents']['bl'];
        $this->assertSame(1, $bl['received_count']);
        $this->assertSame(0, $bl['verified_count']);
        $this->assertTrue($bl['partial_received']);

        // Form-E missing row on s2 is treated as pending + missing_row
        $formE = $progress['documents']['form_e'];
        $s2FormERow = collect($formE['rows'])->firstWhere('shipment_id', $s2->id);
        $this->assertTrue($s2FormERow['missing_row']);
        $this->assertSame('pending', $s2FormERow['status']);
    }

    public function test_fast_attachment_classification_matches_file_access_guard_across_all_four_states(): void
    {
        [, $shipment] = $this->createPoWithShipment('SHP/10/2026/010');
        $doc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
        $guard = app(FileAccessGuard::class);

        // 1. VALIDATED inspection
        $validatedAtt = $this->attachValidatedFile($doc, 'validated.pdf');

        // 2. HISTORICAL_UNVERIFIED without inspection (within cutover boundary)
        $legacyNoInspectionAtt = Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $doc->id,
            'file_path' => 'shipments/legacy-cutover.pdf',
            'file_name' => 'legacy-cutover.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $this->supplier->id,
        ]);

        DB::table('file_security_cutovers')->updateOrInsert(
            ['document_table' => 'attachments'],
            ['legacy_max_id' => $legacyNoInspectionAtt->id, 'created_at' => now()],
        );

        // 3. REJECTED inspection
        $rejectedInspection = FileInspection::create([
            'profile' => 'import_document',
            'status' => 'REJECTED',
            'policy_version' => 'v1',
            'file_path' => 'shipments/rejected-inspected.pdf',
            'file_size' => 128,
            'file_type' => 'application/pdf',
            'sha256' => hash('sha256', 'rejected-inspected'),
        ]);
        $rejectedInspectedAtt = Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $doc->id,
            'file_path' => 'shipments/rejected-inspected.pdf',
            'file_name' => 'rejected-inspected.pdf',
            'file_type' => 'application/pdf',
            'file_inspection_id' => $rejectedInspection->id,
            'uploaded_by' => $this->supplier->id,
        ]);

        // 4. ERROR state (post-cutover attachment without inspection)
        $errorAtt = Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $doc->id,
            'file_path' => 'shipments/unverified-post-cutover.pdf',
            'file_name' => 'unverified-post-cutover.pdf',
            'file_type' => 'application/pdf',
            'uploaded_by' => $this->supplier->id,
        ]);

        foreach ([$validatedAtt, $legacyNoInspectionAtt, $rejectedInspectedAtt, $errorAtt] as $attachment) {
            $fresh = Attachment::with('fileInspection')->findOrFail($attachment->id);
            $this->assertSame(
                $guard->classification($fresh),
                $this->calculator->classifyAttachment($fresh),
                "Mismatch for attachment #{$fresh->id} ({$fresh->file_name})",
            );
        }
    }

    public function test_update_selected_updates_only_chosen_shipments_and_syncs_po_document_forward_only(): void
    {
        [$po, $s1, $qItem] = $this->createPoWithShipment('SHP/10/2026/020');
        $s2 = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/021', Shipment::STATUS_SUBMITTED);

        $s1Invoice = $s1->documents()->where('doc_type', 'invoice')->firstOrFail();
        $s2Invoice = $s2->documents()->where('doc_type', 'invoice')->firstOrFail();
        $att1 = $this->attachValidatedFile($s1Invoice, 's1-inv.pdf');
        $s1Invoice->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $result = $this->service->updateSelected(
            po: $po,
            docType: 'invoice',
            rows: [
                [
                    'shipment' => $s1->hash,
                    'expected_status' => 'received',
                    'expected_attachment' => $att1->hash,
                ],
            ],
            target: 'verified',
            reason: null,
            actor: $this->purchasing,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['counts']['updated']);
        $this->assertSame('verified', $s1Invoice->fresh()->status);
        $this->assertSame('pending', $s2Invoice->fresh()->status);
        $this->assertSame('verified', $po->documents()->where('doc_type', 'invoice')->value('status'));

        // Projection reflects 1/2 verified (partial)
        $this->assertSame(1, $result['projection']['documents']['invoice']['verified_count']);
        $this->assertSame(2, $result['projection']['documents']['invoice']['active_shipment_count']);
        $this->assertTrue($result['projection']['documents']['invoice']['partial_verified']);
    }

    public function test_batch_is_all_or_nothing_on_stale_or_invalid_rows_and_preserves_valid_rows_as_not_applied(): void
    {
        [$po, $s1, $qItem] = $this->createPoWithShipment('SHP/10/2026/030');
        $s2 = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/031', Shipment::STATUS_SUBMITTED);

        $s1Invoice = $s1->documents()->where('doc_type', 'invoice')->firstOrFail();
        $s2Invoice = $s2->documents()->where('doc_type', 'invoice')->firstOrFail();
        $att1 = $this->attachValidatedFile($s1Invoice, 's1-inv.pdf');
        $s1Invoice->update(['status' => ShipmentDocument::STATUS_RECEIVED]);
        $s2Invoice->update(['status' => ShipmentDocument::STATUS_RECEIVED]); // no attachment on s2

        try {
            $this->service->updateSelected(
                po: $po,
                docType: 'invoice',
                rows: [
                    [
                        'shipment' => $s1->hash,
                        'expected_status' => 'received',
                        'expected_attachment' => $att1->hash,
                    ],
                    [
                        'shipment' => $s2->hash,
                        'expected_status' => 'received',
                        'expected_attachment' => null,
                    ],
                ],
                target: 'verified',
                reason: null,
                actor: $this->purchasing,
            );
            $this->fail('Expected PoShipmentDocumentBatchRefused to be thrown.');
        } catch (PoShipmentDocumentBatchRefused $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('validation_failed', $e->errorCode);
            $this->assertSame('not_applied', $e->rows[0]['result']);
            $this->assertSame('rejected', $e->rows[1]['result']);
            $this->assertSame('missing_attachment', $e->rows[1]['reason_code']);
        }

        // Neither s1 nor s2 was updated
        $this->assertSame('received', $s1Invoice->fresh()->status);
        $this->assertSame('received', $s2Invoice->fresh()->status);
        $this->assertSame('pending', $po->documents()->where('doc_type', 'invoice')->value('status'));
    }

    public function test_downgrade_requires_reason_and_appends_audit_trail_without_demoting_po_document(): void
    {
        [$po, $s1] = $this->createPoWithShipment('SHP/10/2026/040');
        $s1Invoice = $s1->documents()->where('doc_type', 'invoice')->firstOrFail();
        $this->attachValidatedFile($s1Invoice, 's1-inv.pdf');
        $s1Invoice->update(['status' => ShipmentDocument::STATUS_VERIFIED]);
        $po->documents()->where('doc_type', 'invoice')->update(['status' => 'verified']);

        try {
            $this->service->updateSelected(
                po: $po,
                docType: 'invoice',
                rows: [['shipment' => $s1->hash, 'expected_status' => 'verified']],
                target: 'received',
                reason: '   ',
                actor: $this->purchasing,
            );
            $this->fail('Expected downgrade without reason to be refused.');
        } catch (PoShipmentDocumentBatchRefused $e) {
            $this->assertSame(422, $e->httpStatus);
            $this->assertSame('reason_required', $e->rows[0]['reason_code']);
        }

        $result = $this->service->updateSelected(
            po: $po,
            docType: 'invoice',
            rows: [['shipment' => $s1->hash, 'expected_status' => 'verified']],
            target: 'received',
            reason: 'Wrong invoice number on page 2',
            actor: $this->purchasing,
        );

        $this->assertTrue($result['success']);
        $this->assertSame('received', $s1Invoice->fresh()->status);
        $this->assertStringContainsString('Wrong invoice number on page 2', (string) $s1Invoice->fresh()->notes);
        $this->assertStringContainsString('verified -> received', (string) $s1Invoice->fresh()->notes);

        // PO document remains forward-only ('verified')
        $this->assertSame('verified', $po->documents()->where('doc_type', 'invoice')->value('status'));
    }

    public function test_lock_order_is_shipments_then_shipment_documents_then_po_documents_ascending(): void
    {
        [$po, $s1, $qItem] = $this->createPoWithShipment('SHP/10/2026/050');
        $s2 = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/051', Shipment::STATUS_SUBMITTED);

        $lockTables = [];
        DB::listen(function ($query) use (&$lockTables): void {
            $sql = strtolower($query->sql);
            if (! str_contains($sql, 'for update')) {
                return;
            }
            if (preg_match('/from\s+[`"]?(\w+)[`"]?/i', $sql, $matches)) {
                $lockTables[] = $matches[1];
            }
        });

        // Submit in reverse order (s2 then s1) to prove service sorts ascending before locking
        $this->service->updateSelected(
            po: $po,
            docType: 'invoice',
            rows: [
                ['shipment' => $s2->hash, 'expected_status' => 'pending'],
                ['shipment' => $s1->hash, 'expected_status' => 'pending'],
            ],
            target: 'received',
            reason: null,
            actor: $this->purchasing,
        );

        $this->assertGreaterThanOrEqual(3, count($lockTables));
        $this->assertSame('shipments', $lockTables[0]);
        $this->assertSame('shipment_documents', $lockTables[1]);
        $this->assertSame('po_documents', $lockTables[2]);
    }

    public function test_draft_and_cancelled_shipments_are_rejected_and_excluded_from_denominators_and_completion_notifications(): void
    {
        [$po, $sSubmitted, $qItem] = $this->createPoWithShipment('SHP/10/2026/060');
        $sDraft = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/061', Shipment::STATUS_DRAFT);
        $sCancelled = $this->createAdditionalShipment($po, $qItem, 'SHP/10/2026/062', Shipment::STATUS_CANCELLED);

        foreach ([$sDraft, $sCancelled] as $ineligibleShipment) {
            try {
                $this->service->updateSelected(
                    po: $po,
                    docType: 'invoice',
                    rows: [['shipment' => $ineligibleShipment->hash, 'expected_status' => 'pending']],
                    target: 'received',
                    reason: null,
                    actor: $this->purchasing,
                );
                $this->fail("Expected {$ineligibleShipment->status} shipment to be refused.");
            } catch (PoShipmentDocumentBatchRefused $e) {
                $this->assertSame(422, $e->httpStatus);
                $this->assertSame('invalid_shipment_status', $e->rows[0]['reason_code']);
            }
        }

        // Complete 3 doc types on sSubmitted while draft/cancelled stay pending
        foreach (['bl', 'packing_list', 'form_e'] as $docType) {
            $sSubmitted->documents()->where('doc_type', $docType)->update(['status' => ShipmentDocument::STATUS_DONE]);
            $po->documents()->where('doc_type', $docType)->update(['status' => 'done']);
        }

        // Updating the 4th doc type ('invoice' -> 'received') on the sole active shipment triggers all_received & all_completed notification
        $result = $this->service->updateSelected(
            po: $po,
            docType: 'invoice',
            rows: [['shipment' => $sSubmitted->hash, 'expected_status' => 'pending']],
            target: 'received',
            reason: null,
            actor: $this->purchasing,
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['projection']['active_shipment_count']);
        $this->assertTrue($result['projection']['all_received']);

        $allCompletedNotif = $this->purchasing->notifications()
            ->get()
            ->first(fn ($n) => str_starts_with((string) ($n->data['event_key'] ?? ''), "document.all_completed:{$po->id}:"));
        $this->assertNotNull($allCompletedNotif, 'Expected document.all_completed notification when sole active shipment reaches all_received despite draft/cancelled siblings.');
    }

    public function test_consolidated_shipment_updates_all_affected_pos_and_manual_po_status_never_falsely_verifies_unselected_shipment(): void
    {
        [$po1, $sShared, $qItem] = $this->createPoWithShipment('SHP/10/2026/070');
        $sPo1Only = $this->createAdditionalShipment($po1, $qItem, 'SHP/10/2026/071', Shipment::STATUS_SUBMITTED);

        $po2 = PurchaseOrder::create([
            'po_number' => 'PO/G2-SHARED/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            PoDocument::create([
                'po_id' => $po2->id,
                'doc_type' => $docType,
                'status' => 'pending',
            ]);
        }

        // Link sShared to po2 as well
        $sShared->items()->create([
            'purchase_order_id' => $po2->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 20.0,
        ]);

        $sharedInvoice = $sShared->documents()->where('doc_type', 'invoice')->firstOrFail();
        $att = $this->attachValidatedFile($sharedInvoice, 'shared-inv.pdf');
        $sharedInvoice->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $result = $this->service->updateSelected(
            po: $po1,
            docType: 'invoice',
            rows: [[
                'shipment' => $sShared->hash,
                'expected_status' => 'received',
                'expected_attachment' => $att->hash,
            ]],
            target: 'verified',
            reason: null,
            actor: $this->purchasing,
        );

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['affected_pos']);
        $this->assertSame('verified', $po1->documents()->where('doc_type', 'invoice')->value('status'));
        $this->assertSame('verified', $po2->documents()->where('doc_type', 'invoice')->value('status'));

        // Unselected shipment on PO1 remains 'pending' even though PO1 po_documents.status is now 'verified'
        $this->assertSame('pending', $sPo1Only->documents()->where('doc_type', 'invoice')->value('status'));
        $po1InvoiceProgress = $result['projection']['documents']['invoice'];
        $this->assertSame(2, $po1InvoiceProgress['active_shipment_count']);
        $this->assertSame(1, $po1InvoiceProgress['verified_count']);
        $this->assertFalse($po1InvoiceProgress['all_verified']);
        $this->assertTrue($po1InvoiceProgress['partial_verified']);

        $unselectedRow = collect($po1InvoiceProgress['rows'])->firstWhere('shipment', $sPo1Only->hash);
        $this->assertSame('pending', $unselectedRow['status']);
    }

    /**
     * @return array{0: PurchaseOrder, 1: Shipment, 2: QuotationItem}
     */
    private function createPoWithShipment(string $shipmentNumber): array
    {
        $period = Period::firstOrCreate(
            ['name' => 'Gate 2 Sync Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id],
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/G2/'.uniqid(),
            'status' => 'bidding',
            'created_by' => $this->purchasing->id,
        ]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'SKD11',
            'quantity' => 4,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 10,
            'width' => 100,
            'length' => 500,
            'weight_needed' => 20,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
        ]);

        $qItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 5.0,
            'amount' => 400.0,
            'is_available' => true,
            'available_qty' => 4,
            'available_thickness' => 10,
            'available_width' => 100,
            'available_length' => 500,
            'offered_weight_per_unit' => 20,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO/G2/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            PoDocument::create([
                'po_id' => $po->id,
                'doc_type' => $docType,
                'status' => 'pending',
            ]);
        }

        $shipment = $this->createAdditionalShipment($po, $qItem, $shipmentNumber, Shipment::STATUS_SUBMITTED);

        return [$po->fresh(['documents']), $shipment, $qItem];
    }

    private function createAdditionalShipment(
        PurchaseOrder $po,
        QuotationItem $qItem,
        string $shipmentNumber,
        string $status,
    ): Shipment {
        $shipment = Shipment::create([
            'shipment_number' => $shipmentNumber,
            'supplier_id' => $this->supplier->id,
            'status' => $status,
            'shipment_date' => now(),
            'estimated_arrival_date' => now()->addDays(14),
            'created_by' => $this->supplier->id,
        ]);

        $shipment->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 20.0,
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $shipment->documents()->create([
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_PENDING,
            ]);
        }

        return $shipment->fresh(['documents', 'items']);
    }

    private function attachValidatedFile(ShipmentDocument $doc, string $fileName): Attachment
    {
        $inspection = FileInspection::create([
            'profile' => 'import_document',
            'status' => FileInspection::STATUS_VALIDATED,
            'policy_version' => 'v1',
            'file_path' => 'shipments/'.$fileName,
            'file_size' => 256,
            'file_type' => 'application/pdf',
            'sha256' => hash('sha256', $fileName),
            'inspected_at' => now(),
        ]);

        return Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $doc->id,
            'file_path' => 'shipments/'.$fileName,
            'file_name' => $fileName,
            'file_type' => 'application/pdf',
            'file_inspection_id' => $inspection->id,
            'uploaded_by' => $this->supplier->id,
        ]);
    }
}
