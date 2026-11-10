<?php

namespace Tests\Feature\LocalInvoice;

use App\Enums\LocalInvoiceStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoiceReceipt;
use App\Models\LocalInvoiceVerification;
use App\Models\LocalPurchaseOrder;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\User;
use App\Services\LocalInvoice\InvoicePhysicalReceiptService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class LocalInvoiceStateCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    private User $supplierUser;

    private User $cashierUser;

    private User $financeUser;

    private LocalPurchaseOrder $po;

    private LocalGoodsReceipt $gr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->financeUser = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
        ]);

        $this->cashierUser = User::factory()->create([
            'role' => 'finance',
            'is_active' => true,
        ]);

        $this->supplierUser = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
        ]);
        $this->supplierUser->supplierScopes()->firstOrCreate(['scope' => 'local']);
        $this->supplierUser->forgetSupplierScopes();

        Supplier::create([
            'user_id' => $this->supplierUser->id,
            'company_name' => 'PT Karakterisasi Lokal',
            'category' => 'Jasa',
            'vendor_category' => 'Jasa',
            'is_pkp' => true,
            'npwp' => '01.234.567.8-901.000',
            'payment_term_days' => 30,
        ]);

        SupplierBankAccount::create([
            'supplier_id' => $this->supplierUser->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Karakterisasi Lokal',
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'verified_by' => $this->financeUser->id,
            'verified_at' => now(),
            'activated_at' => now(),
        ]);

        $this->po = LocalPurchaseOrder::create([
            'po_number' => 'PO-CHAR-001',
            'pr_number' => 'PR-CHAR-001',
            'supplier_id' => $this->supplierUser->id,
            'po_date' => now()->subDays(5)->toDateString(),
            'total_amount' => '10000000.00',
            'status' => LocalPurchaseOrder::STATUS_OPEN,
            'created_by' => $this->financeUser->id,
        ]);

        $this->gr = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id,
            'gr_number' => 'GR-CHAR-001',
            'gr_date' => now()->subDays(3)->toDateString(),
            'qty' => 100,
            'uom' => 'PCS',
            'status' => LocalGoodsReceipt::STATUS_AVAILABLE,
            'created_by' => $this->financeUser->id,
        ]);
    }

    public function test_legacy_synonyms_deserialize_and_satisfy_model_predicates(): void
    {
        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-CHAR-001',
            'supplier_id' => $this->supplierUser->id,
            'invoice_number' => 'INV-CHAR-001',
            'invoice_date' => now()->toDateString(),
            'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id,
            'dpp_amount' => '1000000.00',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'total_amount' => '1110000.00',
            'status' => 'APPROVED',
            'revision_number' => 1,
            'submitted_by' => $this->supplierUser->id,
            'submitted_at' => now(),
        ]);

        $this->assertTrue($invoice->isReadyToPay());
        $this->assertSame('success', StatusHelper::localInvoiceTone($invoice->status));
        if (method_exists($invoice, 'statusEnum')) {
            $this->assertSame(LocalInvoiceStatus::READY_TO_PAY, $invoice->statusEnum());
        }

        $invoice->forceFill(['status' => 'COMPLETED'])->save();
        $invoice->refresh();

        $this->assertTrue($invoice->isPaid());
        $this->assertSame('success', StatusHelper::localInvoiceTone($invoice->status));
        if (method_exists($invoice, 'statusEnum')) {
            $this->assertSame(LocalInvoiceStatus::PAID, $invoice->statusEnum());
        }

        foreach (['SUBMITTED', 'UNDER_REVIEW'] as $legacyStatus) {
            $invoice->forceFill(['status' => $legacyStatus])->save();
            $invoice->refresh();
            $this->assertNotEmpty(StatusHelper::localInvoiceTone($invoice->status));
            $this->assertNotEmpty(StatusHelper::localInvoiceLabel($invoice->status));
        }
    }

    public function test_physical_receipt_transitions_waiting_to_under_verification_and_records_history(): void
    {
        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-CHAR-002',
            'supplier_id' => $this->supplierUser->id,
            'invoice_number' => 'INV-CHAR-002',
            'invoice_date' => now()->toDateString(),
            'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id,
            'dpp_amount' => '1000000.00',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'total_amount' => '1110000.00',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'revision_number' => 1,
            'submitted_by' => $this->supplierUser->id,
            'submitted_at' => now(),
        ]);

        LocalInvoiceReceipt::create([
            'local_invoice_id' => $invoice->id,
            'receipt_number' => 'TT-CHAR-002',
            'issued_at' => now(),
        ]);

        $signedQr = URL::signedRoute('receipts.verify-supplier', [
            'receipt' => 'TT-CHAR-002',
            'revision' => 1,
        ]);

        $updated = app(InvoicePhysicalReceiptService::class)->recordReceipt(
            $this->cashierUser,
            $invoice,
            'Dokumen fisik lengkap',
            $signedQr
        );

        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $updated->status);
        $this->assertNotNull($updated->cashier_received_at);
        $this->assertDatabaseHas('local_invoice_status_histories', [
            'local_invoice_id' => $invoice->id,
            'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'to_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'event' => 'physical_received',
            'actor_id' => $this->cashierUser->id,
        ]);
    }

    public function test_verification_decision_consumes_or_releases_goods_receipts_atomically(): void
    {
        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-CHAR-003',
            'supplier_id' => $this->supplierUser->id,
            'invoice_number' => 'INV-CHAR-003',
            'invoice_date' => now()->toDateString(),
            'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id,
            'dpp_amount' => '1000000.00',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'total_amount' => '1110000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
            'cashier_received_by' => $this->cashierUser->id,
            'cashier_received_at' => now(),
            'due_date' => now()->addDays(30)->toDateString(),
            'submitted_by' => $this->supplierUser->id,
            'submitted_at' => now(),
        ]);

        $this->gr->update([
            'status' => LocalGoodsReceipt::STATUS_RESERVED,
            'current_invoice_id' => $invoice->id,
            'reserved_at' => now(),
        ]);

        LocalInvoiceGoodsReceipt::create([
            'local_invoice_id' => $invoice->id,
            'local_purchase_order_id' => $this->po->id,
            'local_goods_receipt_id' => $this->gr->id,
            'state' => LocalInvoiceGoodsReceipt::STATE_RESERVED,
            'gr_number_snapshot' => $this->gr->gr_number,
            'gr_date_snapshot' => $this->gr->gr_date,
            'gr_qty_snapshot' => $this->gr->qty,
            'gr_uom_snapshot' => $this->gr->uom,
            'reserved_at' => now(),
        ]);

        LocalInvoiceVerification::create([
            'local_invoice_id' => $invoice->id,
            'revision_number' => 1,
            'invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'tax_invoice_check' => LocalInvoiceVerification::CHECK_OK,
            'po_check' => LocalInvoiceVerification::CHECK_OK,
            'delivery_note_check' => LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
            'gr_check' => LocalInvoiceVerification::CHECK_OK,
            'is_section_a_passed' => true,
            'section_a_verified_by' => $this->cashierUser->id,
            'is_section_b_passed' => true,
            'section_b_verified_by' => $this->cashierUser->id,
            'submitted_ppn' => '110000.00',
            'verified_ppn' => '110000.00',
            'verified_by' => $this->financeUser->id,
            'verified_at' => now(),
        ]);

        $approved = app(InvoiceVerificationService::class)->lockAndApprove(
            $invoice,
            $this->financeUser,
            'Verifikasi disetujui'
        );

        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $approved->status);
        $this->assertSame(LocalGoodsReceipt::STATUS_INVOICED, $this->gr->fresh()->status);
        $this->assertDatabaseHas('local_invoice_goods_receipts', [
            'local_invoice_id' => $invoice->id,
            'local_goods_receipt_id' => $this->gr->id,
            'state' => LocalInvoiceGoodsReceipt::STATE_CONSUMED,
        ]);

        // Also verify revision/rejection releases RESERVED GRs back to AVAILABLE
        $gr2 = LocalGoodsReceipt::create([
            'local_purchase_order_id' => $this->po->id,
            'gr_number' => 'GR-CHAR-002',
            'gr_date' => now()->subDays(2)->toDateString(),
            'qty' => 50,
            'uom' => 'PCS',
            'status' => LocalGoodsReceipt::STATUS_RESERVED,
            'created_by' => $this->financeUser->id,
        ]);

        $invoice2 = LocalInvoice::create([
            'submission_number' => 'SUB-CHAR-003B',
            'supplier_id' => $this->supplierUser->id,
            'invoice_number' => 'INV-CHAR-003B',
            'invoice_date' => now()->toDateString(),
            'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id,
            'dpp_amount' => '500000.00',
            'invoice_amount' => '500000.00',
            'tax_amount' => '55000.00',
            'total_amount' => '555000.00',
            'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1,
            'cashier_received_by' => $this->cashierUser->id,
            'cashier_received_at' => now(),
            'due_date' => now()->addDays(30)->toDateString(),
            'submitted_by' => $this->supplierUser->id,
            'submitted_at' => now(),
        ]);

        $gr2->update([
            'current_invoice_id' => $invoice2->id,
            'reserved_at' => now(),
        ]);

        LocalInvoiceGoodsReceipt::create([
            'local_invoice_id' => $invoice2->id,
            'local_purchase_order_id' => $this->po->id,
            'local_goods_receipt_id' => $gr2->id,
            'state' => LocalInvoiceGoodsReceipt::STATE_RESERVED,
            'gr_number_snapshot' => $gr2->gr_number,
            'gr_date_snapshot' => $gr2->gr_date,
            'gr_qty_snapshot' => $gr2->qty,
            'gr_uom_snapshot' => $gr2->uom,
            'reserved_at' => now(),
        ]);

        $rejected = app(InvoiceVerificationService::class)->reject(
            $invoice2,
            'Mohon perbaiki lampiran pajak',
            $this->financeUser
        );

        $this->assertSame(LocalInvoice::STATUS_REJECTED, $rejected->status);
        $this->assertSame(LocalGoodsReceipt::STATUS_AVAILABLE, $gr2->fresh()->status);
        $this->assertDatabaseHas('local_invoice_goods_receipts', [
            'local_invoice_id' => $invoice2->id,
            'local_goods_receipt_id' => $gr2->id,
            'state' => LocalInvoiceGoodsReceipt::STATE_RELEASED,
        ]);
    }

    public function test_illegal_resubmit_from_ready_to_pay_throws_business_rule_exception(): void
    {
        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-CHAR-004',
            'supplier_id' => $this->supplierUser->id,
            'invoice_number' => 'INV-CHAR-004',
            'invoice_date' => now()->toDateString(),
            'po_number' => $this->po->po_number,
            'local_purchase_order_id' => $this->po->id,
            'dpp_amount' => '1000000.00',
            'invoice_amount' => '1000000.00',
            'tax_amount' => '110000.00',
            'total_amount' => '1110000.00',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'revision_number' => 1,
            'submitted_by' => $this->supplierUser->id,
            'submitted_at' => now(),
        ]);

        $this->expectException(BusinessRuleException::class);
        app(InvoiceVerificationService::class)->lockAndApprove($invoice, $this->financeUser);
    }
}
