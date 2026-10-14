<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class InvoiceFilenameAutoFillTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();

        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT AutoFill Vendor',
            'address' => 'Jakarta',
            'phone' => '08123456789',
            'npwp' => '123456789012345',
            'category' => 'Jasa',
            'vendor_category' => 'Jasa',
            'payment_term_days' => 30,
            'is_pkp' => true,
        ]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $masters = app(LocalProcurementMasterService::class);
        $this->po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-AUTO-001',
            'po_date' => '2026-09-20',
            'total_amount' => '1000000.00',
        ]);
        $this->gr = $masters->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-AUTO-001',
            'gr_date' => '2026-09-21',
            'uom' => 'pcs', 'qty' => '10.0',
        ]);
    }

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'local_purchase_order_id' => $this->po->id,
            'goods_receipt_ids' => [$this->gr->id],
            'invoice_date' => '2026-09-24',
            'invoice_amount' => '100000.00',
            'ppn_scheme' => '11%',
            'tax_amount' => '11000.00',
        ], $overrides);
    }

    public static function changedTaxNumbers(): array
    {
        $cases = [];
        foreach (['en', 'id'] as $locale) {
            foreach ([['12.34.56.78765432875', '12.34.56.78765432876', '01.00.26.00000000099'],
                ['010.000-26.12345678', '010.000-26.12345679', '010.000-26.00000099']] as $numbers) {
                foreach ([false, true] as $revision) {
                    foreach ([false, true] as $spoofOriginal) {
                        $cases[] = [$locale, ...$numbers, $revision, $spoofOriginal];
                    }
                }
            }
        }

        return $cases;
    }

    #[DataProvider('changedTaxNumbers')]
    public function test_edited_tax_number_reports_filename_mismatch_instead_of_an_unrelated_duplicate(string $locale, string $used, string $entered, string $current, bool $revision, bool $spoofOriginal): void
    {
        $this->supplier->preference()->create([...config('user_preferences.defaults'), 'locale' => $locale]);
        app()->setLocale($locale);
        LocalInvoice::create(['supplier_id' => $this->supplier->id, 'submission_number' => 'TAKEN-TAX-SUB', 'invoice_number' => 'INV-TAKEN',
            'invoice_date' => '2026-09-20', 'invoice_amount' => '100.00', 'tax_amount' => '11.00', 'tax_invoice_number' => $used,
            'po_number' => $this->po->po_number, 'po_source' => 'INTERNAL', 'local_purchase_order_id' => $this->po->id,
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, 'revision_number' => 1, 'submitted_at' => now()]);
        $url = route('local-supplier.invoices.store');
        if ($revision) {
            $this->actingAs($this->supplier)->post($url, $this->validPayload([
                'invoice_number' => 'INV-EDITED', 'tax_invoice_number' => $current,
                'invoice' => NativeFileFixtures::upload('INV-EDITED.pdf'), 'tax_invoice' => NativeFileFixtures::upload($current.'.pdf'),
            ]))->assertSessionHasNoErrors();
            $invoice = LocalInvoice::where('invoice_number', 'INV-EDITED')->firstOrFail();
            $invoice->update(['status' => LocalInvoice::STATUS_NEED_REVISION]);
            $invoice->statusHistories()->create(['from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'to_status' => LocalInvoice::STATUS_NEED_REVISION, 'event' => 'revision_requested',
                'actor_id' => $this->finance->id, 'notes' => 'Correct the attachment identity.', 'created_at' => now()]);
            $url = route('local-supplier.invoices.resubmit', $invoice);
        }
        $count = LocalInvoice::count();
        $files = Storage::disk('private')->allFiles();
        $response = $this->actingAs($this->supplier)->postJson($url, $this->validPayload([
            'invoice_number' => 'INV-EDITED', 'tax_invoice_number' => $entered,
            ...($spoofOriginal ? ['_original_tax_invoice_number' => $used] : []),
            'invoice' => NativeFileFixtures::upload('INV-EDITED.pdf'), 'tax_invoice' => NativeFileFixtures::upload($used.'.pdf'),
        ]));
        $response->assertUnprocessable()->assertJsonPath('errors.tax_invoice_number', [
            __('local_invoice.validation.tax_filename', ['number' => $entered, 'filename' => $used]),
        ]);
        $this->assertSame($count, LocalInvoice::count());
        $this->assertSame($files, Storage::disk('private')->allFiles());
        if ($revision) {
            $this->assertSame($current, $invoice->fresh()->tax_invoice_number);
            $this->assertSame(1, $invoice->fresh()->revision_number);
        }

        // Correcting just the attachment identity resolves the mismatch.
        $this->withoutExceptionHandling();
        $this->postJson($url, $this->validPayload([
            'invoice_number' => 'INV-EDITED', 'tax_invoice_number' => str_replace(['.', '-'], '', $entered),
            'invoice' => NativeFileFixtures::upload('INV-EDITED.pdf'), 'tax_invoice' => NativeFileFixtures::upload($entered.'.pdf'),
        ]))->assertOk()->assertJsonStructure(['redirect']);
        $saved = LocalInvoice::where('invoice_number', 'INV-EDITED')->firstOrFail();
        $this->assertSame($entered, $saved->tax_invoice_number);
        $this->assertSame($revision ? 2 : 1, $saved->revision_number);
        $this->withExceptionHandling();

        // A real duplicate still names the exact normalized number being checked.
        $this->postJson(route('local-supplier.invoices.store'), $this->validPayload([
            'invoice_number' => 'INV-ANOTHER', 'tax_invoice_number' => $entered,
            'invoice' => NativeFileFixtures::upload('INV-ANOTHER.pdf'), 'tax_invoice' => NativeFileFixtures::upload($entered.'.pdf'),
        ]))->assertUnprocessable()->assertJsonPath('errors.tax_invoice_number', [
            __('local_invoice.validation.tax_number_duplicate', ['number' => $entered]),
        ]);
    }

    public function test_invoice_number_derived_from_uploaded_filename(): void
    {
        $file = NativeFileFixtures::upload('INV-2026-09-001.pdf', 100);
        $taxFile = NativeFileFixtures::upload('0100002612345678.pdf', 100);

        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            [
                'invoice' => $file,
                'tax_invoice' => $taxFile,
            ]
        ));

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $invoice = LocalInvoice::where('supplier_id', $this->supplier->id)->latest('id')->firstOrFail();
        $this->assertSame('INV-2026-09-001', $invoice->invoice_number);
        $this->assertSame('010.000-26.12345678', $invoice->tax_invoice_number);
    }

    public function test_tax_invoice_number_derived_from_17_digit_coretax_filename(): void
    {
        $file = NativeFileFixtures::upload('INV-CORETAX-001.pdf', 100);
        $taxFile = NativeFileFixtures::upload('01002600000000001.pdf', 100);

        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            [
                'invoice' => $file,
                'tax_invoice' => $taxFile,
            ]
        ));

        $response->assertSessionHasNoErrors();
        $invoice = LocalInvoice::where('supplier_id', $this->supplier->id)->latest('id')->firstOrFail();
        $this->assertSame('01.00.26.00000000001', $invoice->tax_invoice_number);
    }

    public function test_conflicting_multiple_invoice_files_are_rejected(): void
    {
        $files = [
            NativeFileFixtures::upload('INV-AAA.pdf', 100),
            NativeFileFixtures::upload('INV-BBB.pdf', 100),
        ];
        $taxFile = NativeFileFixtures::upload('0100002612345678.pdf', 100);

        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            [
                'invoice' => $files,
                'tax_invoice' => $taxFile,
            ]
        ));

        $response->assertSessionHasErrors(['invoice']);
    }

    public function test_conflicting_multiple_tax_invoice_files_are_rejected(): void
    {
        $file = NativeFileFixtures::upload('INV-VALID-001.pdf', 100);
        $taxFiles = [
            NativeFileFixtures::upload('0100002612345678.pdf', 100),
            NativeFileFixtures::upload('0100002612345679.pdf', 100),
        ];

        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            [
                'invoice' => $file,
                'tax_invoice' => $taxFiles,
            ]
        ));

        $response->assertSessionHasErrors(['tax_invoice']);
    }

    public function test_tampered_browser_invoice_number_is_rejected(): void
    {
        $file = NativeFileFixtures::upload('INV-HONEST.pdf', 100);
        $taxFile = NativeFileFixtures::upload('0100002612345678.pdf', 100);

        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload([
                'invoice_number' => 'INV-TAMPERED',
            ]),
            [
                'invoice' => $file,
                'tax_invoice' => $taxFile,
            ]
        ));

        $response->assertSessionHasErrors(['invoice_number']);
    }

    public function test_tampered_browser_tax_number_is_rejected(): void
    {
        $file = NativeFileFixtures::upload('INV-HONEST-002.pdf', 100);
        $taxFile = NativeFileFixtures::upload('0100002612345678.pdf', 100);

        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload([
                'tax_invoice_number' => '010.000-26.99999999',
            ]),
            [
                'invoice' => $file,
                'tax_invoice' => $taxFile,
            ]
        ));

        $response->assertSessionHasErrors(['tax_invoice_number']);
    }

    public function test_uniqueness_validation_enforced_on_derived_invoice_number(): void
    {
        // 1. Create first invoice
        $file1 = NativeFileFixtures::upload('INV-UNIQUE-001.pdf', 100);
        $taxFile1 = NativeFileFixtures::upload('0100002612345678.pdf', 100);
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            ['invoice' => $file1, 'tax_invoice' => $taxFile1]
        ))->assertSessionHasNoErrors();

        // 2. Submit second invoice with identical filename (different GR)
        $masters = app(LocalProcurementMasterService::class);
        $gr2 = $masters->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-AUTO-002',
            'gr_date' => '2026-09-22',
            'uom' => 'pcs', 'qty' => '5.0',
        ]);

        $file2 = NativeFileFixtures::upload('INV-UNIQUE-001.pdf', 100);
        $taxFile2 = NativeFileFixtures::upload('0100002612345679.pdf', 100);
        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(['goods_receipt_ids' => [$gr2->id]]),
            ['invoice' => $file2, 'tax_invoice' => $taxFile2]
        ));

        $response->assertSessionHasErrors(['invoice_number']);
    }

    public function test_resubmission_rejects_uploaded_file_with_different_invoice_number(): void
    {
        // Create initial invoice
        $file = NativeFileFixtures::upload('INV-RESUBMIT-001.pdf', 100);
        $taxFile = NativeFileFixtures::upload('0100002612345678.pdf', 100);
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            ['invoice' => $file, 'tax_invoice' => $taxFile]
        ))->assertSessionHasNoErrors();

        $invoice = LocalInvoice::where('supplier_id', $this->supplier->id)->latest('id')->firstOrFail();

        // Mark as NEED_REVISION by finance
        $invoice->update(['status' => LocalInvoice::STATUS_NEED_REVISION]);
        $invoice->statusHistories()->create([
            'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'to_status' => LocalInvoice::STATUS_NEED_REVISION,
            'event' => 'revision_requested',
            'actor_id' => $this->finance->id,
            'notes' => 'Please fix invoice attachment',
            'created_at' => now(),
        ]);

        // Attempt resubmitting with a DIFFERENT invoice filename
        $newFile = NativeFileFixtures::upload('INV-RESUBMIT-DIFFERENT.pdf', 100);
        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.resubmit', $invoice), array_merge(
            $this->validPayload(),
            [
                'invoice' => $newFile,
                'tax_invoice' => $taxFile,
            ]
        ));

        $response->assertSessionHasErrors(['invoice_number']);
    }
}
