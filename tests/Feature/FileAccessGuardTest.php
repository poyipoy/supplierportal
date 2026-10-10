<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\FileSecurity\FileInspectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class FileAccessGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function document(?int $inspection = null): Attachment
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $po = LocalPurchaseOrder::create(['supplier_id' => $user->id, 'po_number' => uniqid('PO-'), 'po_date' => '2026-10-09',
            'total_amount' => '100.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);

        return $po->attachments()->create(['file_path' => 'secure/proof.pdf', 'file_name' => 'proof.pdf', 'file_type' => 'application/pdf',
            'file_inspection_id' => $inspection, 'uploaded_by' => $user->id]);
    }

    public function test_missing_new_inspection_has_no_historical_bypass_even_for_admin(): void
    {
        $document = $this->document();
        Storage::disk('private')->put($document->file_path, NativeFileFixtures::pdf());
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('attachments.show', $document))->assertNotFound();
    }

    public function test_verified_cutover_identity_preserves_legacy_access_without_validated_claim(): void
    {
        $document = $this->document();
        DB::table('file_security_cutovers')->where('document_table', 'attachments')->update(['legacy_max_id' => $document->id]);
        Storage::disk('private')->put($document->file_path, 'historical uninspected bytes');
        $this->assertSame('HISTORICAL_UNVERIFIED', app(FileAccessGuard::class)->classification($document));
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('attachments.show', $document))->assertOk();
    }

    public function test_pending_rejected_error_and_changed_bytes_are_denied(): void
    {
        $stored = app(FileInspectionService::class)->storeUpload(
            UploadedFile::fake()->createWithContent('proof.pdf', NativeFileFixtures::pdf()), 'po_pdf', 'secure/proof.pdf'
        );
        $document = $this->document($stored['file_inspection_id']);
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['PENDING', 'REJECTED', 'ERROR'] as $status) {
            FileInspection::whereKey($stored['file_inspection_id'])->update(['status' => $status]);
            $this->actingAs($admin)->get(route('attachments.show', $document))->assertNotFound();
        }
        FileInspection::whereKey($stored['file_inspection_id'])->update(['status' => 'VALIDATED']);
        Storage::disk('private')->put($document->file_path, 'tampered bytes');
        $this->actingAs($admin)->get(route('attachments.show', $document))->assertNotFound();
    }
}
