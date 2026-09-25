<?php

namespace Tests\Feature;

use App\Models\Attachment;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PoCustomsDocumentationShipmentSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $supplier;

    private User $otherSupplier;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
    }

    public function test_uploading_shipment_document_automatically_syncs_po_document_status_to_received(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        // Verify initial PO documents are pending
        $poPackingList = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->assertEquals('pending', $poPackingList->status);

        $shipmentPackingList = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->assertEquals('pending', $shipmentPackingList->status);

        // Upload file on shipment packing list
        $file = UploadedFile::fake()->create('packing_list_shp01.pdf', 300, 'application/pdf');

        $response = $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment, 'document_id' => $shipmentPackingList->id]), [
                'file' => $file,
                'document_number' => 'PL-2026-001',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        // Verify shipment document is received with attachment
        $shipmentPackingList->refresh();
        $this->assertEquals(ShipmentDocument::STATUS_RECEIVED, $shipmentPackingList->status);
        $this->assertCount(1, $shipmentPackingList->attachments);

        // CRITICAL INVARIANT: Corresponding PO document MUST be synced to 'received'!
        $poPackingList->refresh();
        $this->assertEquals('received', $poPackingList->status);
    }

    public function test_purchasing_updating_shipment_document_status_syncs_po_document_status(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $shipmentInvoice = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
        $poInvoice = $po->documents()->where('doc_type', 'invoice')->firstOrFail();

        // Purchasing marks shipment invoice as 'verified'
        $response = $this->actingAs($this->purchasing)
            ->put(route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $shipmentInvoice->id]), [
                'status' => ShipmentDocument::STATUS_VERIFIED,
                'notes' => 'Invoice verified against customs declaration.',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $shipmentInvoice->refresh();
        $this->assertEquals(ShipmentDocument::STATUS_VERIFIED, $shipmentInvoice->status);

        // CRITICAL INVARIANT: PO Document must be synced to 'verified'!
        $poInvoice->refresh();
        $this->assertEquals('verified', $poInvoice->status);
    }

    public function test_po_document_status_does_not_downgrade_when_lower_status_is_provided(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $poDoc = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $poDoc->update(['status' => 'verified']);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();

        // Purchasing sets shipment doc status to 'received' (lower rank than 'verified')
        $this->actingAs($this->purchasing)
            ->put(route('purchasing.shipments.documents.status', ['id' => $shipment, 'document_id' => $shipmentDoc->id]), [
                'status' => ShipmentDocument::STATUS_RECEIVED,
            ]);

        $poDoc->refresh();
        // Must remain 'verified' and not downgrade to 'received'
        $this->assertEquals('verified', $poDoc->status);
    }

    public function test_supplier_po_detail_view_renders_shipment_link_and_file_view_link(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();

        $file = UploadedFile::fake()->create('packing_list.pdf', 300, 'application/pdf');
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment, 'document_id' => $shipmentDoc->id]), [
                'file' => $file,
            ]);

        $shipmentDoc->refresh();
        $attachment = $shipmentDoc->attachments->first();
        $this->assertNotNull($attachment);

        // Visit Supplier PO show page
        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.purchase-orders.show', $po));

        $response->assertOk();
        // Must see shipment number
        $response->assertSee($shipment->shipment_number, false);
        // Must see link to shipment detail
        $response->assertSee(route('supplier.shipments.show', $shipment), false);
        // Must see link to view attachment
        $response->assertSee(route('attachments.show', $attachment->id), false);
    }

    public function test_purchasing_po_detail_view_renders_shipment_link_and_file_view_link(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();

        $file = UploadedFile::fake()->create('commercial_invoice.pdf', 300, 'application/pdf');
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment, 'document_id' => $shipmentDoc->id]), [
                'file' => $file,
            ]);

        $shipmentDoc->refresh();
        $attachment = $shipmentDoc->attachments->first();

        // Visit Purchasing PO show page
        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po));

        $response->assertOk();
        // Must see shipment number
        $response->assertSee($shipment->shipment_number, false);
        // Must see link to shipment detail
        $response->assertSee(route('purchasing.shipments.show', $shipment), false);
        // Must see link to view attachment
        $response->assertSee(route('attachments.show', $attachment->id), false);
    }

    public function test_partial_shipments_renders_all_uploaded_files_per_document_type_on_po_detail(): void
    {
        [$po, $shipment1, $qItem] = $this->createPoAndShipment($this->supplier);

        // Create second shipment for the same PO
        $shipment2 = Shipment::create([
            'shipment_number' => 'SHP/09/2026/002',
            'supplier_id' => $this->supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => now()->addDays(2),
            'estimated_arrival_date' => now()->addDays(16),
            'created_by' => $this->supplier->id,
        ]);

        $shipment2->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 10.0,
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $shipment2->documents()->create([
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_PENDING,
            ]);
        }

        // Upload Packing List on Shipment 1
        $file1 = UploadedFile::fake()->create('pl_shipment_1.pdf', 200, 'application/pdf');
        $doc1 = $shipment1->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment1, 'document_id' => $doc1->id]), [
                'file' => $file1,
            ]);

        // Upload Packing List on Shipment 2
        $file2 = UploadedFile::fake()->create('pl_shipment_2.pdf', 250, 'application/pdf');
        $doc2 = $shipment2->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment2, 'document_id' => $doc2->id]), [
                'file' => $file2,
            ]);

        // Check Supplier PO detail
        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.purchase-orders.show', $po));

        $response->assertOk();
        // Both shipment numbers should be present on the PO detail page
        $response->assertSee($shipment1->shipment_number, false);
        $response->assertSee($shipment2->shipment_number, false);
    }

    public function test_supplier_cannot_view_or_access_shipment_document_of_another_supplier(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $doc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $file = UploadedFile::fake()->create('confidential_pl.pdf', 200, 'application/pdf');

        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment, 'document_id' => $doc->id]), [
                'file' => $file,
            ]);

        $att = $doc->fresh()->attachments->first();

        // Other supplier attempts to view this attachment via attachments.show
        $response = $this->actingAs($this->otherSupplier)
            ->get(route('attachments.show', $att->id));

        $response->assertForbidden();
    }

    public function test_customs_documentation_summary_reports_effective_status_without_writing(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $poDoc = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->assertEquals('pending', $poDoc->status);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $shipmentDoc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $summary = $po->fresh()->customsDocumentationSummary();

        // The summary still reports the healed status to the view ...
        $this->assertEquals('received', $summary['packing_list']['status']);

        // ... but a read performs no writes (plan H2: 0 writes).
        $this->assertSame([], $writes, 'customsDocumentationSummary() must not write to the database.');
        $this->assertEquals('pending', $poDoc->fresh()->status);
    }

    public function test_explicit_reconciliation_persists_po_document_status_and_is_idempotent(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $poDoc = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->assertEquals('pending', $poDoc->status);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $shipmentDoc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $this->assertSame(1, $po->fresh()->reconcileCustomsDocumentationStatus());
        $this->assertEquals('received', $poDoc->fresh()->status);

        // Running it again changes nothing.
        $this->assertSame(0, $po->fresh()->reconcileCustomsDocumentationStatus());
        $this->assertEquals('received', $poDoc->fresh()->status);
    }

    public function test_reconciliation_never_demotes_a_more_advanced_po_document_status(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $poDoc = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $poDoc->update(['status' => 'verified']);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $shipmentDoc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $this->assertSame(0, $po->fresh()->reconcileCustomsDocumentationStatus());
        $this->assertEquals('verified', $poDoc->fresh()->status);
    }

    public function test_po_detail_request_still_persists_the_healed_document_status(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $poDoc = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $shipmentDoc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po))
            ->assertOk();

        $this->assertEquals('received', $poDoc->fresh()->status);
    }

    public function test_supplier_po_detail_request_is_pure_read_and_does_not_mutate_database(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $poDoc = $po->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $this->assertEquals('pending', $poDoc->status);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'packing_list')->firstOrFail();
        $shipmentDoc->update(['status' => ShipmentDocument::STATUS_RECEIVED]);

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete)\s/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $response = $this->actingAs($this->supplier)
            ->get(route('supplier.purchase-orders.show', $po));

        $response->assertOk();
        $this->assertSame([], $writes, 'GET supplier.purchase-orders.show must not write to the database.');
        $this->assertEquals('pending', $poDoc->fresh()->status);
    }

    public function test_purchasing_po_detail_calculates_document_completion_accurately(): void
    {
        [$po, $shipment] = $this->createPoAndShipment($this->supplier);

        $shipmentDoc = $shipment->documents()->where('doc_type', 'invoice')->firstOrFail();
        $file = UploadedFile::fake()->create('invoice.pdf', 200, 'application/pdf');

        $this->actingAs($this->supplier)
            ->post(route('supplier.shipments.documents.upload', ['id' => $shipment, 'document_id' => $shipmentDoc->id]), [
                'file' => $file,
            ]);

        $response = $this->actingAs($this->purchasing)
            ->get(route('purchasing.purchase-orders.show', $po));

        $response->assertOk();
        // 1 of 4 mandatory documents is now completed/received
        $response->assertSee('1/4 complete');
        $response->assertSee('Accepted'); // Invoice status label for received
    }

    private function createPoAndShipment(User $supplier): array
    {
        $period = Period::firstOrCreate(
            ['name' => 'Customs Sync Period', 'year' => 2026],
            ['status' => 'open', 'created_by' => $this->purchasing->id]
        );

        $pr = PurchaseRequisition::create([
            'period_id' => $period->id,
            'pr_number' => 'REQ/SYNC/'.uniqid(),
            'status' => 'bidding',
            'created_by' => $this->purchasing->id,
        ]);
        $pr->invitedSuppliers()->syncWithoutDetaching([$supplier->id]);

        $prItem = PrItem::create([
            'pr_id' => $pr->id,
            'hs_code' => '7209.16.00',
            'material_name' => 'Tool Steel 1.2379',
            'quantity' => 2,
            'shape' => PrItem::SHAPE_FLAT,
            'thickness' => 10,
            'width' => 100,
            'length' => 500,
            'weight_needed' => 20,
        ]);

        $quotation = Quotation::create([
            'pr_id' => $pr->id,
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => Quotation::STATUS_SUBMITTED,
        ]);

        $qItem = $quotation->items()->create([
            'pr_item_id' => $prItem->id,
            'price_per_kg' => 5.0,
            'amount' => 100.0,
            'is_available' => true,
            'available_qty' => 2,
            'available_thickness' => 10,
            'available_width' => 100,
            'available_length' => 500,
            'offered_weight_per_unit' => 20,
        ]);

        $po = PurchaseOrder::create([
            'po_number' => 'PO/SYNC/'.uniqid(),
            'supplier_id' => $supplier->id,
            'currency' => 'USD',
            'status' => 'active',
            'created_by' => $this->purchasing->id,
            'estimated_arrival' => now()->addDays(14),
        ]);

        // Create standard PoDocument records
        foreach (['invoice', 'bl', 'packing_list', 'form_e'] as $docType) {
            PoDocument::create([
                'po_id' => $po->id,
                'doc_type' => $docType,
                'status' => 'pending',
            ]);
        }

        $shipment = Shipment::create([
            'shipment_number' => 'SHP/09/2026/001',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
            'shipment_date' => now(),
            'estimated_arrival_date' => now()->addDays(14),
            'created_by' => $supplier->id,
        ]);

        $shipment->items()->create([
            'purchase_order_id' => $po->id,
            'quotation_item_id' => $qItem->id,
            'shipped_qty' => 1,
            'actual_weight_kg' => 10.0,
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $shipment->documents()->create([
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_PENDING,
            ]);
        }

        return [$po->fresh(['documents']), $shipment->fresh(['documents', 'items']), $qItem];
    }
}
