<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoiceVoucher;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierOverpaymentRefund;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceExpiryService;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalGrReservationService;
use App\Services\LocalInvoice\LocalPoGrImportService;
use App\Services\LocalInvoice\LocalPoReferenceService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Services\Payment\LocalInvoicePaymentService;
use App\Services\Payment\LocalInvoiceVoucherService;
use App\Services\Payment\SupplierOverpaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalSupplierWholeGrSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $finance;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr1;

    private LocalGoodsReceipt $gr2;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Whole GR', 'category' => 'Parts', 'is_pkp' => false, 'payment_term_days' => 30]);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $masters = app(LocalProcurementMasterService::class);
        $this->po = $masters->createPurchaseOrder($this->finance, ['supplier_id' => $this->supplier->id, 'po_number' => 'PO-WHOLE-001', 'po_date' => '2026-09-10', 'total_amount' => '300.00']);
        $this->gr1 = $masters->createGoodsReceipt($this->finance, $this->po, ['gr_number' => 'GR-WHOLE-001', 'gr_date' => '2026-09-11', 'qty' => 10.0]);
        $this->gr2 = $masters->createGoodsReceipt($this->finance, $this->po, ['gr_number' => 'GR-WHOLE-002', 'gr_date' => '2026-09-12', 'qty' => 20.0]);
    }

    private function invoice(array $overrides = []): LocalInvoice
    {
        return app(InvoiceSubmissionService::class)->submit($this->supplier, array_merge([
            'invoice_number' => 'INV-WHOLE-001', 'invoice_date' => '2026-09-13',
            'local_purchase_order_id' => $this->po->id, 'goods_receipt_ids' => [$this->gr1->id, $this->gr2->id],
            'invoice_amount' => '300.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%',
        ], $overrides), ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);
    }

    public function test_invoice_requires_one_po_and_exact_whole_gr_total(): void
    {
        $invoice = $this->invoice();

        $this->assertSame($this->po->id, $invoice->fresh()->local_purchase_order_id);
        $this->assertSame('INTERNAL', $invoice->fresh()->po_source);
        $this->assertSame('RESERVED', $this->gr1->fresh()->status);
        $this->assertSame($invoice->id, $this->gr1->fresh()->current_invoice_id);
        $this->assertSame(2, $invoice->goodsReceiptHistories()->where('state', 'RESERVED')->count());

        $this->expectException(ValidationException::class);
        $this->invoice(['invoice_number' => 'INV-WHOLE-002', 'invoice_amount' => '100.00', 'goods_receipt_ids' => [$this->gr1->id]]);
    }

    public function test_authoritative_po_master_cannot_be_masked_by_legacy_provider_data(): void
    {
        LocalPoReferenceService::registerInternalPo($this->supplier->id, $this->po->po_number, 999999.00, null);

        $details = app(LocalPoReferenceService::class)->getInternalPoDetails($this->supplier, $this->po->po_number);

        $this->assertTrue($details['authoritative']);
        $this->assertSame((float) $this->po->total_amount, $details['po_value']);
        $this->assertTrue($details['has_gr']);
        $this->assertSame('GR-WHOLE-001, GR-WHOLE-002', $details['gr_reference']);
    }

    public function test_authoritative_invoice_cannot_resubmit_through_legacy_reference_branch(): void
    {
        $invoice = $this->invoice();
        $invoice->update(['status' => LocalInvoice::STATUS_NEED_REVISION]);
        $invoice->statusHistories()->create([
            'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'to_status' => LocalInvoice::STATUS_NEED_REVISION,
            'actor_id' => $this->finance->id,
            'event' => 'revision_requested',
            'notes' => 'Provide corrected document.',
            'created_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(InvoiceSubmissionService::class)->resubmit($this->supplier, $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'invoice_date' => '2026-09-13',
            'po_source' => 'MANUAL',
            'manual_po_number' => 'LEGACY-PO-001',
            'manual_gr_reference' => 'LEGACY-GR-001',
            'invoice_amount' => '300.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('invoice-revision.pdf', 10, 'application/pdf')]);
    }

    public function test_gr_cannot_be_cross_supplier_or_reused(): void
    {
        $other = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $other->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $other->id, 'company_name' => 'PT Other', 'category' => 'Parts', 'payment_term_days' => 30]);

        try {
            app(InvoiceSubmissionService::class)->submit($other, ['invoice_number' => 'INV-CROSS', 'invoice_date' => '2026-09-13', 'local_purchase_order_id' => $this->po->id, 'goods_receipt_ids' => [$this->gr1->id], 'invoice_amount' => '100.00', 'tax_amount' => '0.00', 'ppn_scheme' => '0%'], ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);
            $this->fail('Cross-supplier PO should be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('local_invoices', 0);
        }

        $invoice = $this->invoice();
        $this->assertSame('RESERVED', $this->gr1->fresh()->status);
        $this->expectException(ValidationException::class);
        $this->invoice(['invoice_number' => 'INV-REUSE', 'invoice_amount' => '100.00', 'goods_receipt_ids' => [$this->gr1->id]]);
    }

    public function test_expiry_releases_reserved_gr_and_closed_po_allows_existing_set(): void
    {
        $invoice = $this->invoice();
        app(LocalProcurementMasterService::class)->closePurchaseOrder($this->finance, $this->po);
        app(LocalGrReservationService::class)->reserve($this->supplier, $invoice, $this->po->id, [$this->gr1->id, $this->gr2->id]);
        $expiry = app(InvoiceExpiryService::class);
        $expiry->recordMissedDelivery($invoice, $this->finance);
        $expiry->recordMissedDelivery($invoice, $this->finance);
        $this->assertSame(LocalInvoice::STATUS_EXPIRED, $invoice->fresh()->status);
        $this->assertSame(LocalGoodsReceipt::STATUS_AVAILABLE, $this->gr1->fresh()->status);
        $this->assertNull($this->gr1->fresh()->current_invoice_id);
    }

    public function test_voucher_and_settlement_are_one_per_invoice_and_overpayment_is_separate(): void
    {
        $invoice = $this->invoice();
        app(LocalGrReservationService::class)->consume($invoice, $this->finance);
        $invoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $invoice->currentVerification()->create(['revision_number' => 1, 'is_section_a_passed' => true, 'is_section_b_passed' => true, 'is_locked' => true, 'verified_ppn' => '0.00', 'pph_23_applicable' => false, 'pph_4_2_applicable' => false, 'pph_21_applicable' => false]);
        SupplierBankAccount::create(['supplier_id' => $this->supplier->id, 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'PT Whole GR', 'status' => SupplierBankAccount::STATUS_VERIFIED]);
        $batch = PaymentBatch::create(['batch_number' => 'DRP-TEST-001', 'batch_type' => PaymentBatch::TYPE_SUPPLIER, 'status' => PaymentBatch::STATUS_FINALIZED, 'created_by' => $this->finance->id]);
        $group = $batch->groups()->create(['payee_type' => 'supplier', 'payee_id' => $this->supplier->id, 'payee_name' => 'PT Whole GR', 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'PT Whole GR', 'subtotal_amount' => '300.00', 'bank_fee' => '0.00', 'net_payment_amount' => '300.00', 'status' => PaymentGroup::STATUS_UNPAID]);
        $item = $group->items()->create(['payable_type' => LocalInvoice::class, 'payable_id' => $invoice->id, 'amount' => '300.00', 'status' => PaymentItem::STATUS_ACTIVE]);
        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, ['voucher_date' => '2026-09-14', 'payment_method' => 'BANK'], $this->finance);
        $this->assertSame('300.00', $voucher->amount);
        $this->assertSame(1, LocalInvoiceVoucher::where('local_invoice_id', $invoice->id)->count());
        $payment = app(LocalInvoicePaymentService::class)->recordPrimary($voucher, ['amount' => '301.00', 'transfer_reference' => 'TRF-001', 'transfer_date' => '2026-09-15'], $this->finance);
        $this->assertSame(LocalInvoicePayment::STATUS_FINALIZED, $payment->status);
        $this->assertSame('1.00', $payment->overpayment->overpayment_amount);
        $this->assertSame(LocalInvoice::STATUS_PAID, $invoice->fresh()->status);
        $this->assertSame(PaymentGroup::STATUS_PAID, $group->fresh()->status);
        $this->expectException(ValidationException::class);
        app(LocalInvoicePaymentService::class)->recordPrimary($voucher, ['amount' => '300.00', 'transfer_reference' => 'TRF-002', 'transfer_date' => '2026-09-15'], $this->finance);
    }

    public function test_voucher_print_route_returns_authoritative_adisi_pdf(): void
    {
        $invoice = $this->invoice();
        app(LocalGrReservationService::class)->consume($invoice, $this->finance);
        $invoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $invoice->currentVerification()->create([
            'revision_number' => 1,
            'is_section_a_passed' => true,
            'is_section_b_passed' => true,
            'is_locked' => true,
            'verified_ppn' => '0.00',
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);
        SupplierBankAccount::create([
            'supplier_id' => $this->supplier->id,
            'bank_name' => 'BCA',
            'account_number' => '123',
            'account_holder_name' => 'PT Whole GR',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-TEST-PDF',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
        ]);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Whole GR',
            'bank_name' => 'BCA',
            'account_number' => '123',
            'account_holder_name' => 'PT Whole GR',
            'subtotal_amount' => '300.00',
            'bank_fee' => '0.00',
            'net_payment_amount' => '300.00',
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => '300.00',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);
        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, [
            'voucher_date' => '2026-09-14',
            'payment_method' => 'BANK',
        ], $this->finance);

        $response = $this->actingAs($this->finance)->get(route('finance.vouchers.print', $voucher));

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_underpayment_requires_correction_and_refund_requires_exact_full_amount(): void
    {
        $invoice = $this->invoice();
        app(LocalGrReservationService::class)->consume($invoice, $this->finance);
        $invoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $invoice->currentVerification()->create(['revision_number' => 1, 'is_section_a_passed' => true, 'is_section_b_passed' => true, 'is_locked' => true, 'verified_ppn' => '0.00', 'pph_23_applicable' => false, 'pph_4_2_applicable' => false, 'pph_21_applicable' => false]);
        $batch = PaymentBatch::create(['batch_number' => 'DRP-TEST-002', 'batch_type' => PaymentBatch::TYPE_SUPPLIER, 'status' => PaymentBatch::STATUS_FINALIZED, 'created_by' => $this->finance->id]);
        $group = $batch->groups()->create(['payee_type' => 'supplier', 'payee_id' => $this->supplier->id, 'payee_name' => 'PT Whole GR', 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'PT Whole GR', 'subtotal_amount' => '300.00', 'bank_fee' => '0.00', 'net_payment_amount' => '300.00', 'status' => PaymentGroup::STATUS_UNPAID]);
        $item = $group->items()->create(['payable_type' => LocalInvoice::class, 'payable_id' => $invoice->id, 'amount' => '300.00', 'status' => PaymentItem::STATUS_ACTIVE]);
        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, ['voucher_date' => '2026-09-14', 'payment_method' => 'BANK'], $this->finance);
        $payment = app(LocalInvoicePaymentService::class)->recordPrimary($voucher, ['amount' => '299.00', 'transfer_reference' => 'TRF-SHORT', 'transfer_date' => '2026-09-15', 'correction_reason' => 'Bank short transfer'], $this->finance);
        $this->assertSame(LocalInvoicePayment::STATUS_CORRECTION_REQUIRED, $payment->status);
        $payment = app(LocalInvoicePaymentService::class)->recordCorrection($payment, ['amount' => '1.00', 'transfer_reference' => 'TRF-CORR', 'transfer_date' => '2026-09-16', 'correction_reason' => 'Short transfer correction'], $this->finance);
        $this->assertSame(LocalInvoicePayment::STATUS_FINALIZED, $payment->status);
        $refund = $payment->overpayment;
        if ($refund) {
            $this->fail('An exact correction should not create an overpayment.');
        }
    }

    public function test_import_validation_is_atomic_and_exact_supplier_match(): void
    {
        $service = app(LocalPoGrImportService::class);
        $rows = [
            ['_row' => 2, 'po_number' => 'PO-IMPORT-001', 'supplier_name' => 'PT Whole GR', 'po_date' => '2026-09-15', 'po_amount' => '500.00', 'po_remarks' => null, 'gr_number' => 'GR-IMPORT-001', 'gr_date' => '2026-09-15', 'gr_amount' => '500.00', 'gr_remarks' => null, '_formula_columns' => []],
            ['_row' => 3, 'po_number' => 'PO-IMPORT-002', 'supplier_name' => 'Unknown Supplier', 'po_date' => '2026-09-15', 'po_amount' => '10.00', 'po_remarks' => null, 'gr_number' => null, 'gr_date' => null, 'gr_amount' => null, 'gr_remarks' => null, '_formula_columns' => []],
        ];
        $result = $service->validate($rows);
        $this->assertFalse($result['success']);
        $this->assertCount(0, LocalPurchaseOrder::where('source', LocalPurchaseOrder::SOURCE_IMPORT)->get());
        $this->expectException(ValidationException::class);
        $service->import($this->finance, $rows);
        $this->assertDatabaseMissing('local_purchase_orders', ['po_number' => 'PO-IMPORT-001']);
    }

    public function test_valid_import_creates_new_po_and_multiple_whole_gr_rows(): void
    {
        $rows = [
            ['_row' => 2, 'po_number' => 'PO-IMPORT-VALID', 'supplier_name' => 'PT Whole GR', 'po_date' => '2026-09-15', 'po_amount' => '500.00', 'po_remarks' => 'Imported', 'gr_number' => 'GR-IMPORT-A', 'gr_date' => '2026-09-15', 'gr_amount' => '200.00', 'gr_remarks' => null, '_formula_columns' => []],
            ['_row' => 3, 'po_number' => 'PO-IMPORT-VALID', 'supplier_name' => 'pt whole gr', 'po_date' => '2026-09-15', 'po_amount' => '500', 'po_remarks' => 'Imported', 'gr_number' => 'GR-IMPORT-B', 'gr_date' => '2026-09-16', 'gr_amount' => '300.00', 'gr_remarks' => null, '_formula_columns' => []],
        ];

        $service = app(LocalPoGrImportService::class);
        $validated = $service->validate($rows);
        $this->assertTrue($validated['success']);
        $this->assertSame(1, $validated['summary']['new_po']);
        $this->assertSame(2, $validated['summary']['new_gr']);

        $counts = $service->import($this->finance, $rows);
        $this->assertSame(1, $counts['newPo']);
        $this->assertSame(2, $counts['newGr']);
        $po = LocalPurchaseOrder::where('po_number', 'PO-IMPORT-VALID')->firstOrFail();
        $this->assertSame(LocalPurchaseOrder::SOURCE_IMPORT, $po->source);
        $this->assertSame(2, $po->goodsReceipts()->count());
    }

    public function test_new_master_routes_require_hashed_keys_and_role_access(): void
    {
        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.show', $this->po))
            ->assertOk()
            ->assertSee($this->po->po_number);

        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.show', $this->po->id))
            ->assertNotFound();

        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.index', ['supplier_id' => $this->supplier->id]))
            ->assertNotFound();
        $this->actingAs($this->finance)
            ->get(route('finance.local-procurement.index', ['supplier_id' => $this->supplier->hash]))
            ->assertOk()
            ->assertSee($this->po->po_number);

        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->actingAs($purchasing)
            ->get(route('purchasing.local-procurement.show', $this->po))
            ->assertOk();

        $this->actingAs($this->supplier)
            ->get(route('finance.local-procurement.show', $this->po))
            ->assertForbidden();
    }

    public function test_overpayment_refund_is_exact_once_and_proof_stays_private(): void
    {
        $invoice = $this->invoice();
        app(LocalGrReservationService::class)->consume($invoice, $this->finance);
        $invoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $invoice->currentVerification()->create(['revision_number' => 1, 'is_section_a_passed' => true, 'is_section_b_passed' => true, 'is_locked' => true, 'verified_ppn' => '0.00', 'pph_23_applicable' => false, 'pph_4_2_applicable' => false, 'pph_21_applicable' => false]);
        SupplierBankAccount::create(['supplier_id' => $this->supplier->id, 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'PT Whole GR', 'status' => SupplierBankAccount::STATUS_VERIFIED]);
        $batch = PaymentBatch::create(['batch_number' => 'DRP-TEST-REFUND', 'batch_type' => PaymentBatch::TYPE_SUPPLIER, 'status' => PaymentBatch::STATUS_FINALIZED, 'created_by' => $this->finance->id]);
        $group = $batch->groups()->create(['payee_type' => 'supplier', 'payee_id' => $this->supplier->id, 'payee_name' => 'PT Whole GR', 'bank_name' => 'BCA', 'account_number' => '123', 'account_holder_name' => 'PT Whole GR', 'subtotal_amount' => '300.00', 'bank_fee' => '0.00', 'net_payment_amount' => '300.00', 'status' => PaymentGroup::STATUS_UNPAID]);
        $item = $group->items()->create(['payable_type' => LocalInvoice::class, 'payable_id' => $invoice->id, 'amount' => '300.00', 'status' => PaymentItem::STATUS_ACTIVE]);
        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, ['voucher_date' => '2026-09-14', 'payment_method' => 'BANK'], $this->finance);
        $payment = app(LocalInvoicePaymentService::class)->recordPrimary($voucher, ['amount' => '301.00', 'transfer_reference' => 'TRF-REFUND', 'transfer_date' => '2026-09-15'], $this->finance);
        $refund = $payment->overpayment;

        $this->assertDatabaseHas('local_invoice_status_histories', [
            'local_invoice_id' => $invoice->id,
            'event' => 'overpaid',
        ]);

        $supplierShow = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.show', $invoice));
        $supplierShow->assertOk();
        $supplierShow->assertSee('Pemberitahuan Kelebihan Pembayaran (Overpayment)');
        $supplierShow->assertSee('Riwayat Pembayaran &amp; Mutasi Transfer Bank', false);

        try {
            app(SupplierOverpaymentService::class)->settle($refund, ['refund_amount' => '0.50', 'refund_reference' => 'REF-PARTIAL', 'refund_date' => '2026-09-16'], UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), $this->finance);
            $this->fail('A partial refund must be rejected.');
        } catch (ValidationException) {
            $this->assertSame(SupplierOverpaymentRefund::STATUS_OPEN, $refund->fresh()->status);
        }

        $settled = app(SupplierOverpaymentService::class)->settle($refund, ['refund_amount' => '1.00', 'refund_reference' => 'REF-FULL', 'refund_date' => '2026-09-16'], UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), $this->finance);
        $attachment = $settled->attachments->first();
        $this->assertSame(SupplierOverpaymentRefund::STATUS_SETTLED, $settled->status);
        $this->assertNotNull($attachment);
        Storage::disk('private')->assertExists($attachment->file_path);
        $this->assertTrue($this->finance->can('view', $attachment));
        $purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->assertFalse($purchasing->can('view', $attachment));

        $this->expectException(ValidationException::class);
        app(SupplierOverpaymentService::class)->settle($settled, ['refund_amount' => '1.00', 'refund_reference' => 'REF-TWICE', 'refund_date' => '2026-09-17'], UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), $this->finance);
    }

    public function test_invoice_with_ten_goods_receipts_does_not_truncate_references(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-MULTI-GR-001',
            'po_date' => '2026-09-10',
            'total_amount' => '1000.00',
        ]);

        $grIds = [];
        $expectedGrNumbers = [];
        for ($i = 1; $i <= 10; $i++) {
            $grNumber = sprintf('GR-LOC-2026-009-%02d', $i);
            $gr = $masters->createGoodsReceipt($this->finance, $po, [
                'gr_number' => $grNumber,
                'gr_date' => '2026-09-11',
                'qty' => 10.0,
            ]);
            $grIds[] = $gr->id;
            $expectedGrNumbers[] = $grNumber;
        }

        $expectedRefString = implode(', ', $expectedGrNumbers);
        $this->assertGreaterThan(100, strlen($expectedRefString));

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-MULTI-GR-10',
            'invoice_date' => '2026-09-13',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => $grIds,
            'invoice_amount' => '1000.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);

        $this->assertSame($expectedRefString, $invoice->fresh()->internal_gr_reference);
        $this->assertSame(10, $invoice->goodsReceiptHistories()->where('state', 'RESERVED')->count());
    }

    public function test_invoice_cannot_exceed_po_ceiling(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-CEIL-001',
            'po_date' => '2026-09-10',
            'total_amount' => '100.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, ['gr_number' => 'GR-CEIL-001', 'gr_date' => '2026-09-11', 'qty' => 5.0]);

        $this->expectException(ValidationException::class);
        app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-CEIL-001',
            'invoice_date' => '2026-09-13',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '150.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);
    }
}
