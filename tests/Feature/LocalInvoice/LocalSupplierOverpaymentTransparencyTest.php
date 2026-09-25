<?php

namespace Tests\Feature\LocalInvoice;

use App\Models\Attachment;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceStatusHistory;
use App\Models\LocalPurchaseOrder;
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
use App\Services\Payment\LocalInvoicePaymentService;
use App\Services\Payment\LocalInvoiceVoucherService;
use App\Services\Payment\SupplierOverpaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LocalSupplierOverpaymentTransparencyTest extends TestCase
{
    use RefreshDatabase;

    private User $supplier;

    private User $otherSupplier;

    private User $finance;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr1;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        config([
            'finance.adasi_refund_account.bank_name' => 'Bank Central Asia (BCA)',
            'finance.adasi_refund_account.account_number' => '888-001-9922',
            'finance.adasi_refund_account.account_holder' => 'PT Astra Daido Steel Indonesia',
            'finance.adasi_refund_account.finance_contact' => 'finance-local@adasi.co.id',
            'finance.adasi_refund_account.finance_email' => 'finance-local@adasi.co.id',
            'finance.adasi_refund_account.finance_wa' => '081298765432',
        ]);

        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->supplier->supplierScopes()->delete();
        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->supplier->id, 'company_name' => 'PT Supplier Utama', 'category' => 'Raw Material', 'is_pkp' => false, 'payment_term_days' => 30]);

        $this->otherSupplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->otherSupplier->supplierScopes()->delete();
        SupplierScope::create(['supplier_id' => $this->otherSupplier->id, 'scope' => 'local']);
        Supplier::create(['user_id' => $this->otherSupplier->id, 'company_name' => 'PT Supplier Lain', 'category' => 'Raw Material', 'is_pkp' => false, 'payment_term_days' => 30]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $masters = app(LocalProcurementMasterService::class);
        $this->po = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-OP-001',
            'po_date' => '2026-09-10',
            'total_amount' => '1000.00',
        ]);
        $this->gr1 = $masters->createGoodsReceipt($this->finance, $this->po, [
            'gr_number' => 'GR-OP-001',
            'gr_date' => '2026-09-11',
            'qty' => 10.0,
        ]);
    }

    private function createInvoiceWithOverpayment(string $invNumber = 'INV-OP-001', string $overAmount = '50.00'): array
    {
        $invoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => $invNumber,
            'invoice_date' => '2026-09-13',
            'local_purchase_order_id' => $this->po->id,
            'goods_receipt_ids' => [$this->gr1->id],
            'invoice_amount' => '1000.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);

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
            'account_number' => '11223344',
            'account_holder_name' => 'PT Supplier Utama',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-OP-'.uniqid(),
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
        ]);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Utama',
            'bank_name' => 'BCA',
            'account_number' => '11223344',
            'account_holder_name' => 'PT Supplier Utama',
            'subtotal_amount' => '1000.00',
            'bank_fee' => '0.00',
            'net_payment_amount' => '1000.00',
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => '1000.00',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, [
            'voucher_date' => '2026-09-14',
            'payment_method' => 'BANK',
        ], $this->finance);

        $actualPaid = bcadd('1000.00', $overAmount, 2);
        $payment = app(LocalInvoicePaymentService::class)->recordPrimary($voucher, [
            'amount' => $actualPaid,
            'transfer_reference' => 'TRF-'.uniqid(),
            'transfer_date' => '2026-09-15',
        ], $this->finance);

        $refund = $payment->overpayment;

        return [$invoice->fresh(), $payment->fresh(), $refund->fresh()];
    }

    public function test_supplier_can_view_open_overpayment_with_bank_details_and_copy_instruction(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-OPEN-001', '50.00');

        $response = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Pemberitahuan Kelebihan Pembayaran (Overpayment)');
        $response->assertSee('Perlu Pengembalian Dana');
        $response->assertSee('Rp 1.000'); // Bill amount
        $response->assertSee('Rp 1.050'); // Transferred amount
        $response->assertSee('Rp 50');    // Overpayment amount

        // ADASI official refund destination
        $response->assertSee('Bank Central Asia (BCA)');
        $response->assertSee('888-001-9922');
        $response->assertSee('PT Astra Daido Steel Indonesia');
        $response->assertSee('finance-local@adasi.co.id');
        $response->assertSee('Email');
        $response->assertSee('mailto:finance-local@adasi.co.id', false);
        $response->assertSee('mail.google.com/mail', false);
        $response->assertSee('outlook.office.com/mail', false);
        $response->assertSee('Salin Alamat Email');
        $response->assertSee('WhatsApp');
        $response->assertSee('https://wa.me/6281298765432', false);
        $response->assertSee('fill="#25D366"', false);

        // Copy interaction attribute
        $response->assertSee('data-copy-text="888-001-9922"', false);
        $response->assertSee('Salin Nomor Rekening');
    }

    public function test_supplier_can_view_settled_overpayment_card_and_reference_details(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-SETTLE-001', '75.00');

        $proof = UploadedFile::fake()->create('bukti_refund.pdf', 100, 'application/pdf');
        $settled = app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '75.00',
            'refund_reference' => 'REF-ADASI-7788',
            'refund_date' => '2026-09-16',
            'notes' => 'Telah diterima via transfer BCA',
        ], $proof, $this->finance);

        $response = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Penyelesaian Kelebihan Pembayaran (Overpayment)');
        $response->assertSee('Selesai / Terverifikasi');
        $response->assertSee('Rp 75');
        $response->assertSee('16 Sep 2026');
        $response->assertSee('REF-ADASI-7788');
        $response->assertSee('Telah diterima via transfer BCA');
        $response->assertSee('bukti_refund.pdf');
        $response->assertSee('Unduh Bukti');
    }

    public function test_supplier_can_download_own_overpayment_settlement_proof(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-PROOF-001', '25.00');

        $proof = UploadedFile::fake()->create('settlement_proof.pdf', 50, 'application/pdf');
        $settled = app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '25.00',
            'refund_reference' => 'REF-PROOF-01',
            'refund_date' => '2026-09-16',
        ], $proof, $this->finance);

        $attachment = $settled->attachments()->first();
        $this->assertNotNull($attachment);

        $response = $this->actingAs($this->supplier)
            ->get(route('attachments.show', $attachment->id));

        $response->assertOk();
    }

    public function test_supplier_cannot_download_other_suppliers_overpayment_proof(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-IDOR-001', '30.00');

        $proof = UploadedFile::fake()->create('secret_proof.pdf', 50, 'application/pdf');
        $settled = app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '30.00',
            'refund_reference' => 'REF-IDOR-01',
            'refund_date' => '2026-09-16',
        ], $proof, $this->finance);

        $attachment = $settled->attachments()->first();
        $this->assertNotNull($attachment);

        // Other supplier forbidden
        $response = $this->actingAs($this->otherSupplier)
            ->get(route('attachments.show', $attachment->id));

        $response->assertForbidden();

        // Unauthenticated forbidden / redirected
        auth()->logout();
        $guestResponse = $this->get(route('attachments.show', $attachment->id));
        $guestResponse->assertRedirect(route('login'));
    }

    public function test_supplier_invoice_table_renders_overpayment_badges_for_open_and_settled(): void
    {
        [$openInvoice] = $this->createInvoiceWithOverpayment('INV-BADGE-OPEN', '40.00');

        // Settled invoice
        $masters = app(LocalProcurementMasterService::class);
        $po2 = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-OP-002',
            'po_date' => '2026-09-10',
            'total_amount' => '500.00',
        ]);
        $gr2 = $masters->createGoodsReceipt($this->finance, $po2, [
            'gr_number' => 'GR-OP-002',
            'gr_date' => '2026-09-11',
            'qty' => 10.0,
        ]);

        $settledInvoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-BADGE-SETTLED',
            'invoice_date' => '2026-09-13',
            'local_purchase_order_id' => $po2->id,
            'goods_receipt_ids' => [$gr2->id],
            'invoice_amount' => '500.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);

        app(LocalGrReservationService::class)->consume($settledInvoice, $this->finance);
        $settledInvoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $settledInvoice->currentVerification()->create([
            'revision_number' => 1,
            'is_section_a_passed' => true,
            'is_section_b_passed' => true,
            'is_locked' => true,
            'verified_ppn' => '0.00',
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-OP-2',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
        ]);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Utama',
            'bank_name' => 'BCA',
            'account_number' => '11223344',
            'account_holder_name' => 'PT Supplier Utama',
            'subtotal_amount' => '500.00',
            'bank_fee' => '0.00',
            'net_payment_amount' => '500.00',
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $settledInvoice->id,
            'amount' => '500.00',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, [
            'voucher_date' => '2026-09-14',
            'payment_method' => 'BANK',
        ], $this->finance);

        $payment = app(LocalInvoicePaymentService::class)->recordPrimary($voucher, [
            'amount' => '560.00',
            'transfer_reference' => 'TRF-SETTLE-BADGE',
            'transfer_date' => '2026-09-15',
        ], $this->finance);

        $refund = $payment->overpayment;
        app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '60.00',
            'refund_reference' => 'REF-BADGE-OK',
            'refund_date' => '2026-09-16',
        ], UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), $this->finance);

        $response = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.index'));

        $response->assertOk();
        $response->assertSee('Kelebihan Bayar: Rp 40 (Perlu Refund)');
        $response->assertSee('Refund Selesai');
    }

    public function test_settlement_creates_status_history_and_sends_notification_to_supplier(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-NOTIF-001', '80.00');

        $proof = UploadedFile::fake()->create('proof_notif.pdf', 20, 'application/pdf');
        $settled = app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '80.00',
            'refund_reference' => 'REF-NOTIF-99',
            'refund_date' => '2026-09-16',
            'notes' => 'Konfirmasi diterima via m-Banking',
        ], $proof, $this->finance);

        // Verify status history
        $history = LocalInvoiceStatusHistory::where('local_invoice_id', $invoice->id)
            ->where('event', 'refund_settled')
            ->first();

        $this->assertNotNull($history);
        $this->assertSame($invoice->status, $history->from_status);
        $this->assertSame($invoice->status, $history->to_status);
        $this->assertSame($this->finance->id, $history->actor_id);
        $this->assertStringContainsString('80', $history->notes);
        $this->assertStringContainsString('REF-NOTIF-99', $history->notes);
        $this->assertStringContainsString('Konfirmasi diterima via m-Banking', $history->notes);

        // Verify in-app notification delivered to supplier
        $notification = $this->supplier->notifications()->where('data->event', 'local_invoice.refund_settled')->first();
        $this->assertNotNull($notification);
        $this->assertSame('local_invoice.refund_settled', $notification->data['event'] ?? null);
    }

    public function test_overpayment_status_filter_open_and_settled(): void
    {
        [$openInvoice, $openPayment, $openRefund] = $this->createInvoiceWithOverpayment('INV-FLT-OPEN', '35.00');

        // Second invoice that gets settled
        $masters = app(LocalProcurementMasterService::class);
        $po3 = $masters->createPurchaseOrder($this->finance, [
            'supplier_id' => $this->supplier->id,
            'po_number' => 'PO-OP-003',
            'po_date' => '2026-09-10',
            'total_amount' => '500.00',
        ]);
        $gr3 = $masters->createGoodsReceipt($this->finance, $po3, [
            'gr_number' => 'GR-OP-003',
            'gr_date' => '2026-09-11',
            'qty' => 10.0,
        ]);

        $settledInvoice = app(InvoiceSubmissionService::class)->submit($this->supplier, [
            'invoice_number' => 'INV-FLT-SETTLED',
            'invoice_date' => '2026-09-13',
            'local_purchase_order_id' => $po3->id,
            'goods_receipt_ids' => [$gr3->id],
            'invoice_amount' => '500.00',
            'tax_amount' => '0.00',
            'ppn_scheme' => '0%',
        ], ['invoice' => UploadedFile::fake()->create('invoice.pdf', 10, 'application/pdf')]);

        app(LocalGrReservationService::class)->consume($settledInvoice, $this->finance);
        $settledInvoice->update(['status' => LocalInvoice::STATUS_READY_TO_PAY]);
        $settledInvoice->currentVerification()->create([
            'revision_number' => 1,
            'is_section_a_passed' => true,
            'is_section_b_passed' => true,
            'is_locked' => true,
            'verified_ppn' => '0.00',
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
        ]);

        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-FLT-'.uniqid(),
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'created_by' => $this->finance->id,
        ]);
        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Supplier Utama',
            'bank_name' => 'BCA',
            'account_number' => '11223344',
            'account_holder_name' => 'PT Supplier Utama',
            'subtotal_amount' => '500.00',
            'bank_fee' => '0.00',
            'net_payment_amount' => '500.00',
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);
        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $settledInvoice->id,
            'amount' => '500.00',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = app(LocalInvoiceVoucherService::class)->finalize($item, [
            'voucher_date' => '2026-09-14',
            'payment_method' => 'BANK',
        ], $this->finance);

        $payment = app(LocalInvoicePaymentService::class)->recordPrimary($voucher, [
            'amount' => '540.00',
            'transfer_reference' => 'TRF-FLT-SETTLE',
            'transfer_date' => '2026-09-15',
        ], $this->finance);

        $refund = $payment->overpayment;
        app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '40.00',
            'refund_reference' => 'REF-FLT-01',
            'refund_date' => '2026-09-16',
        ], UploadedFile::fake()->create('proof.pdf', 10, 'application/pdf'), $this->finance);

        // Filter OPEN
        $resOpen = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.index', ['overpayment_status' => 'open']));
        $resOpen->assertOk();
        $resOpen->assertSee('INV-FLT-OPEN');
        $resOpen->assertDontSee('INV-FLT-SETTLED');

        // Filter SETTLED
        $resSettled = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.index', ['overpayment_status' => 'settled']));
        $resSettled->assertOk();
        $resSettled->assertSee('INV-FLT-SETTLED');
        $resSettled->assertDontSee('INV-FLT-OPEN');

        // Filter ALL
        $resAll = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.index', ['overpayment_status' => 'all']));
        $resAll->assertOk();
        $resAll->assertSee('INV-FLT-OPEN');
        $resAll->assertSee('INV-FLT-SETTLED');

        // Cross-supplier isolation
        $resOther = $this->actingAs($this->otherSupplier)
            ->get(route('local-supplier.invoices.index', ['overpayment_status' => 'open']));
        $resOther->assertOk();
        $resOther->assertDontSee('INV-FLT-OPEN');
    }

    public function test_settlement_is_idempotent_and_rejects_second_settlement(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-IDEMP-001', '10.00');

        $proof1 = UploadedFile::fake()->create('proof1.pdf', 10, 'application/pdf');
        $settled = app(SupplierOverpaymentService::class)->settle($refund, [
            'refund_amount' => '10.00',
            'refund_reference' => 'REF-IDEMP-01',
            'refund_date' => '2026-09-16',
        ], $proof1, $this->finance);

        $this->assertSame(SupplierOverpaymentRefund::STATUS_SETTLED, $settled->status);
        $this->assertSame(1, LocalInvoiceStatusHistory::where('local_invoice_id', $invoice->id)->where('event', 'refund_settled')->count());

        // Attempt second settlement
        $this->expectException(ValidationException::class);
        $proof2 = UploadedFile::fake()->create('proof2.pdf', 10, 'application/pdf');
        app(SupplierOverpaymentService::class)->settle($settled, [
            'refund_amount' => '10.00',
            'refund_reference' => 'REF-IDEMP-02',
            'refund_date' => '2026-09-17',
        ], $proof2, $this->finance);
    }

    public function test_defensive_rendering_when_attachment_or_notes_missing(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-DEF-001', '15.00');

        // Settle directly in DB without attachment and without notes to simulate legacy or edge cases
        $refund->update([
            'status' => SupplierOverpaymentRefund::STATUS_SETTLED,
            'refund_amount' => '15.00',
            'refund_reference' => 'REF-MANUAL-01',
            'refund_date' => '2026-09-16',
            'notes' => null,
            'settled_by' => $this->finance->id,
            'settled_at' => now(),
        ]);

        $response = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.show', $invoice));

        $response->assertOk();
        $response->assertSee('Penyelesaian Kelebihan Pembayaran (Overpayment)');
        $response->assertSee('Selesai / Terverifikasi');
        $response->assertSee('REF-MANUAL-01');
        $response->assertDontSee('Unduh Bukti'); // No proof attachment rendered
    }

    public function test_overpayment_email_and_whatsapp_templates_contain_structured_details_and_copy_action(): void
    {
        [$invoice, $payment, $refund] = $this->createInvoiceWithOverpayment('INV-MAIL-DETAIL-01', '75.00');

        $response = $this->actingAs($this->supplier)
            ->get(route('local-supplier.invoices.show', $invoice));

        $response->assertOk();
        // Overpayment notice and amounts
        $response->assertSee('Pemberitahuan Kelebihan Pembayaran (Overpayment)');
        $response->assertSee('Rp 75');

        // Structured email subject and body in links
        $response->assertSee('Buka di Gmail (Web)');
        $response->assertSee('Buka di Outlook (Web)');
        $response->assertSee('Aplikasi Email Bawaan');
        $response->assertSee('Salin Teks Draf Pesan');
        $response->assertSee('Salin Alamat Email Saja');
        $response->assertSee('mail.google.com/mail');
        $response->assertSee('outlook.office.com/mail');
        $response->assertSee('mailto:finance-local@adasi.co.id');
        $response->assertSee('data-copy-target="#email-draft-text-'.$invoice->id.'"', false);

        // WhatsApp structured message
        $response->assertSee('wa.me/6281298765432');
        $response->assertSee(rawurlencode('Nilai Kelebihan Bayar: Rp 75'));
    }
}
