<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierOverpaymentRefund;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceSubmissionService;
use App\Services\LocalInvoice\LocalGrReservationService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Services\Payment\LocalInvoiceVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceDrpPaidTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $admin;

    private User $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        Notification::fake();

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Supplier Testing',
            'category' => 'Raw Material',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);
    }

    public function test_drp_paid_requires_authentication_and_finance_or_admin_role(): void
    {
        // Unauthenticated
        $this->get(route('finance.drp.paid.index'))->assertRedirect(route('login'));

        // Supplier role -> 403
        $this->actingAs($this->supplier)->get(route('finance.drp.paid.index'))->assertForbidden();

        // Finance role -> 200
        $this->actingAs($this->finance)->get(route('finance.drp.paid.index'))->assertOk();

        // Admin role -> 200
        $this->actingAs($this->admin)->get(route('finance.drp.paid.index'))->assertOk();
    }

    public function test_drp_paid_index_filters_by_tabs_and_types(): void
    {
        $batchDraft = PaymentBatch::create([
            'batch_number' => 'DRP-DRAFT-001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 1000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000000,
            'created_by' => $this->finance->id,
        ]);

        $batchFinalized = PaymentBatch::create([
            'batch_number' => 'DRP-FIN-001',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 2000000,
            'total_bank_fee' => 5000,
            'total_net_amount' => 1995000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $batchPaid = PaymentBatch::create([
            'batch_number' => 'DRP-PAID-001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_PAID,
            'total_subtotal' => 3000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 3000000,
            'created_by' => $this->finance->id,
            'paid_at' => now(),
        ]);

        // Default tab (unpaid) -> shows DRAFT & FINALIZED, excludes PAID
        $response = $this->actingAs($this->finance)->get(route('finance.drp.paid.index'));
        $response->assertOk();
        $response->assertSee('DRP-DRAFT-001');
        $response->assertSee('DRP-FIN-001');
        $response->assertDontSee('DRP-PAID-001');

        // Tab Paid -> shows PAID, excludes DRAFT & FINALIZED
        $responsePaid = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', ['tab' => 'paid']));
        $responsePaid->assertOk();
        $responsePaid->assertSee('DRP-PAID-001');
        $responsePaid->assertDontSee('DRP-DRAFT-001');
        $responsePaid->assertDontSee('DRP-FIN-001');

        // Tab All -> shows all
        $responseAll = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', ['tab' => 'all']));
        $responseAll->assertOk();
        $responseAll->assertSee('DRP-DRAFT-001');
        $responseAll->assertSee('DRP-FIN-001');
        $responseAll->assertSee('DRP-PAID-001');

        // Filter Type GA -> shows only GA
        $responseGa = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', ['tab' => 'all', 'type' => 'GA']));
        $responseGa->assertOk();
        $responseGa->assertSee('DRP-FIN-001');
        $responseGa->assertDontSee('DRP-DRAFT-001');
        $responseGa->assertDontSee('DRP-PAID-001');
    }

    public function test_mark_batch_paid_successfully_marks_ga_batch_as_paid(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $employee = Employee::create([
            'name' => 'Budi GA',
            'department' => 'General Affairs',
            'bank_name' => 'BCA',
            'account_number' => '5551234',
            'account_holder_name' => 'Budi GA',
            'is_active' => true,
        ]);
        $claim = GaClaim::create([
            'claim_number' => 'CLM-GA-001',
            'employee_id' => $employee->id,
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
            'claim_date' => '2026-09-15',
            'amount' => '500000.00',
            'status' => GaClaim::STATUS_READY_TO_PAY,
            'submitted_by' => $gaUser->id,
            'submitted_at' => now(),
            'description' => 'Medical checkup',
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-GA-BATCH-001',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 500000,
            'total_bank_fee' => 0,
            'total_net_amount' => 500000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'ga',
            'payee_id' => $gaUser->id,
            'payee_name' => $gaUser->name,
            'bank_name' => 'BCA',
            'account_number' => '5551234',
            'account_holder_name' => $gaUser->name,
            'subtotal_amount' => 500000,
            'bank_fee' => 0,
            'net_payment_amount' => 500000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $group->items()->create([
            'payable_type' => GaClaim::class,
            'payable_id' => $claim->id,
            'amount' => 500000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-GA-999',
            'transfer_date' => '2026-09-16',
            'payment_notes' => 'Settled via auto batch pay',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PAID, $batch->status);
        $this->assertNotNull($batch->paid_at);

        $group->refresh();
        $this->assertSame(PaymentGroup::STATUS_PAID, $group->status);
        $this->assertSame('TRF-GA-999', $group->transfer_reference);

        $claim->refresh();
        $this->assertSame(GaClaim::STATUS_PAID, $claim->status);
    }

    public function test_mark_batch_paid_successfully_settles_supplier_batch(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-DRP-PAID-01',
            'po_date' => '2026-09-10',
            'total_amount' => '1000.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-DRP-PAID-01',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-DRP-PAID-01',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '1000.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);

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
            'account_number' => '998877',
            'account_holder_name' => 'PT Supplier Testing',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-SUPP-BATCH-001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 1000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '998877',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 1000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 1000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Pre-generate voucher (mandatory before marking paid)
        $voucherService = app(LocalInvoiceVoucherService::class);
        $voucher = $voucherService->finalize($item, [
            'voucher_date' => '2026-09-16',
            'payment_method' => 'BANK',
            'remarks' => 'Pre-generated for batch settlement',
        ], $this->finance);

        $this->assertSame(LocalInvoiceVoucher::STATUS_FINAL, $voucher->status);

        $response = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-SUPP-12345',
            'transfer_date' => '2026-09-16',
            'payment_notes' => 'Batch settlement complete',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PAID, $batch->status);
        $this->assertNotNull($batch->paid_at);

        $invoice->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        $this->assertDatabaseHas('local_invoice_vouchers', [
            'payment_item_id' => $item->id,
            'status' => LocalInvoiceVoucher::STATUS_FINAL,
        ]);

        $this->assertDatabaseHas('local_invoice_payments', [
            'local_invoice_id' => $invoice->id,
            'status' => LocalInvoicePayment::STATUS_FINALIZED,
        ]);
    }

    public function test_mark_batch_paid_rejects_supplier_batch_with_unvouchered_items(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-DRP-NOVOUCHER-01',
            'po_date' => '2026-09-10',
            'total_amount' => '1000.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-DRP-NOVOUCHER-01',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-DRP-NOVOUCHER-01',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '1000.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);

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
            'account_number' => '998877',
            'account_holder_name' => 'PT Supplier Testing',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-SUPP-NOVOUCHER',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 1000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '998877',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 1000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 1000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Do NOT generate voucher — this should be rejected
        $response = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-SHOULD-FAIL',
            'transfer_date' => '2026-09-16',
            'payment_notes' => 'This should fail',
        ]);

        $response->assertSessionHasErrors('batch');

        // Batch should remain FINALIZED
        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_FINALIZED, $batch->status);

        // Verify that DRP Paid page renders disabled button and tooltip note
        $pageResponse = $this->actingAs($this->finance)->get(route('finance.drp.paid.index'));
        $pageResponse->assertOk();
        $pageResponse->assertSee('Voucher Belum Lengkap');
        $pageResponse->assertSee('Terdapat 1 tagihan yang belum diterbitkan Voucher Bayar');
        $pageResponse->assertSee('disabled');

        $this->assertNull($batch->paid_at);

        // Invoice should remain READY_TO_PAY
        $invoice->refresh();
        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->status);
    }

    public function test_primary_settlement_route_is_blocked(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-BLOCK-PRIMARY',
            'po_date' => '2026-09-10',
            'total_amount' => '500.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-BLOCK-PRIMARY',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-BLOCK-PRIMARY',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '500.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);

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
            'account_number' => '112233',
            'account_holder_name' => 'PT Supplier Testing',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-BLOCK-PRIMARY',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '112233',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 500,
            'bank_fee' => 0,
            'net_payment_amount' => 500,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Generate voucher first
        $voucherService = app(LocalInvoiceVoucherService::class);
        $voucher = $voucherService->finalize($item, [
            'voucher_date' => '2026-09-16',
            'payment_method' => 'BANK',
        ], $this->finance);

        // Attempting to use the primary settlement route should return 403
        $response = $this->actingAs($this->finance)->post(route('finance.settlements.primary', $voucher), [
            'amount' => '500.00',
            'transfer_reference' => 'TRF-DIRECT',
            'transfer_date' => '2026-09-16',
        ]);

        $response->assertForbidden();
    }

    public function test_mark_batch_paid_validates_input_and_batch_state(): void
    {
        $batchDraft = PaymentBatch::create([
            'batch_number' => 'DRP-DRAFT-VAL',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_DRAFT,
            'created_by' => $this->finance->id,
        ]);

        // Attempting to pay a draft batch should fail
        $response = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batchDraft), [
            'transfer_reference' => 'TRF-DRAFT',
            'transfer_date' => '2026-09-16',
        ]);
        $response->assertSessionHasErrors();

        // Finalized batch with missing transfer_reference
        $batchFin = PaymentBatch::create([
            'batch_number' => 'DRP-FIN-VAL',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $resMissing = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batchFin), [
            'transfer_reference' => '',
            'transfer_date' => '2026-09-16',
        ]);
        $resMissing->assertSessionHasErrors('transfer_reference');
    }

    public function test_generate_voucher_creates_voucher_and_allows_repeated_print(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-VOUCHER-01',
            'po_date' => '2026-09-10',
            'total_amount' => '500.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-VOUCHER-01',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-VOUCHER-01',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '500.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);

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
            'account_number' => '112233',
            'account_holder_name' => 'PT Supplier Testing',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-VOUCHER-BATCH',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '112233',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 500,
            'bank_fee' => 0,
            'net_payment_amount' => 500,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Post to vouchers.generate alias
        $resGen = $this->actingAs($this->finance)->post(route('finance.vouchers.generate', $item), [
            'voucher_date' => '2026-09-16',
            'payment_method' => 'BANK',
            'remarks' => 'Auto generated',
        ]);

        $resGen->assertRedirect();
        $resGen->assertSessionHas('success');

        $voucher = LocalInvoiceVoucher::where('payment_item_id', $item->id)->firstOrFail();
        $this->assertSame(LocalInvoiceVoucher::STATUS_FINAL, $voucher->status);

        // Test stream print PDF
        $resPrint = $this->actingAs($this->finance)->get(route('finance.vouchers.print', $voucher));
        $resPrint->assertOk();
        $this->assertStringContainsString('application/pdf', $resPrint->headers->get('Content-Type'));

        // Test download PDF (?download=1)
        $resDownload = $this->actingAs($this->finance)->get(route('finance.vouchers.print', [$voucher, 'download' => 1]));
        $resDownload->assertOk();
        $this->assertStringContainsString('attachment', $resDownload->headers->get('Content-Disposition') ?? '');
    }

    public function test_drp_paid_metrics_exclude_cancelled_and_zero_amount_batches(): void
    {
        // 1. Paid batch: 500,000
        PaymentBatch::create([
            'batch_number' => 'DRP-TEST-PAID-1',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_PAID,
            'total_subtotal' => 500000,
            'total_bank_fee' => 0,
            'total_net_amount' => 500000,
            'created_by' => $this->finance->id,
            'paid_at' => now(),
        ]);

        // 2. Active unpaid batch: 300,000
        PaymentBatch::create([
            'batch_number' => 'DRP-TEST-UNPAID-1',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 300000,
            'total_bank_fee' => 0,
            'total_net_amount' => 300000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        // 3. Cancelled batch: should be excluded
        PaymentBatch::create([
            'batch_number' => 'DRP-TEST-CANCELLED-1',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_CANCELLED,
            'total_subtotal' => 0,
            'total_bank_fee' => 0,
            'total_net_amount' => 0,
            'created_by' => $this->finance->id,
        ]);

        // 4. Empty draft batch with net 0: should be excluded
        PaymentBatch::create([
            'batch_number' => 'DRP-TEST-EMPTY-1',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 0,
            'total_bank_fee' => 0,
            'total_net_amount' => 0,
            'created_by' => $this->finance->id,
        ]);

        $response = $this->actingAs($this->finance)->get(route('finance.drp.paid.index'));
        $response->assertOk();

        // Metrics passed to view
        $metrics = $response->viewData('metrics');
        $this->assertSame(2, $metrics['total_batches'], 'Total batches should only count active unpaid + paid batches');
        $this->assertSame(1, $metrics['unpaid_count'], 'Unpaid count should only count batches with net amount > 0');
        $this->assertSame(300000.0, (float) $metrics['unpaid_amount']);
        $this->assertSame(1, $metrics['paid_count']);
        $this->assertSame(500000.0, (float) $metrics['paid_amount']);

        // Unpaid tab should not see cancelled or empty batches
        $response->assertSee('DRP-TEST-UNPAID-1');
        $response->assertDontSee('DRP-TEST-CANCELLED-1');
        $response->assertDontSee('DRP-TEST-EMPTY-1');
        $response->assertDontSee('DRP-TEST-PAID-1');
    }

    public function test_removing_last_item_from_draft_batch_auto_cancels_batch(): void
    {
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-AUTO-CANCEL-001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 100000,
            'total_bank_fee' => 0,
            'total_net_amount' => 100000,
            'created_by' => $this->finance->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '112233',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 100000,
            'bank_fee' => 0,
            'net_payment_amount' => 100000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => 99999,
            'amount' => 100000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Remove the item
        $response = $this->actingAs($this->finance)->post(route('finance.drp.remove-item', $item), [
            'reason' => 'Invoice dikeluarkan untuk revisi',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $item->refresh();
        $this->assertSame(PaymentItem::STATUS_REMOVED, $item->status);
        $this->assertSame('Invoice dikeluarkan untuk revisi', $item->removal_reason);

        $group->refresh();
        $this->assertSame(PaymentGroup::STATUS_CANCELLED, $group->status);
        $this->assertEquals(0, $group->net_payment_amount);

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_CANCELLED, $batch->status);
        $this->assertEquals(0, $batch->total_net_amount);
    }

    public function test_finance_can_cancel_draft_batch(): void
    {
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-CANCEL-TEST-001',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 250000,
            'total_bank_fee' => 0,
            'total_net_amount' => 250000,
            'created_by' => $this->finance->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '112233',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 250000,
            'bank_fee' => 0,
            'net_payment_amount' => 250000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => 88888,
            'amount' => 250000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->finance)->post(route('finance.drp.cancel', $batch), [
            'reason' => 'Batch dibatalkan karena kesalahan jadwal',
        ]);

        $response->assertRedirect(route('finance.drp.supplier'));
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_CANCELLED, $batch->status);
        $this->assertEquals(0, $batch->total_net_amount);

        $group->refresh();
        $this->assertSame(PaymentGroup::STATUS_CANCELLED, $group->status);

        $item->refresh();
        $this->assertSame(PaymentItem::STATUS_REMOVED, $item->status);
        $this->assertSame('Batch dibatalkan karena kesalahan jadwal', $item->removal_reason);
    }

    public function test_mark_batch_paid_with_overpayment_creates_supplier_overpayment(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-OVERPAY-01',
            'po_date' => '2026-09-10',
            'total_amount' => '500.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-OVERPAY-01',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-OVERPAY-01',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '500.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);

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
            'account_number' => '998877',
            'account_holder_name' => 'PT Supplier Overpay',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-OVERPAY-TEST',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Overpay',
            'bank_name' => 'BCA',
            'account_number' => '998877',
            'account_holder_name' => 'PT Supplier Overpay',
            'subtotal_amount' => 500,
            'bank_fee' => 0,
            'net_payment_amount' => 500,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, [
            'voucher_date' => '2026-09-16',
            'payment_method' => 'BANK',
        ], $this->finance);

        // Mark paid with custom amount higher than voucher (550 vs 500)
        $response = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-OVERPAY-123',
            'transfer_date' => '2026-09-17',
            'custom_amounts' => [
                $item->id => '550.00',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PAID, $batch->status);

        $invoice->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $invoice->status);

        $payment = LocalInvoicePayment::where('local_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(LocalInvoicePayment::STATUS_FINALIZED, $payment->status);
        $this->assertSame('550.00', (string) $payment->actual_paid_total);
        $this->assertNotNull($payment->overpayment);
        $this->assertSame('50.00', (string) $payment->overpayment->overpayment_amount);
        $this->assertSame(SupplierOverpaymentRefund::STATUS_OPEN, $payment->overpayment->status);

        // Assert status history recorded the overpayment
        $this->assertDatabaseHas('local_invoice_status_histories', [
            'local_invoice_id' => $invoice->id,
            'event' => 'overpaid',
        ]);

        // Assert supplier detail view shows the Overpayment banner and transfer mutation card
        $supplierShowResponse = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.show', $invoice));
        $supplierShowResponse->assertOk();
        $supplierShowResponse->assertSee('Pemberitahuan Kelebihan Pembayaran (Overpayment)');
        $supplierShowResponse->assertSee('Riwayat Pembayaran &amp; Mutasi Transfer Bank', false);
    }

    public function test_mark_batch_paid_with_underpayment_sets_correction_required_and_partially_paid_batch(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-UNDERPAY-01',
            'po_date' => '2026-09-10',
            'total_amount' => '500.00',
        ]);
        $gr = $masters->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-UNDERPAY-01',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);

        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-UNDERPAY-01',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po->id,
            'goods_receipt_ids' => [$gr->id],
            'invoice_amount' => '500.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('inv.pdf', 10, 'application/pdf')]);

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
            'account_number' => '112244',
            'account_holder_name' => 'PT Supplier Underpay',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-UNDERPAY-TEST',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 500,
            'total_bank_fee' => 0,
            'total_net_amount' => 500,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Underpay',
            'bank_name' => 'BCA',
            'account_number' => '112244',
            'account_holder_name' => 'PT Supplier Underpay',
            'subtotal_amount' => 500,
            'bank_fee' => 0,
            'net_payment_amount' => 500,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 500,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, [
            'voucher_date' => '2026-09-16',
            'payment_method' => 'BANK',
        ], $this->finance);

        // Mark paid with custom amount lower than voucher (450 vs 500) and a reason
        $response = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-UNDERPAY-123',
            'transfer_date' => '2026-09-17',
            'custom_amounts' => [
                $item->id => '450.00',
            ],
            'correction_reasons' => [
                $item->id => 'Potong biaya admin bank transfer',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PARTIALLY_PAID, $batch->status);

        $invoice->refresh();
        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->status);

        $payment = LocalInvoicePayment::where('local_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(LocalInvoicePayment::STATUS_CORRECTION_REQUIRED, $payment->status);
        $this->assertSame('450.00', (string) $payment->actual_paid_total);
        $this->assertNull($payment->overpayment);

        $this->assertEquals(450.0, $batch->actual_paid_amount);
        $this->assertEquals(50.0, $batch->remaining_amount);

        // Assert status history recorded the partial payment
        $this->assertDatabaseHas('local_invoice_status_histories', [
            'local_invoice_id' => $invoice->id,
            'event' => 'partial_payment',
        ]);

        // Assert supplier detail view shows the Underpayment banner and transfer mutation card
        $supplierShowResponse = $this->actingAs($this->supplier)->get(route('local-supplier.invoices.show', $invoice));
        $supplierShowResponse->assertOk();
        $supplierShowResponse->assertSee('Pembayaran Sebagian (Kurang Bayar)');
        $supplierShowResponse->assertSee('Riwayat Pembayaran &amp; Mutasi Transfer Bank', false);
        $supplierShowResponse->assertSee('TRF-UNDERPAY-123');
        $supplierShowResponse->assertSee('Potong biaya admin bank transfer');
        $supplierShowResponse->assertSee('Sisa: Rp 50');

        // Assert DRP Paid index view displays remaining amount breakdown
        $indexResponse = $this->actingAs($this->finance)->get(route('finance.drp.paid.index'));
        $indexResponse->assertOk();
        $indexResponse->assertSee('Sebagian Sudah Dibayar');
        $indexResponse->assertSee('Terbayar: Rp 450');
        $indexResponse->assertSee('Sisa: Rp 50');

        // Assert DRP show view displays remaining amount breakdown cards
        $showResponse = $this->actingAs($this->finance)->get(route('finance.drp.show', $batch));
        $showResponse->assertOk();
        $showResponse->assertSee('Sudah Terbayar');
        $showResponse->assertSee('Sisa Belum Lunas');

        // Settle the remaining 50.00 in a second mark-paid call
        $settleResponse = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-UNDERPAY-CLEARED',
            'transfer_date' => '2026-09-18',
            'custom_amounts' => [
                $item->id => '50.00',
            ],
            'correction_reasons' => [
                $item->id => 'Pelunasan sisa kurang bayar',
            ],
        ]);

        $settleResponse->assertRedirect();
        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PAID, $batch->status);
        $this->assertEquals(500.0, $batch->actual_paid_amount);
        $this->assertEquals(0.0, $batch->remaining_amount);

        $invoice->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $invoice->status);
        $payment->refresh();
        $this->assertSame(LocalInvoicePayment::STATUS_FINALIZED, $payment->status);
        $this->assertSame('500.00', (string) $payment->actual_paid_total);
    }

    public function test_drp_paid_renders_overpayment_badge_and_filters_by_overpayment_status(): void
    {
        $masters = app(LocalProcurementMasterService::class);
        $po1 = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-OP-FILTER-01',
            'po_date' => '2026-09-10',
            'total_amount' => '1000.00',
        ]);
        $gr1 = $masters->createGoodsReceipt($this->finance, $po1, [
            'gr_number' => 'GR-OP-FILTER-01',
            'gr_date' => '2026-09-11',
            'qty' => '1.0000',
        ]);
        $inv1 = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-OP-FILTER-01',
            'invoice_date' => '2026-09-12',
            'local_purchase_order_id' => $po1->id,
            'goods_receipt_ids' => [$gr1->id],
            'invoice_amount' => '1000.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('INV-OP-FILTER-01.pdf', 10, 'application/pdf')]);
        app(LocalGrReservationService::class)->consume($inv1, $this->finance);
        $inv1->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $inv1->currentVerification()->create([
            'revision_number' => 1,
            'is_section_a_passed' => true,
            'is_section_b_passed' => true,
            'is_locked' => true,
            'verified_ppn' => '0.00',
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);

        $batch1 = PaymentBatch::create([
            'batch_number' => 'DRP-BATCH-OVERPAY-1',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 1000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);
        $grp1 = $batch1->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Testing',
            'bank_name' => 'BCA',
            'account_number' => '112244',
            'account_holder_name' => 'PT Supplier Testing',
            'subtotal_amount' => 1000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $item1 = $grp1->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $inv1->id,
            'amount' => 1000,
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);
        app(LocalInvoiceVoucherService::class)->finalize($item1, [
            'voucher_date' => '2026-09-16',
            'payment_method' => 'BANK',
        ], $this->finance);

        // Batch 2 without overpayment (normal)
        PaymentBatch::create([
            'batch_number' => 'DRP-BATCH-NORMAL-2',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_PAID,
            'total_subtotal' => 2000,
            'total_bank_fee' => 0,
            'total_net_amount' => 2000,
            'created_by' => $this->finance->id,
            'paid_at' => now(),
        ]);

        // Settle batch 1 with overpayment: 1200 vs 1000
        $res = $this->actingAs($this->finance)->post(route('finance.drp.paid.mark-paid', $batch1), [
            'transfer_reference' => 'TRF-TEST-OP-1',
            'transfer_date' => '2026-09-17',
            'custom_amounts' => [
                $item1->id => '1200.00',
            ],
        ]);
        $res->assertRedirect();
        $res->assertSessionHas('success', fn ($msg) => str_contains($msg, 'Terdeteksi kelebihan bayar sebesar Rp 200'));

        // Visit DRP Paid list and assert overpayment badge and transfer amount appear
        $listRes = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', ['tab' => 'paid']));
        $listRes->assertOk();
        $listRes->assertSee('DRP-BATCH-OVERPAY-1');
        $listRes->assertSee('Overpayment: Rp 200 (Open)');
        $listRes->assertSee('Transfer: Rp 1.200');
        $listRes->assertSee('Refund Overpayment');

        // Test filter overpayment_status=has_overpayment
        $filterAllOp = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', [
            'tab' => 'paid',
            'overpayment_status' => 'has_overpayment',
        ]));
        $filterAllOp->assertOk();
        $filterAllOp->assertSee('DRP-BATCH-OVERPAY-1');
        $filterAllOp->assertDontSee('DRP-BATCH-NORMAL-2');

        // Test filter overpayment_status=open
        $filterOpen = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', [
            'tab' => 'paid',
            'overpayment_status' => 'open',
        ]));
        $filterOpen->assertOk();
        $filterOpen->assertSee('DRP-BATCH-OVERPAY-1');

        // Test filter overpayment_status=settled (should NOT see DRP-BATCH-OVERPAY-1 because it is still OPEN)
        $filterSettled = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', [
            'tab' => 'paid',
            'overpayment_status' => 'settled',
        ]));
        $filterSettled->assertOk();
        $filterSettled->assertDontSee('DRP-BATCH-OVERPAY-1');

        // Now settle the refund
        $refund = SupplierOverpaymentRefund::where('supplier_id', $this->supplier->id)->firstOrFail();
        $refund->update(['status' => SupplierOverpaymentRefund::STATUS_SETTLED]);

        // Re-check settled filter
        $filterSettledAfter = $this->actingAs($this->finance)->get(route('finance.drp.paid.index', [
            'tab' => 'paid',
            'overpayment_status' => 'settled',
        ]));
        $filterSettledAfter->assertOk();
        $filterSettledAfter->assertSee('DRP-BATCH-OVERPAY-1');
        $filterSettledAfter->assertSee('Overpayment Selesai (Rp 200)');

        // Test DRP Show
        $showRes = $this->actingAs($this->finance)->get(route('finance.drp.show', $batch1));
        $showRes->assertOk();
        $showRes->assertSee('Overpayment Selesai (Rp 200)');
        $showRes->assertSee('Refund');
    }
}
