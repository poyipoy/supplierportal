<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\FileSecurity\FileInspectionService;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class LocalInvoicePdfOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $operator;

    private array $data;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->supplier = User::factory()->create();
        $this->supplier->supplierScopes()->create(['scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PDF Vendor',
            'category' => 'Barang', 'vendor_category' => 'Barang', 'is_pkp' => true, 'payment_term_days' => 30]);
        $this->operator = User::factory()->create(['role' => 'finance']);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->operator, ['supplier_id' => $this->supplier->id,
            'po_number' => 'PO-PDF-001', 'po_date' => BusinessTime::today()->toDateString(), 'total_amount' => '1000000.00']);
        $gr = $masters->createGoodsReceipt($this->operator, $po, ['gr_number' => 'GR-PDF-001',
            'gr_date' => BusinessTime::today()->toDateString(), 'qty' => 10, 'uom' => 'pcs']);
        $this->data = ['local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id],
            'invoice_number' => 'INV-001', 'invoice_date' => BusinessTime::today()->toDateString(),
            'invoice_amount' => '100000.00', 'tax_amount' => '11000.00', 'ppn_scheme' => '11%',
            'tax_invoice_number' => '010.000-26.12345678'];
    }

    private function files(bool $arrays = false): array
    {
        $files = [];
        foreach (['invoice' => 'inv', 'tax_invoice' => 'tax', 'delivery_note' => 'dn', 'supporting' => 'support'] as $type => $name) {
            $file = NativeFileFixtures::upload($name.'.pdf');
            $files[$type] = $arrays ? [$file] : $file;
        }

        return $files;
    }

    private function revision(): LocalInvoice
    {
        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data, $this->files());
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice,
            scannedReceiptQr: URL::signedRoute('receipts.verify-supplier', ['receipt' => $invoice->receipt->receipt_number, 'revision' => 1]));
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Revise supporting documents', $this->operator);

        return $invoice->refresh();
    }

    public static function rejectedUploads(): array
    {
        $cases = [];
        foreach (['invoice', 'tax_invoice', 'delivery_note', 'supporting', 'surat_jalan'] as $type) {
            foreach (['jpg', 'jpeg', 'png', 'pdf'] as $extension) {
                foreach ([false, true] as $revision) {
                    $cases[$type.' '.$extension.' '.($revision ? 'resubmit' : 'submit')] = [$type, $extension, $revision];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('rejectedUploads')]
    public function test_http_rejects_images_and_images_renamed_pdf(string $type, string $extension, bool $revision): void
    {
        $invoice = $revision ? $this->revision() : null;
        $before = Storage::disk('private')->allFiles();
        $files = $this->files();
        if ($type === 'surat_jalan') {
            unset($files['delivery_note']);
        }
        $filename = $type === 'invoice' ? 'INV-001' : 'scan';
        // Real image bytes prove MIME rejection instead of an empty-upload failure.
        $bad = $extension === 'pdf'
            ? UploadedFile::fake()->createWithContent($filename.'.pdf', NativeFileFixtures::png())
            : UploadedFile::fake()->image($filename.'.'.$extension);
        $files[$type] = in_array($extension, ['png', 'pdf'], true) ? [$bad] : $bad;
        $normalizedType = $type === 'surat_jalan' ? 'delivery_note' : $type;
        $field = $extension === 'pdf' ? $normalizedType : (is_array($files[$type]) ? $normalizedType.'.0' : $normalizedType);
        $route = $revision ? route('local-supplier.invoices.resubmit', $invoice) : route('local-supplier.invoices.store');
        $this->actingAs($this->supplier)->postJson($route, array_merge($this->data, $files))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($before, Storage::disk('private')->allFiles());
        $this->assertSame($revision ? 1 : 0, LocalInvoice::count());
        if ($invoice) {
            $this->assertSame(1, $invoice->fresh()->revision_number);
            $this->assertSame(LocalInvoice::STATUS_NEED_REVISION, $invoice->fresh()->status);
            $this->assertSame(1, $invoice->revisions()->count());
        }
    }

    public static function fileShapes(): array
    {
        return ['single' => [false], 'array' => [true]];
    }

    #[DataProvider('fileShapes')]
    public function test_pdf_is_accepted_in_all_categories_on_submit_and_resubmit(bool $arrays): void
    {
        $files = $this->files($arrays);
        $uppercase = NativeFileFixtures::upload('support.PDF');
        $files['supporting'] = $arrays ? [$uppercase] : $uppercase;
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), array_merge($this->data, $files))
            ->assertOk()->assertJsonStructure(['redirect']);
        $invoice = LocalInvoice::sole();
        $this->assertSame(4, $invoice->documents()->count());
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice,
            scannedReceiptQr: URL::signedRoute('receipts.verify-supplier', ['receipt' => $invoice->receipt->receipt_number, 'revision' => 1]));
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Revise documents', $this->operator);
        $this->postJson(route('local-supplier.invoices.resubmit', $invoice), array_merge($this->data, $this->files($arrays)))
            ->assertOk()->assertJsonStructure(['redirect']);
        $this->assertSame(2, $invoice->fresh()->revision_number);
        $this->assertSame(4, $invoice->fresh()->latestRevision->documents()->count());
    }

    #[DataProvider('fileShapes')]
    public function test_http_accepts_pdf_for_surat_jalan_alias_on_submit_and_resubmit(bool $arrays): void
    {
        $files = $this->files($arrays);
        $files['surat_jalan'] = $files['delivery_note'];
        unset($files['delivery_note']);
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), array_merge($this->data, $files))->assertOk();
        $invoice = LocalInvoice::sole();
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice,
            scannedReceiptQr: URL::signedRoute('receipts.verify-supplier', ['receipt' => $invoice->receipt->receipt_number, 'revision' => 1]));
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Revise documents', $this->operator);
        $this->postJson(route('local-supplier.invoices.resubmit', $invoice), array_merge($this->data, $files))->assertOk();
        $this->assertSame(1, $invoice->fresh()->latestRevision->documents()->where('document_type', 'delivery_note')->count());
    }

    #[DataProvider('fileShapes')]
    public function test_service_rejection_rolls_back_prior_file_writes_on_submit_and_resubmit(bool $arrays): void
    {
        foreach ([false, true] as $revision) {
            $invoice = $revision ? $this->revision() : null;
            $before = Storage::disk('private')->allFiles();
            $files = $this->files($arrays);
            $bad = UploadedFile::fake()->image('support.png');
            $files['supporting'] = $arrays ? [$bad] : $bad;
            try {
                $service = app(InvoiceSubmissionService::class);
                $invoice ? $service->resubmit($this->supplier, $invoice, $this->data, $files)
                    : $service->submit($this->supplier, $this->data, $files);
                $this->fail('Non-PDF upload was accepted by the service.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('supporting', $exception->errors());
            }
            $this->assertSame($before, Storage::disk('private')->allFiles());
            $this->assertSame($revision ? 1 : 0, LocalInvoice::count());
            if ($invoice) {
                $this->assertSame(1, $invoice->revisions()->count());
            }
        }
    }

    public function test_service_accepts_pdf_and_rejects_image_for_surat_jalan_alias(): void
    {
        $files = $this->files();
        unset($files['delivery_note']);
        $files['surat_jalan'] = UploadedFile::fake()->image('dn.jpg');
        try {
            app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data, $files);
            $this->fail('The alias accepted an image.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('delivery_note', $exception->errors());
        }
        $this->assertSame([], Storage::disk('private')->allFiles());
        $files['surat_jalan'] = NativeFileFixtures::upload('dn.pdf');
        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data, $files);
        $this->assertSame(1, $invoice->documents()->where('document_type', 'delivery_note')->count());
    }

    #[DataProvider('fileShapes')]
    public function test_resubmit_retains_readable_old_image_with_inspection_or_historical_cutover(bool $historical): void
    {
        $invoice = $this->revision();
        $doc = $invoice->documents()->where('document_type', 'invoice')->firstOrFail();
        // Reconstruct a previously accepted image without reopening new-upload rules.
        $stored = app(FileInspectionService::class)->storeUpload(UploadedFile::fake()->image('old.jpg'), 'vendor', 'old/invoice.jpg');
        $doc->update(['file_path' => $stored['file_path'], 'original_filename' => 'old.jpg',
            'mime_type' => $stored['file_type'], 'file_size' => $stored['file_size'],
            'file_inspection_id' => $historical ? null : $stored['file_inspection_id']]);
        if ($historical) {
            DB::table('file_security_cutovers')->updateOrInsert(['document_table' => 'local_invoice_documents'], ['legacy_max_id' => $doc->id]);
        } else {
            DB::table('file_inspections')->where('id', $stored['file_inspection_id'])->update(['profile' => 'invoice']);
        }
        $files = $this->files();
        unset($files['invoice']);
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice),
            array_merge($this->data, $files, ['kept_document_ids' => [$doc->id]]))->assertOk();
        $retained = $invoice->fresh()->latestRevision->documents()->where('document_type', 'invoice')->sole();
        $this->assertSame('old.jpg', $retained->original_filename);
        $this->assertSame($doc->file_path, $retained->file_path);
        $this->get(route('local-invoice-documents.show', $retained))->assertOk();
        $other = User::factory()->create();
        $other->supplierScopes()->create(['scope' => 'local']);
        $this->actingAs($other)->get(route('local-invoice-documents.show', $retained))->assertForbidden();
    }

    public function test_retained_and_new_files_share_the_five_file_limit(): void
    {
        $invoice = $this->revision();
        $ids = $invoice->documents()->pluck('id')->all();
        $files = ['supporting' => array_map(fn ($i) => NativeFileFixtures::upload('support-'.$i.'.pdf'), range(1, 5))];
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice),
            array_merge($this->data, $files, ['kept_document_ids' => $ids]))
            ->assertUnprocessable()->assertJsonValidationErrors('kept_document_ids');
        $this->assertSame(1, $invoice->revisions()->count());
        array_pop($files['supporting']);
        $this->postJson(route('local-supplier.invoices.resubmit', $invoice),
            array_merge($this->data, $files, ['kept_document_ids' => $ids]))->assertOk();
        $this->assertSame(5, $invoice->fresh()->latestRevision->documents()->where('document_type', 'supporting')->count());
    }

    public function test_resubmit_cannot_retain_a_document_from_another_invoice(): void
    {
        $invoice = $this->revision();
        $gr = app(LocalProcurementMasterService::class)->createGoodsReceipt($this->operator, $invoice->localPurchaseOrder,
            ['gr_number' => 'GR-OTHER', 'gr_date' => BusinessTime::today()->toDateString(), 'qty' => 1, 'uom' => 'pcs']);
        $other = app(InvoiceSubmissionService::class)->submit($this->supplier,
            array_merge($this->data, ['invoice_number' => 'INV-002', 'tax_invoice_number' => '010.000-26.12345679', 'goods_receipt_ids' => [$gr->id]]), $this->files());
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice),
            array_merge($this->data, $this->files(), ['kept_document_ids' => [$other->documents()->firstOrFail()->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('kept_document_ids');
        $this->assertSame(1, $invoice->revisions()->count());
    }

    public function test_native_invoice_profile_rejects_image_extensions_without_affecting_vendor_profile(): void
    {
        foreach (['jpg', 'jpeg', 'png'] as $extension) {
            $file = UploadedFile::fake()->image('image.'.$extension);
            try {
                app(FileInspectionService::class)->inspectUpload($file, 'invoice', 'supporting');
                $this->fail('The invoice profile accepted an image.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('supporting', $exception->errors());
            }
            $this->assertSame('VALIDATED', app(FileInspectionService::class)->inspectUpload($file, 'vendor')->status);
        }
    }

    public function test_pdf_size_limit_and_native_content_inspection(): void
    {
        foreach ([NativeFileFixtures::upload('support.pdf', 5121), UploadedFile::fake()->createWithContent('support.pdf', "%PDF-1.4\nmalformed document")] as $bad) {
            $files = $this->files();
            $files['supporting'] = $bad;
            $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), array_merge($this->data, $files))
                ->assertUnprocessable()->assertJsonValidationErrors('supporting');
            $this->assertSame(0, LocalInvoice::count());
            $this->assertSame([], Storage::disk('private')->allFiles());
        }
        $files['supporting'] = NativeFileFixtures::upload('support.pdf', 5120);
        $this->postJson(route('local-supplier.invoices.store'), array_merge($this->data, $files))->assertOk();
    }

    public function test_form_exposes_pdf_only_hints_and_accessible_controls_in_both_languages(): void
    {
        foreach (['en', 'id'] as $locale) {
            $this->supplier->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale]);
            $response = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.create'))->assertOk();
            $response->assertSee(__('common.final_review.private_uploads', [], $locale));
            $this->assertSame(4, substr_count($response->getContent(), 'accept=".pdf"'));
            $response->assertDontSee('accept=".pdf,.jpg,.jpeg,.png"', false);
            foreach (['invoice', 'tax_invoice', 'delivery_note', 'supporting'] as $type) {
                $response->assertSee('id="file_'.$type.'-format"', false)
                    ->assertSee('id="file_'.$type.'-error"', false)
                    ->assertSee('aria-describedby="file_'.$type.'-help file_'.$type.'-format file_'.$type.'-error"', false);
            }
            $response->assertSee('@keydown.space.prevent=', false)->assertSee(':aria-invalid=', false);
        }
    }
}
