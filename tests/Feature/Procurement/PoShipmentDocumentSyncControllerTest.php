<?php

namespace Tests\Feature\Procurement;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\Period;
use App\Models\PoDocument;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseOrder;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PoShipmentDocumentSyncControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $admin;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);
    }

    public function test_only_purchasing_can_access_shipment_document_batch_update_endpoint(): void
    {
        [$po, $shipment] = $this->createPoWithShipment('SHP/10/2026/101');
        $url = route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'invoice']);
        $payload = [
            'target_status' => 'received',
            'rows' => [['shipment' => $shipment->hash, 'expected_status' => 'pending']],
        ];

        $this->putJson($url, $payload)->assertUnauthorized();

        foreach ([$this->admin, $this->supplier, $this->qc] as $forbiddenActor) {
            $this->actingAs($forbiddenActor)->putJson($url, $payload)->assertForbidden();
        }

        $this->actingAs($this->purchasing)->putJson($url, $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('counts.updated', 1);
    }

    public function test_raw_numeric_po_id_or_shipment_id_is_rejected(): void
    {
        [$po, $shipment] = $this->createPoWithShipment('SHP/10/2026/102');

        // Raw numeric PO ID in URL -> 404 from DecodeHashids
        $this->actingAs($this->purchasing)
            ->putJson("/purchasing/purchase-orders/{$po->id}/documents/invoice/shipments", [
                'target_status' => 'received',
                'rows' => [['shipment' => $shipment->hash, 'expected_status' => 'pending']],
            ])
            ->assertNotFound();

        // Raw numeric shipment ID in body -> 422 from UpdatePoShipmentDocumentStatusRequest
        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'invoice']), [
                'target_status' => 'received',
                'rows' => [['shipment' => (string) $shipment->id, 'expected_status' => 'pending']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.0.shipment']);
    }

    public function test_invalid_target_status_for_doc_type_and_duplicate_shipments_return_422(): void
    {
        [$po, $shipment] = $this->createPoWithShipment('SHP/10/2026/103');
        $url = route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'bl']);

        // 'verified' is not valid for 'bl' (bl allows pending, issued, done)
        $this->actingAs($this->purchasing)
            ->putJson($url, [
                'target_status' => 'verified',
                'rows' => [['shipment' => $shipment->hash, 'expected_status' => 'pending']],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['target_status']);

        // Duplicate shipment rows
        $this->actingAs($this->purchasing)
            ->putJson($url, [
                'target_status' => 'issued',
                'rows' => [
                    ['shipment' => $shipment->hash, 'expected_status' => 'pending'],
                    ['shipment' => $shipment->hash, 'expected_status' => 'pending'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rows.0.shipment', 'rows.1.shipment']);
    }

    public function test_stale_status_or_stale_attachment_returns_409_conflict_with_fresh_rows_state(): void
    {
        [$po, $shipment] = $this->createPoWithShipment('SHP/10/2026/104');
        $doc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
        $att1 = $this->attachValidatedFile($doc, 'inv-v1.pdf');
        $doc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $url = route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'invoice']);

        // Stale status: client expects 'pending', actual is 'received'
        $this->actingAs($this->purchasing)
            ->putJson($url, [
                'target_status' => 'received',
                'rows' => [['shipment' => $shipment->hash, 'expected_status' => 'pending']],
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'stale_state')
            ->assertJsonPath('rows.0.result', 'stale')
            ->assertJsonPath('rows.0.reason_code', 'stale_status')
            ->assertJsonPath('rows_state.0.status', 'received');

        // Stale attachment: supplier uploads v2 while status stays 'received'
        $att2 = $this->attachValidatedFile($doc, 'inv-v2.pdf');

        $this->actingAs($this->purchasing)
            ->putJson($url, [
                'target_status' => 'verified',
                'rows' => [[
                    'shipment' => $shipment->hash,
                    'expected_status' => 'received',
                    'expected_attachment' => $att1->hash,
                ]],
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'stale_state')
            ->assertJsonPath('rows.0.result', 'stale')
            ->assertJsonPath('rows.0.reason_code', 'stale_attachment')
            ->assertJsonPath('rows_state.0.attachment_hash', $att2->hash);

        $this->assertSame('received', $doc->fresh()->status);
    }

    public function test_legacy_po_document_endpoint_blocks_shipment_backed_po_but_allows_legacy_po(): void
    {
        [$shipmentPo] = $this->createPoWithShipment('SHP/10/2026/105');
        $shipmentPoDoc = $shipmentPo->documents()->where('doc_type', 'invoice')->firstOrFail();

        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $shipmentPoDoc), [
                'status' => 'received',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('documents.sync.legacy_endpoint_blocked'));

        $this->assertSame('pending', $shipmentPoDoc->fresh()->status);

        // Legacy PO (no awards, no shipment items) still works on legacy endpoint
        $legacyPo = PurchaseOrder::create([
            'po_number' => 'PO/LEGACY/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
        $legacyDoc = PoDocument::create([
            'po_id' => $legacyPo->id,
            'doc_type' => 'invoice',
            'status' => 'pending',
        ]);

        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $legacyDoc), [
                'status' => 'received',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('doc.status', 'received');
    }

    public function test_award_based_po_without_active_shipment_cannot_bypass_decision_b_via_legacy_endpoint_and_renders_waiting_shipment_state(): void
    {
        $period = Period::firstOrCreate(
            ['name' => 'Gate 2 Award Guard Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id],
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/G2AWD/'.uniqid(),
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

        $awardPo = PurchaseOrder::create([
            'po_number' => 'PO/G2AWD/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);

        PrItemAward::create([
            'pr_id' => $pr->id,
            'pr_item_id' => $prItem->id,
            'quotation_id' => $quotation->id,
            'quotation_item_id' => $qItem->id,
            'supplier_id' => $this->supplier->id,
            'purchase_order_id' => $awardPo->id,
            'awarded_by' => $this->purchasing->id,
            'awarded_at' => now(),
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            PoDocument::create([
                'po_id' => $awardPo->id,
                'doc_type' => $docType,
                'status' => 'pending',
            ]);
        }

        $awardPoDoc = $awardPo->documents()->where('doc_type', 'invoice')->firstOrFail();
        $this->assertFalse($awardPo->fresh()->isLegacyArrivalEligible());

        // 1) Award-based PO with 0 shipments cannot bypass Decision B via legacy endpoint
        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $awardPoDoc), [
                'status' => 'received',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('documents.sync.legacy_endpoint_blocked'));

        $this->assertSame('pending', $awardPoDoc->fresh()->status);

        // UI renders waiting-shipment state, not legacy .btn-update-doc or active shipment dialog button
        $showZeroShipment = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $awardPo));
        $showZeroShipment->assertOk();
        $showZeroShipment->assertSee('data-doc-waiting-shipment="invoice"', false);
        $showZeroShipment->assertSee(__('documents.sync.waiting_for_shipment'));
        $showZeroShipment->assertDontSee('btn-update-doc tw:w-full', false);
        $showZeroShipment->assertDontSee('data-update-url=', false);

        // 2) Even if a draft or cancelled shipment is created for the award-based PO,
        //    legacy endpoint remains blocked and UI remains waiting-shipment
        $draftShipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/AWD-DRAFT',
            'supplier_id' => $this->supplier->id,
            'status' => Shipment::STATUS_DRAFT,
            'shipment_date' => now(),
            'estimated_arrival_date' => now()->addDays(14),
            'created_by' => $this->supplier->id,
        ]);
        $draftShipment->items()->create([
            'purchase_order_id' => $awardPo->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 20.0,
        ]);
        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $draftShipment->documents()->create([
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_PENDING,
            ]);
        }

        $this->actingAs($this->purchasing)
            ->putJson(route('purchasing.po-documents.update', $awardPoDoc), [
                'status' => 'received',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', __('documents.sync.legacy_endpoint_blocked'));

        $showDraftShipment = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $awardPo));
        $showDraftShipment->assertOk();
        $showDraftShipment->assertSee('data-doc-waiting-shipment="invoice"', false);
        $showDraftShipment->assertSee(__('documents.sync.waiting_for_shipment'));
        $showDraftShipment->assertDontSee('btn-update-doc tw:w-full', false);
        $showDraftShipment->assertDontSee('data-update-url=', false);
    }

    public function test_purchasing_po_show_renders_shipment_dialog_counters_and_legacy_fallback(): void
    {
        [$po, $shipment] = $this->createPoWithShipment('SHP/10/2026/106');
        $invDoc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
        $this->attachValidatedFile($invDoc, 'inv-106.pdf');
        $invDoc->update(['status' => ShipmentDocument::STATUS_VERIFIED]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po));

        $response->assertOk();
        $response->assertSee('btn-open-shipment-doc-dialog', false);
        $response->assertSee('id="shipmentDocDialogRoot"', false);
        $response->assertSee('id="btnSubmitShipmentDocStatus"', false);
        $response->assertSee('1/1 received');
        $response->assertSee('1/1 verified');
        $response->assertSee(
            route('purchasing.purchase-orders.documents.shipments.update', ['id' => $po, 'doc_type' => 'invoice']),
            false,
        );

        // Legacy PO renders .btn-update-doc and legacy manual status label
        $legacyPo = PurchaseOrder::create([
            'po_number' => 'PO/LEGACY-UI/'.uniqid(),
            'supplier_id' => $this->supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);
        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            PoDocument::create([
                'po_id' => $legacyPo->id,
                'doc_type' => $docType,
                'status' => 'pending',
            ]);
        }

        $legacyResponse = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $legacyPo));

        $legacyResponse->assertOk();
        $legacyResponse->assertSee('btn-update-doc', false);
        $legacyResponse->assertDontSee('data-update-url=', false);
        $legacyResponse->assertSee(__('documents.sync.legacy_manual_status'));
    }

    /**
     * @return array{0: PurchaseOrder, 1: Shipment, 2: QuotationItem}
     */
    private function createPoWithShipment(string $shipmentNumber): array
    {
        $period = Period::firstOrCreate(
            ['name' => 'Gate 2 Controller Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id],
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/G2C/'.uniqid(),
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
            'po_number' => 'PO/G2C/'.uniqid(),
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
            'shipment_number' => $shipmentNumber,
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

        return [$po->fresh(['documents']), $shipment->fresh(['documents', 'items']), $qItem];
    }

    private function attachValidatedFile(ShipmentDocument $doc, string $fileName): Attachment
    {
        $inspection = FileInspection::create([
            'profile' => 'import_document',
            'status' => FileInspection::STATUS_VALIDATED,
            'policy_version' => 'v1',
            'file_path' => 'shipments/'.$fileName.'-'.uniqid(),
            'file_size' => 256,
            'file_type' => 'application/pdf',
            'sha256' => hash('sha256', $fileName.uniqid()),
            'inspected_at' => now(),
        ]);

        return Attachment::create([
            'attachable_type' => ShipmentDocument::class,
            'attachable_id' => $doc->id,
            'file_path' => $inspection->file_path,
            'file_name' => $fileName,
            'file_type' => 'application/pdf',
            'file_inspection_id' => $inspection->id,
            'uploaded_by' => $this->supplier->id,
        ]);
    }
}
