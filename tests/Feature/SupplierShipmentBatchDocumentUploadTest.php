<?php

namespace Tests\Feature;

use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class SupplierShipmentBatchDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_batch_upload_multiple_documents_atomically(): void
    {
        Storage::fake('private');
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/001',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
        ]);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $shipment->documents()->create([
                'doc_type' => $docType,
                'status' => ShipmentDocument::STATUS_PENDING,
            ]);
        }

        $response = $this->actingAs($supplier)->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
            'documents' => [
                [
                    'doc_type' => 'invoice',
                    'file' => NativeFileFixtures::upload('invoice.pdf'),
                    'document_number' => 'INV-2026-001',
                ],
                [
                    'doc_type' => 'packing_list',
                    'file' => NativeFileFixtures::upload('packing_list.pdf'),
                    'document_number' => 'PL-2026-001',
                ],
                [
                    'doc_type' => 'bl',
                    'file' => NativeFileFixtures::upload('bill_of_lading.pdf'),
                    'document_number' => 'BL-2026-001',
                ],
                [
                    'doc_type' => 'form_e',
                    'file' => NativeFileFixtures::upload('form_e.pdf'),
                    'document_number' => 'FE-2026-001',
                ],
            ],
        ]);

        $response->assertOk();
        $this->assertDatabaseCount('attachments', 4);

        foreach (ShipmentDocument::DOC_TYPES as $docType) {
            $doc = $shipment->documents()->where('doc_type', $docType)->first();
            $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $doc->status);
            $this->assertNotNull($doc->latestAttachment);
            $this->assertNotNull($doc->document_number);
            Storage::disk('private')->assertExists($doc->latestAttachment->file_path);
        }
    }

    public function test_batch_upload_is_atomic_and_rolls_back_when_one_document_is_invalid(): void
    {
        Storage::fake('private');
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/002',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
        ]);

        $shipment->documents()->create(['doc_type' => 'invoice', 'status' => ShipmentDocument::STATUS_PENDING]);
        $shipment->documents()->create(['doc_type' => 'packing_list', 'status' => ShipmentDocument::STATUS_PENDING]);

        // Second document sends an invalid extension file (not in mimes list)
        $invalidFile = UploadedFile::fake()->create('malicious.exe', 500);

        $response = $this->actingAs($supplier)->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
            'documents' => [
                [
                    'doc_type' => 'invoice',
                    'file' => NativeFileFixtures::upload('valid-invoice.pdf'),
                    'document_number' => 'INV-001',
                ],
                [
                    'doc_type' => 'packing_list',
                    'file' => $invalidFile,
                    'document_number' => 'PL-001',
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public function test_batch_upload_fails_if_shipment_is_cancelled_or_document_already_verified(): void
    {
        Storage::fake('private');
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/003',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_CANCELLED,
        ]);

        $shipment->documents()->create(['doc_type' => 'invoice', 'status' => ShipmentDocument::STATUS_PENDING]);

        $response = $this->actingAs($supplier)->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
            'documents' => [
                [
                    'doc_type' => 'invoice',
                    'file' => NativeFileFixtures::upload('invoice.pdf'),
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_supplier_cannot_batch_upload_to_another_supplier_shipment(): void
    {
        Storage::fake('private');
        $owner = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/004',
            'supplier_id' => $owner->id,
            'status' => Shipment::STATUS_SUBMITTED,
        ]);
        $shipment->documents()->create(['doc_type' => 'invoice', 'status' => ShipmentDocument::STATUS_PENDING]);

        $response = $this->actingAs($otherSupplier)->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
            'documents' => [
                [
                    'doc_type' => 'invoice',
                    'file' => NativeFileFixtures::upload('invoice.pdf'),
                ],
            ],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_batch_upload_rejects_duplicate_document_types(): void
    {
        Storage::fake('private');
        $supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/005',
            'supplier_id' => $supplier->id,
            'status' => Shipment::STATUS_SUBMITTED,
        ]);
        $shipment->documents()->create(['doc_type' => 'invoice', 'status' => ShipmentDocument::STATUS_PENDING]);

        $response = $this->actingAs($supplier)->postJson(route('supplier.shipments.documents.batch-upload', $shipment), [
            'documents' => [
                [
                    'doc_type' => 'invoice',
                    'file' => NativeFileFixtures::upload('invoice.pdf'),
                ],
                [
                    'doc_type' => 'invoice',
                    'file' => NativeFileFixtures::upload('invoice_dup.pdf'),
                ],
            ],
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['documents.0.doc_type', 'documents.1.doc_type']);
        $this->assertDatabaseCount('attachments', 0);
    }
}
