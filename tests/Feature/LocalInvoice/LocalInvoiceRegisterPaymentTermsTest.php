<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\NativeFileFixtures;
use Tests\TestCase;

class LocalInvoiceRegisterPaymentTermsTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private User $accounting;

    private static int $authSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();

        $this->supplier = $this->createSupplierWithTerm(45);
        $this->finance = User::factory()->create(['role' => 'finance']);
        $this->accounting = User::factory()->create(['role' => 'accounting']);
    }

    private function createSupplierWithTerm(int $termDays): User
    {
        $user = User::factory()->create(['role' => 'supplier']);
        $user->supplierScopes()->delete();
        $user->supplierScopes()->create(['scope' => 'local']);

        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Test Supplier '.$user->id,
            'address' => 'Jakarta',
            'phone' => '08123456789',
            'npwp' => '01.234.567.8-999.000',
            'category' => 'Parts',
            'vendor_category' => 'Barang',
            'payment_term_days' => $termDays,
            'is_pkp' => true,
        ]);

        return $user;
    }

    private function authoritative(User $owner): array
    {
        $suffix = str_pad((string) ++self::$authSeq, 3, '0', STR_PAD_LEFT);
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $owner->id,
            'po_number' => 'PO-AUTH-'.$suffix,
            'po_date' => '2026-09-01',
            'total_amount' => '50000000.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-AUTH-'.$suffix,
            'gr_date' => '2026-09-02',
            'uom' => 'pcs',
            'qty' => 10.0,
        ]);

        return ['local_purchase_order_id' => $po->id, 'goods_receipt_ids' => [$gr->id]];
    }

    private function submitInvoice(User $supplier, array $overrides = []): LocalInvoice
    {
        $data = array_merge([
            'invoice_number' => 'INV-TERM-'.uniqid(),
            'invoice_date' => '2026-09-10',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'tax_invoice_number' => '010.000-26.'.random_int(10000000, 99999999),
        ], $this->authoritative($supplier), $overrides);

        $files = [
            ...NativeFileFixtures::invoiceReferences(),
            'invoice' => NativeFileFixtures::upload('invoice.pdf', 100),
            'tax_invoice' => NativeFileFixtures::upload('tax.pdf'),
            'delivery_note' => NativeFileFixtures::upload('sj.pdf'),
        ];

        $this->actingAs($supplier)
            ->post(route('local-supplier.invoices.store'), array_merge($data, $files))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        return LocalInvoice::where('invoice_number', $data['invoice_number'])->sole();
    }

    public function test_invoice_submission_and_resubmission_snapshots_payment_terms_from_supplier(): void
    {
        // 1. Submit captures 45 days
        $invoice = $this->submitInvoice($this->supplier);
        $this->assertSame(45, $invoice->payment_term_days_snapshot);
        $this->assertSame(45, $invoice->effectivePaymentTermDays());

        // 2. Request revision
        $signedQr = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => $invoice->receipt->receipt_number,
            'revision' => $invoice->revision_number,
        ]);
        app(InvoicePhysicalReceiptService::class)->recordReceipt($this->finance, $invoice, scannedReceiptQr: $signedQr);
        app(InvoiceVerificationService::class)->requestRevision($invoice, 'Please revise documents', $this->finance);

        $this->assertSame('NEED_REVISION', $invoice->fresh()->status);

        // 3. Update supplier term to 60 days in Vendor Master
        $this->supplier->supplier->update(['payment_term_days' => 60]);

        // 4. Resubmit
        $resubmitData = [
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => '2026-09-10',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'tax_invoice_number' => $invoice->tax_invoice_number,
            'local_purchase_order_id' => $invoice->local_purchase_order_id,
            'goods_receipt_ids' => $invoice->localPurchaseOrder->goodsReceipts->pluck('id')->all(),
        ];
        $files = [
            ...NativeFileFixtures::invoiceReferences(),
            'invoice' => NativeFileFixtures::upload($invoice->invoice_number.'.pdf', 100),
            'tax_invoice' => NativeFileFixtures::upload('tax_revised.pdf'),
            'delivery_note' => NativeFileFixtures::upload('sj_revised.pdf'),
        ];

        $this->actingAs($this->supplier)
            ->post(route('local-supplier.invoices.resubmit', $invoice), array_merge($resubmitData, $files))
            ->assertSessionHasNoErrors();

        $fresh = $invoice->fresh();
        // Resubmission should capture the updated 60 days snapshot instead of leaving it null
        $this->assertSame(60, $fresh->payment_term_days_snapshot);
        $this->assertSame(60, $fresh->effectivePaymentTermDays());
    }

    public function test_effective_payment_term_days_returns_snapshot_or_falls_back_to_supplier(): void
    {
        $invoice = $this->submitInvoice($this->supplier);

        // Has snapshot
        $this->assertSame(45, $invoice->effectivePaymentTermDays());

        // Legacy invoice with null snapshot falls back to supplier profile
        $invoice->update(['payment_term_days_snapshot' => null]);
        $this->assertNull($invoice->fresh()->payment_term_days_snapshot);
        $this->assertSame(45, $invoice->fresh()->effectivePaymentTermDays());
    }

    public function test_invoice_register_displays_payment_terms_column_for_all_roles(): void
    {
        $invoice = $this->submitInvoice($this->supplier);

        // 1. Local Supplier Invoice Register
        $supplierResponse = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.index'));
        $supplierResponse->assertOk();
        $supplierResponse->assertSee(__('local_invoice.labels.term_days'));
        $supplierResponse->assertSee('Net 45');

        // 2. Finance AP Invoice Register
        $financeResponse = $this->actingAs($this->finance)->get(route('finance.invoices.index'));
        $financeResponse->assertOk();
        $financeResponse->assertSee(__('local_invoice.labels.term_days'));
        $financeResponse->assertSee('Net 45');

        // 3. Accounting Invoice Register
        $accountingResponse = $this->actingAs($this->accounting)->get(route('accounting.invoices.index'));
        $accountingResponse->assertOk();
        $accountingResponse->assertSee(__('local_invoice.labels.term_days'));
        $accountingResponse->assertSee('Net 45');
    }

    public function test_supplier_can_see_payment_terms_in_invoice_detail(): void
    {
        $invoice = $this->submitInvoice($this->supplier);

        $response = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.show', $invoice));
        $response->assertOk();
        $response->assertSee(__('local_invoice.detail.payment_term'));
        $response->assertSee('Net 45');
    }
}
