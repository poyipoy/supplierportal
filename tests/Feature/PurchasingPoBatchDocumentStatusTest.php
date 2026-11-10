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

class PurchasingPoBatchDocumentStatusTest extends TestCase
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

    public function test_purchasing_can_batch_update_multi_doc_types_from_po(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');
        $this->attachValidatedFile($docs['invoice'], 'inv.pdf');
        $this->attachValidatedFile($docs['bl'], 'bl.pdf');
        $docs['invoice']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);
        $docs['bl']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $payload = [
            'documents' => [
                'invoice' => [
                    'rows' => [
                        [
                            'shipment' => (string) $shipment->hash,
                            'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                            'expected_attachment' => $docs['invoice']->latestAttachment->hash,
                        ],
                    ],
                    'target_status' => ShipmentDocument::STATUS_VERIFIED,
                    'reason' => 'Invoice verified',
                ],
                'bl' => [
                    'rows' => [
                        [
                            'shipment' => (string) $shipment->hash,
                            'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                            'expected_attachment' => $docs['bl']->latestAttachment->hash,
                        ],
                    ],
                    'target_status' => ShipmentDocument::STATUS_VERIFIED,
                    'reason' => 'BL verified',
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasing)->putJson(
            route('purchasing.purchase-orders.documents.shipments.batch-update', $po),
            $payload
        );

        $response->assertOk();
        $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $docs['invoice']->fresh()->status);
        $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $docs['bl']->fresh()->status);
    }

    public function test_multi_doc_type_batch_rolls_back_all_if_one_doc_type_fails(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');
        $this->attachValidatedFile($docs['invoice'], 'inv.pdf');
        // Do NOT attach file to BL - it will fail because target is VERIFIED but attachment is missing
        $docs['invoice']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);
        $docs['bl']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $payload = [
            'documents' => [
                'invoice' => [
                    'rows' => [
                        [
                            'shipment' => (string) $shipment->hash,
                            'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                            'expected_attachment' => $docs['invoice']->latestAttachment->hash,
                        ],
                    ],
                    'target_status' => ShipmentDocument::STATUS_VERIFIED,
                ],
                'bl' => [
                    'rows' => [
                        [
                            'shipment' => (string) $shipment->hash,
                            'expected_status' => ShipmentDocument::STATUS_RECEIVED,
                        ],
                    ],
                    'target_status' => ShipmentDocument::STATUS_VERIFIED, // missing attachment -> refusal
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasing)->putJson(
            route('purchasing.purchase-orders.documents.shipments.batch-update', $po),
            $payload
        );

        $response->assertUnprocessable();
        // Assert invoice was NOT changed (rolled back)
        $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $docs['invoice']->fresh()->status);
        $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $docs['bl']->fresh()->status);
    }

    public function test_purchasing_po_show_page_renders_with_batch_dialog_button(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.purchase-orders.show', $po));

        $response->assertOk();
        $response->assertSee('id="btnOpenBatchPoDocDialog"', false);
        $response->assertSee('id="batchPoDocDialogRoot"', false);
    }

    public function test_batch_update_rejects_with_409_when_expected_status_is_stale(): void
    {
        [$po, $shipment] = $this->createPoWithShipment();

        $docs = $shipment->documents()->get()->keyBy('doc_type');
        $this->attachValidatedFile($docs['invoice'], 'inv.pdf');
        $docs['invoice']->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        // Expecting PENDING, but current is RECEIVED (concurrent modification / stale client state)
        $payload = [
            'documents' => [
                'invoice' => [
                    'rows' => [
                        [
                            'shipment' => (string) $shipment->hash,
                            'expected_status' => ShipmentDocument::STATUS_PENDING,
                        ],
                    ],
                    'target_status' => ShipmentDocument::STATUS_VERIFIED,
                ],
            ],
        ];

        $response = $this->actingAs($this->purchasing)->putJson(
            route('purchasing.purchase-orders.documents.shipments.batch-update', $po),
            $payload
        );

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'stale_state');
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
