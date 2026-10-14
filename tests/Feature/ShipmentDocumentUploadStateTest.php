<?php

namespace Tests\Feature;

use App\Models\FileInspection;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Models\User;
use App\Services\ShipmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class ShipmentDocumentUploadStateTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('blockedStates')]
    public function test_final_document_or_cancelled_shipment_cannot_be_reuploaded(string $shipmentStatus, string $documentStatus): void
    {
        Storage::fake('private');
        [$user, $shipment, $document] = $this->document($shipmentStatus, $documentStatus);

        $this->actingAs($user)->postJson(route('supplier.shipments.documents.upload', [$shipment, $document->id]), [
            'file' => NativeFileFixtures::upload('shipment.pdf'), 'document_number' => 'CHANGED',
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame($documentStatus, $document->fresh()->status);
        $this->assertSame('ORIGINAL', $document->fresh()->document_number);
        $this->assertDatabaseCount('attachments', 0);
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public static function blockedStates(): array
    {
        return [
            [Shipment::STATUS_SUBMITTED, ShipmentDocument::STATUS_VERIFIED],
            [Shipment::STATUS_ARRIVED, ShipmentDocument::STATUS_DONE],
            [Shipment::STATUS_CANCELLED, ShipmentDocument::STATUS_PENDING],
        ];
    }

    public function test_locked_state_is_rechecked_after_file_admission_and_failed_publication_is_tracked(): void
    {
        Storage::fake('private');
        [$user, $shipment, $document] = $this->document(Shipment::STATUS_SUBMITTED, ShipmentDocument::STATUS_PENDING);
        FileInspection::created(function () use ($document): void {
            $document->update(['status' => ShipmentDocument::STATUS_VERIFIED]);
        });

        try {
            app(ShipmentService::class)->uploadDocument($document, NativeFileFixtures::upload('shipment.pdf'), $user, 'CHANGED');
            $this->fail('A finalized document must reject publication after file admission.');
        } catch (InvalidArgumentException) {
            $this->assertSame(ShipmentDocument::STATUS_VERIFIED, $document->fresh()->status);
            $this->assertSame('ORIGINAL', $document->fresh()->document_number);
            $this->assertDatabaseCount('attachments', 0);
            $this->assertDatabaseHas('file_inspections', ['status' => 'ERROR', 'error_code' => 'publication_failed']);
            $this->assertSame([], Storage::disk('private')->allFiles());
        }
    }

    public function test_pending_document_accepts_upload_and_keeps_native_validation(): void
    {
        Storage::fake('private');
        [$user, $shipment, $document] = $this->document(Shipment::STATUS_SUBMITTED, ShipmentDocument::STATUS_PENDING);
        $attachment = app(ShipmentService::class)->uploadDocument($document, NativeFileFixtures::upload('shipment.pdf'), $user);

        $this->assertSame(ShipmentDocument::STATUS_RECEIVED, $document->fresh()->status);
        Storage::disk('private')->assertExists($attachment->file_path);
        $this->assertDatabaseHas('file_inspections', ['id' => $attachment->file_inspection_id, 'status' => FileInspection::STATUS_VALIDATED]);
    }

    private function document(string $shipmentStatus, string $documentStatus): array
    {
        $user = User::factory()->create();
        $shipment = Shipment::create([
            'shipment_number' => 'SHP/10/2026/001', 'supplier_id' => $user->id, 'status' => $shipmentStatus,
        ]);
        $document = $shipment->documents()->create([
            'doc_type' => ShipmentDocument::DOC_TYPE_INVOICE, 'status' => $documentStatus, 'document_number' => 'ORIGINAL',
        ]);

        return [$user, $shipment, $document];
    }
}
