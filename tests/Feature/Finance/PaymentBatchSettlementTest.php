<?php

namespace Tests\Feature\Finance;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoiceVerification;
use App\Models\LocalInvoiceVoucher;
use App\Models\LocalPurchaseOrder;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Payment\LocalInvoicePaymentService;
use App\Services\Payment\LocalInvoiceVoucherService;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentBatchSettlementTest extends TestCase
{
    use RefreshDatabase;

    private User $financeUser;
    private User $supplierBca;
    private User $supplierMandiri;

    protected function setUp(): void
    {
        parent::setUp();

        $this->financeUser = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
        ]);

        // 1. Supplier BCA
        $this->supplierBca = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        SupplierScope::create([
            'supplier_id' => $this->supplierBca->id,
            'scope' => 'local',
        ]);
        Supplier::create([
            'user_id' => $this->supplierBca->id,
            'company_name' => 'PT Supplier BCA',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);
        SupplierBankAccount::create([
            'supplier_id' => $this->supplierBca->id,
            'bank_name' => 'BCA',
            'account_number' => '111222333',
            'account_holder_name' => 'PT Supplier BCA',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'is_primary' => true,
            'is_active' => true,
            'verified_at' => now(),
            'verified_by' => $this->financeUser->id,
        ]);

        // 2. Supplier Mandiri (Non-BCA)
        $this->supplierMandiri = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        SupplierScope::create([
            'supplier_id' => $this->supplierMandiri->id,
            'scope' => 'local',
        ]);
        Supplier::create([
            'user_id' => $this->supplierMandiri->id,
            'company_name' => 'PT Supplier Mandiri',
            'category' => 'Barang',
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);
        SupplierBankAccount::create([
            'supplier_id' => $this->supplierMandiri->id,
            'bank_name' => 'Mandiri',
            'account_number' => '444555666',
            'account_holder_name' => 'PT Supplier Mandiri',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'is_primary' => true,
            'is_active' => true,
            'verified_at' => now(),
            'verified_by' => $this->financeUser->id,
        ]);
    }

    private function createVerifiedInvoice(User $supplier, float $amount, string $invoiceNumber): LocalInvoice
    {
        $po = LocalPurchaseOrder::create([
            'supplier_id' => $supplier->id,
            'po_number' => 'PO-' . uniqid(),
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'currency' => 'IDR',
            'total_amount' => $amount,
            'po_date' => '2026-09-01',
        ]);

        $gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $po->id,
            'gr_number' => 'GR-' . uniqid(),
            'gr_date' => '2026-09-02',
            'received_amount' => $amount,
            'status' => LocalGoodsReceipt::STATUS_INVOICED,
        ]);

        $invoice = LocalInvoice::create([
            'supplier_id' => $supplier->id,
            'submission_number' => 'SUB-' . uniqid(),
            'invoice_number' => $invoiceNumber,
            'invoice_date' => '2026-09-03',
            'submitted_at' => now(),
            'invoice_amount' => $amount,
            'tax_amount' => 0,
            'ppn_scheme' => '0%',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'po_source' => 'INTERNAL',
            'po_number' => $po->po_number,
            'local_purchase_order_id' => $po->id,
            'due_date' => now()->addDays(15),
            'ready_to_pay_at' => now(),
        ]);

        $gr->update(['current_invoice_id' => $invoice->id]);

        LocalInvoiceGoodsReceipt::create([
            'local_invoice_id' => $invoice->id,
            'local_purchase_order_id' => $po->id,
            'local_goods_receipt_id' => $gr->id,
            'gr_number_snapshot' => $gr->gr_number,
            'gr_amount_snapshot' => $amount,
            'received_amount_snapshot' => $amount,
            'state' => LocalInvoiceGoodsReceipt::STATE_CONSUMED,
            'consumed_by' => $this->financeUser->id,
            'consumed_at' => now(),
        ]);

        LocalInvoiceVerification::create([
            'local_invoice_id' => $invoice->id,
            'verified_by' => $this->financeUser->id,
            'is_section_a_passed' => true,
            'is_section_b_passed' => true,
            'is_locked' => true,
            'dpp_amount' => $amount,
            'ppn_amount' => 0,
            'pph_amount' => 0,
            'verified_ppn' => '0.00',
            'pph_23_applicable' => false,
            'pph_4_2_applicable' => false,
            'pph_21_applicable' => false,
            'status' => 'VERIFIED',
        ]);

        return $invoice;
    }

    public function test_entire_supplier_batch_with_bca_and_non_bca_groups_settles_to_paid_status(): void
    {
        $invBca = $this->createVerifiedInvoice($this->supplierBca, 5000000.00, 'INV-SETTLE-BCA');
        $invMandiri = $this->createVerifiedInvoice($this->supplierMandiri, 4000000.00, 'INV-SETTLE-MAN');

        $batchService = app(PaymentBatchService::class);
        $voucherService = app(LocalInvoiceVoucherService::class);

        // 1. Create Supplier DRP Batch
        $batch = $batchService->createSupplierBatch($this->financeUser, [
            $invBca->id,
            $invMandiri->id,
        ], 'Batch Pelunasan Supplier September');

        $this->assertSame(PaymentBatch::STATUS_DRAFT, $batch->status);
        $this->assertSame(PaymentBatch::TYPE_SUPPLIER, $batch->batch_type);
        $this->assertCount(2, $batch->groups);

        $bcaGroup = $batch->groups->where('payee_id', $this->supplierBca->id)->first();
        $this->assertNotNull($bcaGroup);
        $this->assertEquals(5000000.0, (float) $bcaGroup->subtotal_amount);
        $this->assertEquals(0.0, (float) $bcaGroup->bank_fee);
        $this->assertEquals(5000000.0, (float) $bcaGroup->net_payment_amount);

        $mandiriGroup = $batch->groups->where('payee_id', $this->supplierMandiri->id)->first();
        $this->assertNotNull($mandiriGroup);
        $this->assertEquals(4000000.0, (float) $mandiriGroup->subtotal_amount);
        $this->assertEquals(2500.0, (float) $mandiriGroup->bank_fee);
        $this->assertEquals(3997500.0, (float) $mandiriGroup->net_payment_amount);

        // 2. Finalize Batch
        $batch = $batchService->finalizeBatch($batch, $this->financeUser);
        $this->assertSame(PaymentBatch::STATUS_FINALIZED, $batch->status);

        // 3. Generate Vouchers for both items
        foreach ($batch->groups as $group) {
            foreach ($group->items as $item) {
                $voucher = $voucherService->finalize($item, [
                    'voucher_date' => '2026-09-18',
                    'payment_method' => 'BANK',
                ], $this->financeUser);
                $this->assertSame(LocalInvoiceVoucher::STATUS_FINAL, $voucher->status);
            }
        }

        // 4. Mark Entire Batch Paid via Controller Route
        $response = $this->actingAs($this->financeUser)->post(route('finance.drp.paid.mark-paid', $batch), [
            'transfer_reference' => 'TRF-FULL-BATCH-001',
            'transfer_date' => '2026-09-18',
            'payment_notes' => 'Settled clean batch',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        // 5. Assertions on fresh state
        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PAID, $batch->status);
        $this->assertNotNull($batch->paid_at);

        foreach ($batch->groups as $group) {
            $group->refresh();
            $this->assertSame(PaymentGroup::STATUS_PAID, $group->status);
            $this->assertSame('TRF-FULL-BATCH-001', $group->transfer_reference);
            $this->assertNotNull($group->paid_at);
        }

        $invBca->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $invBca->status);
        $this->assertNotNull($invBca->paid_at);

        $invMandiri->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $invMandiri->status);
        $this->assertNotNull($invMandiri->paid_at);

        $this->assertDatabaseHas('local_invoice_payments', [
            'local_invoice_id' => $invBca->id,
            'status' => LocalInvoicePayment::STATUS_FINALIZED,
        ]);

        $this->assertDatabaseHas('local_invoice_payments', [
            'local_invoice_id' => $invMandiri->id,
            'status' => LocalInvoicePayment::STATUS_FINALIZED,
        ]);
    }

    public function test_mark_entire_batch_paid_with_partial_custom_amount_and_subsequent_correction_settles(): void
    {
        $inv1 = $this->createVerifiedInvoice($this->supplierBca, 2000000.00, 'INV-PARTIAL-01');
        $inv2 = $this->createVerifiedInvoice($this->supplierMandiri, 3000000.00, 'INV-PARTIAL-02');

        $batchService = app(PaymentBatchService::class);
        $voucherService = app(LocalInvoiceVoucherService::class);
        $executionService = app(PaymentExecutionService::class);
        $paymentService = app(LocalInvoicePaymentService::class);

        $batch = $batchService->createSupplierBatch($this->financeUser, [$inv1->id, $inv2->id]);
        $batchService->finalizeBatch($batch, $this->financeUser);

        $item1 = null;
        $item2 = null;
        foreach ($batch->groups as $group) {
            foreach ($group->items as $item) {
                $voucherService->finalize($item, [
                    'voucher_date' => '2026-09-18',
                    'payment_method' => 'BANK',
                ], $this->financeUser);

                if ($item->payable_id === $inv1->id) {
                    $item1 = $item;
                } elseif ($item->payable_id === $inv2->id) {
                    $item2 = $item;
                }
            }
        }

        $this->assertNotNull($item1);
        $this->assertNotNull($item2);

        // Underpay item 2 by 500,000 (actual transfer = 2,500,000)
        $executionService->markEntireBatchPaid($batch, [
            'transfer_reference' => 'TRF-SPLIT-01',
            'transfer_date' => '2026-09-18',
            'payment_notes' => 'Partial clearance',
            'custom_amounts' => [
                $item2->id => 2500000.00,
            ],
            'correction_reasons' => [
                $item2->id => 'Debit memo retur material',
            ],
        ], $this->financeUser);

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PARTIALLY_PAID, $batch->status);

        $inv1->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $inv1->status);

        $inv2->refresh();
        $this->assertNotSame(LocalInvoice::STATUS_PAID, $inv2->status);

        $payment2 = LocalInvoicePayment::where('local_invoice_id', $inv2->id)->first();
        $this->assertNotNull($payment2);
        $this->assertSame(LocalInvoicePayment::STATUS_CORRECTION_REQUIRED, $payment2->status);
        $this->assertEquals(2500000.00, (float) $payment2->actual_paid_total);

        // Execute subsequent correction transfer for the remaining 500,000
        $paymentService->recordCorrection($payment2, [
            'amount' => '500000.00',
            'transfer_reference' => 'TRF-CORR-02',
            'transfer_date' => '2026-09-19',
            'correction_reason' => 'Clearance remaining balance',
        ], $this->financeUser);

        $payment2->refresh();
        $this->assertSame(LocalInvoicePayment::STATUS_FINALIZED, $payment2->status);

        $inv2->refresh();
        $this->assertSame(LocalInvoice::STATUS_PAID, $inv2->status);

        $batch->refresh();
        $this->assertSame(PaymentBatch::STATUS_PAID, $batch->status);
        $this->assertNotNull($batch->paid_at);
    }
}
