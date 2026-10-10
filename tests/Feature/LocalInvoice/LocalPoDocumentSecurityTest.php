<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\Attachment;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalPoDocumentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class LocalPoDocumentSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_header_only_pdf_is_rejected_without_attachment_or_audit(): void
    {
        [$actor, $supplier] = $this->actors();
        $po = $this->po($supplier, 'PO-SECURE-1');
        $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $supplier->id,
            'file' => UploadedFile::fake()->createWithContent('PO-SECURE-1.pdf', "%PDF-1.4\nspoof"),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame(0, $po->attachments()->count());
        $this->assertDatabaseMissing('local_finance_audit_logs', ['action' => 'po_document_uploaded']);
    }

    public function test_valid_pdf_creates_validated_inspection_and_json_completion(): void
    {
        [$actor, $supplier] = $this->actors();
        $po = $this->po($supplier, 'PO-SECURE-1');
        $this->actingAs($actor)->postJson(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $supplier->id,
            'file' => UploadedFile::fake()->createWithContent('PO-SECURE-1.pdf', self::pdf()),
        ])->assertOk()->assertJsonPath('status', 'COMPLETED');
        $attachment = $po->attachments()->firstOrFail();
        $this->assertNotNull($attachment->file_inspection_id);
        $this->assertDatabaseHas('file_inspections', ['id' => $attachment->file_inspection_id, 'status' => 'VALIDATED']);
        Storage::disk('private')->assertExists($attachment->file_path);
    }

    public function test_native_entry_budget_rejects_zip_without_partial_publication(): void
    {
        [$actor, $supplier] = $this->actors();
        $this->po($supplier, 'PO-SECURE-1');
        config(['native_file_security.zip.max_entry_bytes' => 64]);
        $file = $this->zip(['PO-SECURE-1.pdf' => self::pdf()]);
        try {
            app(LocalPoDocumentService::class)->uploadZip($actor, $supplier, $file);
            $this->fail('An entry above its budget was accepted.');
        } catch (ValidationException) {
            $this->assertSame(0, Attachment::where('attachable_type', LocalPurchaseOrder::class)->count());
        } finally {
            @unlink($file->getPathname());
        }
    }

    public function test_zip_with_unmatched_po_preserves_existing_attachment(): void
    {
        [$actor, $supplier] = $this->actors();
        $po = $this->po($supplier, 'PO-SECURE-1');
        app(LocalPoDocumentService::class)->uploadSinglePdf($actor, $supplier, UploadedFile::fake()->createWithContent('PO-SECURE-1.pdf', self::pdf()));
        $existing = $po->attachments()->firstOrFail();
        $file = $this->zip(['PO-SECURE-1.pdf' => self::pdf(), 'PO-UNKNOWN.pdf' => self::pdf()]);
        try {
            app(LocalPoDocumentService::class)->uploadZip($actor, $supplier, $file);
            $this->fail('An unmatched PO was accepted.');
        } catch (ValidationException) {
            $this->assertSame([$existing->id], $po->attachments()->pluck('id')->all());
            Storage::disk('private')->assertExists($existing->file_path);
        } finally {
            @unlink($file->getPathname());
        }
    }

    private function actors(): array
    {
        $actor = User::factory()->create(['role' => 'finance']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        SupplierScope::create(['supplier_id' => $supplier->id, 'scope' => 'local']);

        return [$actor, $supplier];
    }

    private function po(User $supplier, string $number): LocalPurchaseOrder
    {
        return LocalPurchaseOrder::create(['supplier_id' => $supplier->id, 'po_number' => $number,
            'po_date' => '2026-10-09', 'total_amount' => '1000.00', 'currency' => 'IDR', 'status' => 'OPEN', 'source' => 'MANUAL']);
    }

    private function zip(array $entries): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'po-security-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return new UploadedFile($path, 'documents.zip', 'application/zip', null, true);
    }

    public static function pdf(): string
    {
        $pdf = "%PDF-1.4\n";
        $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] >>'];
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 4\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf."trailer\n<< /Size 4 /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }
}
