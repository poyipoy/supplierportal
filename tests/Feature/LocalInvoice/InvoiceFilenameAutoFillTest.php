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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
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
            'received_amount' => '100000.00',
            'qty' => '10.0000',
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

    public function test_invoice_number_derived_from_uploaded_filename(): void
    {
        $file = UploadedFile::fake()->create('INV-2026-09-001.pdf', 100, 'application/pdf');
        $taxFile = UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf');

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
        $file = UploadedFile::fake()->create('INV-CORETAX-001.pdf', 100, 'application/pdf');
        $taxFile = UploadedFile::fake()->create('01002600000000001.pdf', 100, 'application/pdf');

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
            UploadedFile::fake()->create('INV-AAA.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('INV-BBB.pdf', 100, 'application/pdf'),
        ];
        $taxFile = UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf');

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
        $file = UploadedFile::fake()->create('INV-VALID-001.pdf', 100, 'application/pdf');
        $taxFiles = [
            UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('0100002612345679.pdf', 100, 'application/pdf'),
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
        $file = UploadedFile::fake()->create('INV-HONEST.pdf', 100, 'application/pdf');
        $taxFile = UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf');

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
        $file = UploadedFile::fake()->create('INV-HONEST-002.pdf', 100, 'application/pdf');
        $taxFile = UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf');

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
        $file1 = UploadedFile::fake()->create('INV-UNIQUE-001.pdf', 100, 'application/pdf');
        $taxFile1 = UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf');
        $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(),
            ['invoice' => $file1, 'tax_invoice' => $taxFile1]
        ))->assertSessionHasNoErrors();

        // 2. Submit second invoice with identical filename (different GR)
        $masters = app(LocalProcurementMasterService::class);
        $gr2 = $masters->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-AUTO-002',
            'gr_date' => '2026-09-22',
            'received_amount' => '100000.00',
            'qty' => '5.0000',
        ]);

        $file2 = UploadedFile::fake()->create('INV-UNIQUE-001.pdf', 100, 'application/pdf');
        $taxFile2 = UploadedFile::fake()->create('0100002612345679.pdf', 100, 'application/pdf');
        $response = $this->actingAs($this->supplier)->post(route('local-supplier.invoices.store'), array_merge(
            $this->validPayload(['goods_receipt_ids' => [$gr2->id]]),
            ['invoice' => $file2, 'tax_invoice' => $taxFile2]
        ));

        $response->assertSessionHasErrors(['invoice_number']);
    }

    public function test_resubmission_rejects_uploaded_file_with_different_invoice_number(): void
    {
        // Create initial invoice
        $file = UploadedFile::fake()->create('INV-RESUBMIT-001.pdf', 100, 'application/pdf');
        $taxFile = UploadedFile::fake()->create('0100002612345678.pdf', 100, 'application/pdf');
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
        $newFile = UploadedFile::fake()->create('INV-RESUBMIT-DIFFERENT.pdf', 100, 'application/pdf');
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
