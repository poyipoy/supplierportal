<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\User;
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

class LocalInvoiceRequiredDocumentsTest extends TestCase
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
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'Document Vendor',
            'category' => 'Jasa', 'vendor_category' => 'Jasa', 'is_pkp' => false, 'payment_term_days' => 30]);
        $operator = $this->operator = User::factory()->create(['role' => 'finance']);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($operator, ['supplier_id' => $this->supplier->id,
            'po_number' => 'PO-REQUIRED', 'po_date' => BusinessTime::today()->toDateString(), 'total_amount' => '1000.00']);
        $gr = $masters->createGoodsReceipt($operator, $po, ['gr_number' => 'GR-REQUIRED',
            'gr_date' => BusinessTime::today()->toDateString(), 'qty' => '1', 'uom' => 'pcs']);
        $this->data = ['local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id],
            'invoice_number' => 'INV-REQUIRED', 'invoice_date' => BusinessTime::today()->toDateString(),
            'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%'];
    }

    private function files(): array
    {
        return ['invoice' => NativeFileFixtures::upload('INV-REQUIRED.pdf'), ...NativeFileFixtures::invoiceReferences()];
    }

    private function revision(): LocalInvoice
    {
        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data, $this->files());
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->operator, $invoice,
            scannedReceiptQr: URL::signedRoute('receipts.verify-supplier', ['receipt' => $invoice->receipt->receipt_number, 'revision' => 1]));
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Complete the reference documents', $this->operator);

        return $invoice->refresh();
    }

    public static function requiredCases(): array
    {
        return ['PO submit' => ['purchase_order', false], 'GR submit' => ['goods_receipt', false],
            'PO revision' => ['purchase_order', true], 'GR revision' => ['goods_receipt', true]];
    }

    #[DataProvider('requiredCases')]
    public function test_required_documents_cannot_be_omitted_on_http_or_service(string $type, bool $revision): void
    {
        $invoice = $revision ? $this->revision() : null;
        $files = $this->files();
        unset($files[$type]);
        $before = Storage::disk('private')->allFiles();
        $route = $invoice ? route('local-supplier.invoices.resubmit', $invoice) : route('local-supplier.invoices.store');
        $this->actingAs($this->supplier)->postJson($route, [...$this->data, ...$files])
            ->assertUnprocessable()->assertJsonValidationErrors($type);
        try {
            $service = app(InvoiceSubmissionService::class);
            $invoice ? $service->resubmit($this->supplier, $invoice, $this->data, $files)
                : $service->submit($this->supplier, $this->data, $files);
            $this->fail('A required document was omitted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($type, $exception->errors());
        }
        $this->assertSame($before, Storage::disk('private')->allFiles());
        $this->assertSame($revision ? 1 : 0, LocalInvoice::count());
        $this->assertSame($revision ? 1 : 0, DB::table('local_invoice_revisions')->count());
    }

    public static function badFiles(): array
    {
        $cases = [];
        foreach (['purchase_order', 'goods_receipt'] as $type) {
            foreach (['image', 'extension', 'empty', 'malformed', 'oversized', 'invalid_entry'] as $kind) {
                $cases[$type.' '.$kind] = [$type, $kind];
            }
        }

        return $cases;
    }

    #[DataProvider('badFiles')]
    public function test_bad_reference_documents_are_rejected_without_partial_submission(string $type, string $kind): void
    {
        $bad = match ($kind) {
            'image' => UploadedFile::fake()->createWithContent('reference.pdf', NativeFileFixtures::png()),
            'extension' => UploadedFile::fake()->createWithContent('reference.txt', NativeFileFixtures::pdf()),
            'empty' => UploadedFile::fake()->createWithContent('reference.pdf', ''),
            'malformed' => UploadedFile::fake()->createWithContent('reference.pdf', "%PDF-1.4\ninvalid"),
            'oversized' => NativeFileFixtures::upload('reference.pdf', 5121),
            default => 'not-an-upload',
        };
        $files = $this->files();
        $files[$type] = $type === 'goods_receipt' ? [$bad] : $bad;
        $response = $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), [...$this->data, ...$files]);
        $response->assertUnprocessable();
        $this->assertNotEmpty(array_filter(array_keys($response->json('errors')), fn ($key) => $key === $type || str_starts_with($key, $type.'.')));
        try {
            app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data, $files);
            $this->fail('An invalid document was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($type, $exception->errors());
        }
        $this->assertSame(0, LocalInvoice::count());
        $this->assertSame(0, DB::table('file_inspections')->count());
        $this->assertSame([], Storage::disk('private')->allFiles());
    }

    public static function grCounts(): array
    {
        return [[1, true], [5, true], [6, false]];
    }

    #[DataProvider('grCounts')]
    public function test_gr_limit_counts_files_instead_of_gr_numbers(int $count, bool $accepted): void
    {
        $files = $this->files();
        $files['goods_receipt'] = array_map(fn ($i) => NativeFileFixtures::upload('GR-'.$i.'.PDF'), range(1, $count));
        $response = $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), [...$this->data, ...$files]);
        if (! $accepted) {
            $response->assertUnprocessable()->assertJsonValidationErrors('goods_receipt');
            $this->assertSame(0, LocalInvoice::count());

            return;
        }
        $response->assertOk();
        $invoice = LocalInvoice::sole();
        $this->assertSame($count, $invoice->documents()->where('document_type', 'goods_receipt')->count());
        $this->assertSame(1, $invoice->goodsReceiptHistories()->count());
    }

    public function test_two_po_files_are_rejected_by_http_and_service(): void
    {
        $files = $this->files();
        $files['purchase_order'] = [NativeFileFixtures::upload('PO-1.pdf'), NativeFileFixtures::upload('PO-2.pdf')];
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), [...$this->data, ...$files])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order');
        $this->expectException(ValidationException::class);
        app(InvoiceSubmissionService::class)->submit($this->supplier, $this->data, $files);
    }

    public function test_po_and_gr_accept_the_exact_five_mib_boundary(): void
    {
        $files = $this->files();
        $files['purchase_order'] = NativeFileFixtures::upload('PO.PDF', 5120);
        $files['goods_receipt'] = [NativeFileFixtures::upload('GR.PDF', 5120)];
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.store'), [...$this->data, ...$files])->assertOk();
        foreach (LocalInvoice::sole()->documents->whereIn('document_type', ['purchase_order', 'goods_receipt']) as $document) {
            $this->assertSame(5 * 1024 * 1024, (int) $document->file_size);
        }
    }

    public function test_revision_reuses_documents_and_replaces_only_the_current_po(): void
    {
        $invoice = $this->revision();
        $oldPo = $invoice->latestRevision->documents->firstWhere('document_type', 'purchase_order');
        $oldGr = $invoice->latestRevision->documents->firstWhere('document_type', 'goods_receipt');
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice), [
            ...$this->data, 'kept_document_ids' => $invoice->documents->modelKeys(),
            'purchase_order' => NativeFileFixtures::upload('replacement.PDF'),
            'goods_receipt' => [NativeFileFixtures::upload('additional.pdf')],
        ])->assertOk();
        $current = $invoice->fresh()->latestRevision->documents;
        $this->assertCount(1, $current->where('document_type', 'purchase_order'));
        $this->assertCount(2, $current->where('document_type', 'goods_receipt'));
        $this->assertSame('replacement.PDF', $current->firstWhere('document_type', 'purchase_order')->original_filename);
        $this->assertSame($oldGr->file_path, $current->firstWhere('document_type', 'goods_receipt')->file_path);
        Storage::disk('private')->assertExists($oldPo->file_path);
        $this->assertSame(3, $invoice->revisions()->where('revision_number', 1)->firstOrFail()->documents()->count());
    }

    public function test_retained_and_new_gr_documents_share_one_limit(): void
    {
        $invoice = $this->revision();
        $ids = $invoice->documents->modelKeys();
        $files = array_map(fn ($i) => NativeFileFixtures::upload('additional-'.$i.'.pdf'), range(1, 5));
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice), [
            ...$this->data, 'kept_document_ids' => $ids, 'goods_receipt' => $files,
        ])->assertUnprocessable()->assertJsonValidationErrors('kept_document_ids');
        array_pop($files);
        $this->postJson(route('local-supplier.invoices.resubmit', $invoice), [
            ...$this->data, 'kept_document_ids' => $ids, 'goods_receipt' => $files,
        ])->assertOk();
        $this->assertSame(5, $invoice->fresh()->latestRevision->documents()->where('document_type', 'goods_receipt')->count());
    }

    public function test_legacy_invoice_revision_requires_adding_missing_po_and_gr(): void
    {
        $invoice = $this->revision();
        $invoice->documents()->whereIn('document_type', ['purchase_order', 'goods_receipt'])->delete();
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice), [
            ...$this->data, 'kept_document_ids' => $invoice->documents()->pluck('id')->all(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['purchase_order', 'goods_receipt']);
        $this->postJson(route('local-supplier.invoices.resubmit', $invoice), [...$this->data, ...$this->files()])->assertOk();
    }

    public function test_native_inspection_and_download_access_apply_to_both_categories(): void
    {
        $invoice = $this->revision();
        foreach ($invoice->documents->whereIn('document_type', ['purchase_order', 'goods_receipt']) as $document) {
            $inspection = DB::table('file_inspections')->find($document->file_inspection_id);
            $this->assertSame('invoice', $inspection->profile);
            $this->assertSame('VALIDATED', $inspection->status);
            $this->assertSame(hash('sha256', Storage::disk('private')->get($document->file_path)), $inspection->sha256);
            $this->actingAs($this->supplier)->get(route('local-invoice-documents.show', $document))
                ->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $other = User::factory()->create();
            $other->supplierScopes()->create(['scope' => 'local']);
            $this->actingAs($other)->get(route('local-invoice-documents.show', $document))->assertForbidden();
            Storage::disk('private')->put($document->file_path, 'tampered');
            $this->actingAs($this->supplier)->get(route('local-invoice-documents.show', $document))->assertNotFound();
        }
    }

    public function test_enum_rollback_refuses_to_remove_existing_reference_documents(): void
    {
        $this->revision();
        $migration = require database_path('migrations/2026_10_15_000001_add_po_and_gr_to_local_invoice_documents.php');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot remove PO and GR document types');
        $migration->down();
    }

    public function test_failed_gr_replacement_rolls_back_new_files_and_keeps_old_revision(): void
    {
        $invoice = $this->revision();
        $paths = Storage::disk('private')->allFiles();
        $files = $this->files();
        $files['goods_receipt'] = [UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.4\ninvalid")];
        try {
            app(InvoiceSubmissionService::class)->resubmit($this->supplier, $invoice, $this->data, $files);
            $this->fail('An invalid GR replacement was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('goods_receipt', $exception->errors());
        }
        $this->assertSame($paths, Storage::disk('private')->allFiles());
        $this->assertSame(LocalInvoice::STATUS_NEED_REVISION, $invoice->fresh()->status);
        $this->assertSame(1, $invoice->revisions()->count());
        $this->assertSame(3, $invoice->documents()->count());
    }

    public function test_documents_from_another_invoice_cannot_be_retained_even_for_the_same_supplier(): void
    {
        $invoice = $this->revision();
        $otherGr = app(LocalProcurementMasterService::class)->createGoodsReceipt($this->operator, $invoice->localPurchaseOrder,
            ['gr_number' => 'GR-OTHER', 'gr_date' => BusinessTime::today()->toDateString(), 'qty' => '1', 'uom' => 'pcs']);
        $files = $this->files();
        $files['invoice'] = NativeFileFixtures::upload('INV-OTHER.pdf');
        $other = app(InvoiceSubmissionService::class)->submit($this->supplier,
            [...$this->data, 'invoice_number' => 'INV-OTHER', 'goods_receipt_ids' => [$otherGr->id]], $files);
        foreach (['purchase_order', 'goods_receipt'] as $type) {
            $data = [...$this->data, 'kept_document_ids' => [...$invoice->documents->modelKeys(), $other->documents->firstWhere('document_type', $type)->id]];
            $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice), $data)
                ->assertUnprocessable()->assertJsonValidationErrors('kept_document_ids');
            try {
                app(InvoiceSubmissionService::class)->resubmit($this->supplier, $invoice, $data, []);
                $this->fail('A document from another invoice was retained.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('kept_document_ids', $exception->errors());
            }
        }
        $this->assertSame(1, $invoice->revisions()->count());
    }

    public function test_reference_documents_are_visible_to_existing_authorized_roles_in_both_locales(): void
    {
        $invoice = $this->revision();
        $routes = ['supplier' => 'local-supplier.invoices.show', 'finance' => 'finance.invoices.show',
            'admin' => 'finance.invoices.show', 'purchasing' => 'purchasing.local-invoices.show', 'accounting' => 'accounting.invoices.show'];
        foreach ($routes as $role => $route) {
            $user = $role === 'supplier' ? $this->supplier : User::factory()->create(['role' => $role]);
            foreach (['en', 'id'] as $locale) {
                $user->preference()->updateOrCreate([], [...config('user_preferences.defaults'), 'locale' => $locale]);
                $this->actingAs($user)->get(route($route, $invoice))->assertOk()
                    ->assertSee(__('local_invoice.labels.purchase_order', [], $locale))
                    ->assertSee(__('local_invoice.labels.goods_receipt', [], $locale))
                    ->assertSee('purchase-order.pdf')->assertSee('goods-receipt.pdf');
            }
        }
        $this->assertSame(0, $invoice->localPurchaseOrder->attachments()->count());
    }

    public function test_malformed_retention_ids_fail_validation_before_queries(): void
    {
        $invoice = $this->revision();
        $data = [...$this->data, 'kept_document_ids' => [['nested' => $invoice->documents->first()->id]]];
        $this->actingAs($this->supplier)->postJson(route('local-supplier.invoices.resubmit', $invoice), $data)
            ->assertUnprocessable()->assertJsonValidationErrors('kept_document_ids.0');
        $this->expectException(ValidationException::class);
        app(InvoiceSubmissionService::class)->resubmit($this->supplier, $invoice, $data, []);
    }
}
