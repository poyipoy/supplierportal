<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\Attachment;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class LocalPurchaseOrderDocumentTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierA;

    private User $supplierB;

    private User $finance;

    private User $purchasing;

    private User $qc;

    private LocalPurchaseOrder $poA1;

    private LocalPurchaseOrder $poA2;

    private LocalPurchaseOrder $poB1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->supplierA = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplierA->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplierA->id, 'company_name' => 'PT Supplier Alpha', 'category' => 'Parts', 'is_pkp' => true, 'payment_term_days' => 30]);

        $this->supplierB = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplierB->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplierB->id, 'company_name' => 'PT Supplier Beta', 'category' => 'Raw Material', 'is_pkp' => false, 'payment_term_days' => 45]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        $masters = app(LocalProcurementMasterService::class);
        $this->poA1 = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-ALPHA-001',
            'po_date' => '2026-09-10',
            'total_amount' => '1000.00',
        ]);
        $this->poA2 = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplierA->id,
            'po_number' => 'PO-ALPHA-002',
            'po_date' => '2026-09-11',
            'total_amount' => '2000.00',
        ]);
        $this->poB1 = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplierB->id,
            'po_number' => 'PO-BETA-001',
            'po_date' => '2026-09-12',
            'total_amount' => '3000.00',
        ]);

        $masters->createGoodsReceipt($this->finance, $this->poA1, [
            'gr_number' => 'GR-A-001',
            'gr_date' => '2026-09-11',
            'qty' => '5.0000',
            'description' => 'First batch delivery',
        ]);
    }

    public function test_finance_and_purchasing_can_upload_single_po_pdf(): void
    {
        $file = UploadedFile::fake()->createWithContent('PO-ALPHA-001.pdf', "%PDF-1.4\nPO Content Alpha 1");

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'attachable_id' => $this->poA1->id,
            'file_name' => 'PO-ALPHA-001.pdf',
        ]);

        $this->assertDatabaseHas('local_finance_audit_logs', [
            'auditable_type' => LocalPurchaseOrder::class,
            'auditable_id' => $this->poA1->id,
            'action' => 'po_document_uploaded',
            'actor_id' => $this->finance->id,
        ]);

        // Purchasing can also upload
        $file2 = UploadedFile::fake()->createWithContent('PO-ALPHA-002.pdf', "%PDF-1.4\nPO Content Alpha 2");
        $purchasingResponse = $this->actingAs($this->purchasing)->post(route('purchasing.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file2,
        ]);
        $purchasingResponse->assertSessionHasNoErrors();
        $this->assertDatabaseHas('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'attachable_id' => $this->poA2->id,
            'file_name' => 'PO-ALPHA-002.pdf',
        ]);
    }

    public function test_unauthorized_user_cannot_upload_po_document(): void
    {
        $file = UploadedFile::fake()->createWithContent('PO-ALPHA-001.pdf', "%PDF-1.4\nPO Content");

        $response = $this->actingAs($this->supplierA)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file,
        ]);
        $response->assertStatus(403);

        $qcResponse = $this->actingAs($this->qc)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file,
        ]);
        $qcResponse->assertStatus(403);
    }

    public function test_unmatched_po_filename_is_rejected(): void
    {
        $file = UploadedFile::fake()->createWithContent('PO-NON-EXISTENT.pdf', "%PDF-1.4\nContent");

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file,
        ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertDatabaseMissing('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'file_name' => 'PO-NON-EXISTENT.pdf',
        ]);
    }

    public function test_cross_supplier_upload_is_rejected(): void
    {
        // Try uploading Beta's PO under Alpha's supplier selection
        $file = UploadedFile::fake()->createWithContent('PO-BETA-001.pdf', "%PDF-1.4\nContent");

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file,
        ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertDatabaseMissing('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'file_name' => 'PO-BETA-001.pdf',
        ]);
    }

    public function test_valid_zip_with_multiple_pos_is_extracted_and_attached_atomically(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'po_zip').'.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('PO-ALPHA-001.pdf', "%PDF-1.4\nPO 1");
        $zip->addFromString('PO-ALPHA-002.pdf', "%PDF-1.4\nPO 2");
        $zip->close();

        $zipFile = new UploadedFile($zipPath, 'batch_pos.zip', 'application/zip', null, true);

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $zipFile,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'attachable_id' => $this->poA1->id,
            'file_name' => 'PO-ALPHA-001.pdf',
        ]);

        $this->assertDatabaseHas('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'attachable_id' => $this->poA2->id,
            'file_name' => 'PO-ALPHA-002.pdf',
        ]);

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
    }

    public function test_zip_with_unmatched_po_fails_entire_batch_atomically(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'po_zip_fail').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('PO-ALPHA-001.pdf', "%PDF-1.4\nPO 1");
        $zip->addFromString('PO-UNKNOWN-999.pdf', "%PDF-1.4\nPO 999");
        $zip->close();

        $zipFile = new UploadedFile($zipPath, 'invalid_batch.zip', 'application/zip', null, true);

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $zipFile,
        ]);

        $response->assertSessionHasErrors(['file']);

        // Assert atomicity: PO-ALPHA-001 must NOT be attached because batch failed
        $this->assertDatabaseMissing('attachments', [
            'attachable_type' => LocalPurchaseOrder::class,
            'attachable_id' => $this->poA1->id,
        ]);

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
    }

    public function test_zip_with_path_traversal_is_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'po_zip_traversal').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('../traversal.pdf', "%PDF-1.4\nExploit");
        $zip->close();

        $zipFile = new UploadedFile($zipPath, 'traversal.zip', 'application/zip', null, true);

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $zipFile,
        ]);

        $response->assertSessionHasErrors(['file']);
        $this->assertDatabaseMissing('attachments', [
            'file_name' => 'traversal.pdf',
        ]);

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
    }

    public function test_zip_with_nested_archive_is_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'po_zip_nested').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('inner.zip', 'dummy zip content');
        $zip->close();

        $zipFile = new UploadedFile($zipPath, 'nested.zip', 'application/zip', null, true);

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $zipFile,
        ]);

        $response->assertSessionHasErrors(['file']);

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
    }

    public function test_zip_with_non_pdf_file_is_rejected(): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'po_zip_exe').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('script.sh', 'echo hello');
        $zip->close();

        $zipFile = new UploadedFile($zipPath, 'non_pdf.zip', 'application/zip', null, true);

        $response = $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $zipFile,
        ]);

        $response->assertSessionHasErrors(['file']);

        if (file_exists($zipPath)) {
            @unlink($zipPath);
        }
    }

    public function test_supplier_can_view_own_po_and_download_document(): void
    {
        // 1. Attach document to PO A1
        $file = UploadedFile::fake()->createWithContent('PO-ALPHA-001.pdf', "%PDF-1.4\nPO Document Content Alpha");
        $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierA->id,
            'file' => $file,
        ]);

        $attachment = Attachment::where('attachable_type', LocalPurchaseOrder::class)
            ->where('attachable_id', $this->poA1->id)
            ->firstOrFail();

        // 2. Supplier A visits PO index
        $indexResponse = $this->actingAs($this->supplierA)->get(route('local-supplier.purchase-orders.index'));
        $indexResponse->assertStatus(200);
        $indexResponse->assertSee('PO-ALPHA-001');
        $indexResponse->assertSee('PO-ALPHA-002');
        $indexResponse->assertDontSee('PO-BETA-001');

        // 3. Supplier A visits PO show
        $showResponse = $this->actingAs($this->supplierA)->get(route('local-supplier.purchase-orders.show', $this->poA1));
        $showResponse->assertStatus(200);
        $showResponse->assertSee('PO-ALPHA-001');
        $showResponse->assertSee('GR-A-001');
        $showResponse->assertSee('First batch delivery');

        // 4. Supplier A downloads attachment
        $downloadResponse = $this->actingAs($this->supplierA)->get(route('attachments.show', $attachment->id));
        $downloadResponse->assertStatus(200);
    }

    public function test_supplier_isolation_prevents_viewing_and_downloading_another_supplier_po(): void
    {
        // 1. Attach document to Beta's PO
        $file = UploadedFile::fake()->createWithContent('PO-BETA-001.pdf', "%PDF-1.4\nSecret Beta PO Content");
        $this->actingAs($this->finance)->post(route('finance.local-procurement.upload-po'), [
            'supplier_id' => $this->supplierB->id,
            'file' => $file,
        ]);

        $betaAttachment = Attachment::where('attachable_type', LocalPurchaseOrder::class)
            ->where('attachable_id', $this->poB1->id)
            ->firstOrFail();

        // 2. Supplier A attempts to view Beta's PO show page
        $showResponse = $this->actingAs($this->supplierA)->get(route('local-supplier.purchase-orders.show', $this->poB1));
        $showResponse->assertStatus(403);

        // 3. Supplier A attempts to download Beta's PO attachment
        $downloadResponse = $this->actingAs($this->supplierA)->get(route('attachments.show', $betaAttachment->id));
        $downloadResponse->assertStatus(403);

        // 4. Supplier A index page must never contain Beta's PO
        $indexResponse = $this->actingAs($this->supplierA)->get(route('local-supplier.purchase-orders.index'));
        $indexResponse->assertDontSee('PO-BETA-001');
    }
}
