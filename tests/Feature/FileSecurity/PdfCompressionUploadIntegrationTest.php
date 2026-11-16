<?php

namespace Tests\Feature\FileSecurity;

use App\Models\Attachment;
use App\Models\FileInspection;
use App\Models\LocalFinanceAuditLog;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\FileSecurity\FileAccessGuard;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\FileSecurity\NativeArchiveInspector;
use App\Services\LocalInvoice\InvoiceDocumentService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalGrDocumentService;
use App\Services\LocalInvoice\LocalPoDocumentService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;
use ZipArchive;

class PdfCompressionUploadIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();

        $this->operator = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Compress Test',
            'category' => 'Parts',
            'vendor_category' => 'Parts',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);
    }

    public function test_single_po_gr_and_local_invoice_pdfs_compress_on_storage_and_pass_file_access_guard(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->operator, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-COMP-001',
            'po_date' => BusinessTime::today()->toDateString(),
            'total_amount' => '1000000.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->operator, $po, [
            'gr_number' => 'GR-COMP-001',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => 5,
            'uom' => 'pcs',
        ]);

        $bloatedPdf = $this->makeBloatedImagePdf();
        $rawLength = strlen($bloatedPdf);

        // 1. Single PO PDF upload compresses & syncs FileInspection + Attachment.
        $poUpload = UploadedFile::fake()->createWithContent('PO-COMP-001.pdf', $bloatedPdf);
        app(LocalPoDocumentService::class)->uploadSinglePdf($this->operator, $this->supplier, $poUpload);
        $poAttachment = $po->attachments()->latest('id')->firstOrFail();
        $this->assertCompressedAttachmentValid($poAttachment, $rawLength);

        // 2. Single GR PDF upload compresses & syncs FileInspection + Attachment.
        $grUpload = UploadedFile::fake()->createWithContent('GR-COMP-001.pdf', $bloatedPdf);
        app(LocalGrDocumentService::class)->uploadSinglePdf($this->operator, $po, $grUpload);
        $grAttachment = $gr->attachments()->latest('id')->firstOrFail();
        $this->assertCompressedAttachmentValid($grAttachment, $rawLength);

        // 3. Local Invoice submission compresses the 6 PDF slots without altering HTTP contract.
        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_number' => 'INV-COMP-001',
            'invoice_date' => BusinessTime::today()->toDateString(),
            'invoice_amount' => '100000.00',
            'tax_amount' => '11000.00',
            'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.98765432',
        ], [
            'invoice' => UploadedFile::fake()->createWithContent('inv.pdf', $bloatedPdf),
            'tax_invoice' => UploadedFile::fake()->createWithContent('tax.pdf', $bloatedPdf),
            'purchase_order' => UploadedFile::fake()->createWithContent('po-ref.pdf', $bloatedPdf),
            'goods_receipt' => [UploadedFile::fake()->createWithContent('gr-ref.pdf', $bloatedPdf)],
            'delivery_note' => [UploadedFile::fake()->createWithContent('dn-ref.pdf', $bloatedPdf)],
            'supporting' => [UploadedFile::fake()->createWithContent('supp-ref.pdf', $bloatedPdf)],
        ]);

        foreach ($invoice->documents as $doc) {
            $this->assertLessThan($rawLength, (int) $doc->file_size);
            $inspection = FileInspection::findOrFail($doc->file_inspection_id);
            $storedBytes = Storage::disk('private')->get($doc->file_path);
            $this->assertSame(strlen($storedBytes), $inspection->file_size);
            $this->assertSame(hash('sha256', $storedBytes), $inspection->sha256);
            app(FileAccessGuard::class)->assertReadable($doc);
        }

        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'po_document_uploaded')->count());
        $this->assertSame(1, LocalFinanceAuditLog::where('action', 'gr_document_uploaded')->count());
    }

    public function test_zip_uploads_and_other_profiles_do_not_invoke_pdf_compression(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->operator, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-ZIP-UNTOUCHED',
            'po_date' => BusinessTime::today()->toDateString(),
            'total_amount' => '1000000.00',
        ]);
        $masters->createGoodsReceipt($this->operator, $po, [
            'gr_number' => 'GR-ZIP-UNTOUCHED',
            'gr_date' => BusinessTime::today()->toDateString(),
            'qty' => 2,
            'uom' => 'pcs',
        ]);

        $bloatedPdf = $this->makeBloatedImagePdf();
        $rawLength = strlen($bloatedPdf);

        // Other profiles keep compressPdf = false (default) cleanly.
        $vendorStored = app(FileInspectionService::class)->storeUpload(
            UploadedFile::fake()->createWithContent('vendor-doc.pdf', $bloatedPdf),
            'vendor',
            'vendor-docs/untouched.pdf',
        );
        $this->assertSame($rawLength, $vendorStored['file_size']);

        // GR ZIP batch uses storePath() without running upload-time PDF recompression.
        $zipPath = tempnam(sys_get_temp_dir(), 'gr-zip-');
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::OVERWRITE);
        $zip->addFromString('GR-ZIP-UNTOUCHED.pdf', $bloatedPdf);
        $zip->close();

        try {
            $zipUpload = new UploadedFile($zipPath, 'gr-batch.zip', 'application/zip', null, true);
            $result = app(LocalGrDocumentService::class)->uploadZip($this->operator, $po, $zipUpload);
            $this->assertSame(1, $result['count']);
            $gr = $po->goodsReceipts()->sole();
            $attachment = $gr->attachments()->sole();
            $inspection = FileInspection::findOrFail($attachment->file_inspection_id);
            $this->assertSame($rawLength, $inspection->file_size);
        } finally {
            @unlink($zipPath);
        }
    }

    public function test_encrypted_or_non_shrinkable_pdf_falls_back_to_original_bytes_on_upload(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->operator, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-FALLBACK-001',
            'po_date' => BusinessTime::today()->toDateString(),
            'total_amount' => '500000.00',
        ]);

        $original = NativeFileFixtures::pdf('Plain small document');
        $upload = UploadedFile::fake()->createWithContent('PO-FALLBACK-001.pdf', $original);
        app(LocalPoDocumentService::class)->uploadSinglePdf($this->operator, $this->supplier, $upload);

        $attachment = $po->attachments()->sole();
        $inspection = FileInspection::findOrFail($attachment->file_inspection_id);
        $this->assertSame(strlen($original), $inspection->file_size);
        $this->assertSame(hash('sha256', $original), $inspection->sha256);
    }

    public function test_compression_inspection_failure_falls_back_to_original_and_rollbacks_mark_inspections_error(): void
    {
        $bloated = $this->makeBloatedImagePdf();
        $upload = UploadedFile::fake()->createWithContent('valid-fallback.pdf', $bloated);
        $originalPath = $upload->getPathname();

        $inspectionService = \Mockery::mock(FileInspectionService::class, [app(NativeArchiveInspector::class)])->makePartial();
        $inspectionService->shouldReceive('inspectPath')
            ->once()
            ->withArgs(fn (string $path): bool => $path === $originalPath)
            ->passthru();
        $inspectionService->shouldReceive('inspectPath')
            ->once()
            ->withArgs(fn (string $path): bool => $path !== $originalPath)
            ->andThrow(ValidationException::withMessages(['file' => 'Forced re-inspection failure']));

        $stored = $inspectionService->storeUpload($upload, 'po_pdf', 'attachments/fallback-ok.pdf', compressPdf: true);

        $this->assertSame(strlen($bloated), $stored['file_size']);
        $this->assertSame($bloated, Storage::disk('private')->get('attachments/fallback-ok.pdf'));
        $inspection = FileInspection::findOrFail($stored['file_inspection_id']);
        $this->assertSame('VALIDATED', $inspection->status);

        // InvoiceDocumentService::compensate() marks discarded validated inspections as ERROR.
        $original = NativeFileFixtures::pdf('Valid rollback PDF');
        $compensateUpload = UploadedFile::fake()->createWithContent('rollback-doc.pdf', $original);
        $compStored = app(FileInspectionService::class)->storeUpload($compensateUpload, 'invoice', 'attachments/comp-fail.pdf', 'invoice', compressPdf: false);
        app(InvoiceDocumentService::class)->compensate(['attachments/comp-fail.pdf']);
        Storage::disk('private')->assertMissing('attachments/comp-fail.pdf');
        $compInspection = FileInspection::findOrFail($compStored['file_inspection_id']);
        $this->assertSame('ERROR', $compInspection->status);
        $this->assertSame('publication_failed', $compInspection->error_code);

        // LocalPoDocumentService transaction rollback deletes file AND updates FileInspection to ERROR.
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->operator, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-RB-001',
            'po_date' => BusinessTime::today()->toDateString(),
            'total_amount' => '100000.00',
        ]);
        Attachment::creating(function (): void {
            throw new \RuntimeException('Simulated PO attachment failure');
        });
        try {
            app(LocalPoDocumentService::class)->uploadSinglePdf(
                $this->operator,
                $this->supplier,
                UploadedFile::fake()->createWithContent('PO-RB-001.pdf', $original),
            );
            $this->fail('Expected simulated failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated PO attachment failure', $exception->getMessage());
        } finally {
            Attachment::flushEventListeners();
        }

        $rbInspection = FileInspection::where('profile', 'po_pdf')->latest('id')->firstOrFail();
        $this->assertSame('ERROR', $rbInspection->status);
        $this->assertSame('publication_failed', $rbInspection->error_code);
        Storage::disk('private')->assertMissing($rbInspection->file_path);
    }

    private function assertCompressedAttachmentValid(object $attachment, int $originalSize): void
    {
        $inspection = FileInspection::findOrFail($attachment->file_inspection_id);
        $storedBytes = Storage::disk('private')->get($attachment->file_path);
        $this->assertLessThan($originalSize, $inspection->file_size);
        $this->assertSame(strlen($storedBytes), $inspection->file_size);
        $this->assertSame(hash('sha256', $storedBytes), $inspection->sha256);
        app(FileAccessGuard::class)->assertReadable($attachment);
    }

    private function makeBloatedImagePdf(): string
    {
        $im = imagecreatetruecolor(2200, 2200);
        for ($x = 0; $x < 2200; $x++) {
            $color = imagecolorallocate($im, ($x * 37) % 256, ($x * 53) % 256, ($x * 71) % 256);
            imageline($im, $x, 0, $x, 2199, $color);
        }
        ob_start();
        imagejpeg($im, null, 95);
        $jpeg = ob_get_clean();
        unset($im);

        $content = 'q 100 0 0 100 0 0 cm /Im0 Do Q';
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /XObject << /Im0 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /XObject /Subtype /Image /Width 2200 /Height 2200 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($jpeg)." >>\nstream\n{$jpeg}\nendstream",
            5 => '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream",
        ];

        $out = "%PDF-1.5\n";
        $offsets = [0];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 6\n0000000000 65535 f \n";
        for ($id = 1; $id <= 5; $id++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }

        return $out."trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
