<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PurchasingShipmentBatchDocumentStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    public function test_purchasing_can_batch_update_document_statuses_atomically(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');
        $this->attachValidatedFile($docs['invoice'], 'inv.pdf');
        $this->attachValidatedFile($docs['packing_list'], 'pl.pdf');
        $docs['invoice']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);
        $docs['packing_list']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $payload = [
            'documents' => [
                [
                    'document_id' => $docs['invoice']->id,
                    'status' => ShipmentDocument::STATUS_VERIFIED,
                    'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                    'expected_attachment' => $docs['invoice']->latestAttachment->hash,
                ],
                [
                    'document_id' => $docs['packing_list']->id,
                    'status' => ShipmentDocument::STATUS_VERIFIED,
                    'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                    'expected_attachment' => $docs['packing_list']->latestAttachment->hash,
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasing)->putJson(
            route('purchasing.shipments.documents.batch-status', $shipment),
            $payload
        );

        $response->assertOk();
        $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $docs['invoice']->fresh()->status);
        $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $docs['packing_list']->fresh()->status);
    }

    public function test_purchasing_can_verify_all_documents_with_quick_action(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        foreach ($shipment->documents as $doc) {
            $this->attachValidatedFile($doc, "{$doc->doc_type}.pdf");
            $doc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);
        }

        $response = $this->actingAs($this->purchasing)->postJson(
            route('purchasing.shipments.documents.verify-all', $shipment)
        );

        $response->assertOk();

        foreach ($shipment->documents()->get() as $doc) {
            $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $doc->status);
        }
    }

    public function test_batch_status_update_fails_all_if_one_requires_reason_and_is_missing(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');
        $this->attachValidatedFile($docs['invoice'], 'inv.pdf');
        $this->attachValidatedFile($docs['bl'], 'bl.pdf');
        $docs['invoice']->update(['status' => ShipmentDocument::STATUS_VERIFIED]);
        $docs['bl']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        // Attempting to downgrade invoice from VERIFIED to RECEIVED without reason
        $payload = [
            'documents' => [
                [
                    'document_id' => $docs['invoice']->id,
                    'status' => ShipmentDocument::STATUS_RECEIVED,
                    'reason' => '', // missing reason
                    'expected_status' => ShipmentDocument::STATUS_VERIFIED,
                ],
                [
                    'document_id' => $docs['bl']->id,
                    'status' => ShipmentDocument::STATUS_VERIFIED,
                    'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                    'expected_attachment' => $docs['bl']->latestAttachment->hash,
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasing)->putJson(
            route('purchasing.shipments.documents.batch-status', $shipment),
            $payload
        );

        $response->assertUnprocessable();
        // All-or-nothing: invoice stays VERIFIED, bl stays RECEIVED
        $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $docs['invoice']->fresh()->status);
        $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $docs['bl']->fresh()->status);
    }

    public function test_batch_status_update_rejects_duplicate_document_types(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');

        $payload = [
            'documents' => [
                [
                    'document_id' => $docs['invoice']->id,
                    'doc_type' => 'invoice',
                    'status' => ShipmentDocument::STATUS_RECEIVED,
                ],
                [
                    'document_id' => $docs['invoice']->id,
                    'doc_type' => 'invoice',
                    'status' => ShipmentDocument::STATUS_RECEIVED,
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasing)->putJson(
            route('purchasing.shipments.documents.batch-status', $shipment),
            $payload
        );

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['documents.0.doc_type', 'documents.1.doc_type']);
    }

    public function test_purchasing_shipment_show_page_renders_with_batch_action_buttons(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');
        $this->attachValidatedFile($docs['invoice'], 'inv.pdf');
        $docs['invoice']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.shipments.show', $shipment));

        $response->assertOk();
        $response->assertSee('id="btnVerifyAllDocs"', false);
        $response->assertSee('id="btnSaveAllDocStatuses"', false);
    }

    private function createPoWithShipment(): array
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

        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
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

        return [$po, $shipment->fresh(['documents', 'items'])];
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
