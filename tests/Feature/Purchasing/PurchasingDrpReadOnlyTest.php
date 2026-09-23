<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\Supplier;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchasingDrpReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $purchasing;

    private User $finance;

    private User $supplier;

    private User $qc;

    protected function setUp(): void
    {
        parent::setUp();

        $this->purchasing = User::factory()->create(['role' => 'purchasing', 'is_active' => true]);
        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->supplier = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        $this->qc = User::factory()->create(['role' => 'qc', 'is_active' => true]);

        SupplierScope::create(['supplier_id' => $this->supplier->id, 'scope' => 'local']);
        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Testing Supplier Jaya',
            'category' => 'Raw Material',
            'is_pkp' => false,
            'payment_term_days' => 30,
        ]);
    }

    public function test_purchasing_can_access_drp_supplier_page(): void
    {
        PaymentBatch::create([
            'batch_number' => 'DRP-SUPP-TEST-01',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 15000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 15000000,
            'created_by' => $this->finance->id,
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.drp.supplier'));

        $response->assertOk();
        $response->assertSee('DRP Supplier (Daftar Rencana Pembayaran)');
        $response->assertSee('DRP-SUPP-TEST-01');
        // Read-only: Should NOT render form to create batch
        $response->assertDontSee('Buat Batch DRP Supplier Baru');
        $response->assertDontSee(route('finance.drp.supplier.create'));
    }

    public function test_purchasing_can_access_drp_ga_page(): void
    {
        PaymentBatch::create([
            'batch_number' => 'DRP-GA-TEST-01',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 5000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 5000000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.drp.ga'));

        $response->assertOk();
        $response->assertSee('DRP General Affairs (GA)');
        $response->assertSee('DRP-GA-TEST-01');
        $response->assertSee('Detail Batch');
    }

    public function test_purchasing_can_access_drp_paid_page_in_read_only_mode(): void
    {
        PaymentBatch::create([
            'batch_number' => 'DRP-PAID-TEST-01',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_PAID,
            'total_subtotal' => 20000000,
            'total_bank_fee' => 5000,
            'total_net_amount' => 19995000,
            'created_by' => $this->finance->id,
            'paid_at' => now(),
        ]);

        PaymentBatch::create([
            'batch_number' => 'DRP-UNPAID-TEST-01',
            'batch_type' => PaymentBatch::TYPE_GA,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 8000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 8000000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        // Default tab: unpaid
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.drp.paid.index'));
        $response->assertOk();
        $response->assertSee('DRP Paid (Monitoring Pelunasan)');
        $response->assertSee('DRP-UNPAID-TEST-01');
        $response->assertDontSee('DRP-PAID-TEST-01');
        // Read-only: MUST NOT contain "Tandai Paid" or mark paid modals
        $response->assertDontSee('Tandai Paid');
        $response->assertDontSee('markPaidModal');

        // Paid tab
        $paidResponse = $this->actingAs($this->purchasing)->get(route('purchasing.drp.paid.index', ['tab' => 'paid']));
        $paidResponse->assertOk();
        $paidResponse->assertSee('DRP-PAID-TEST-01');
        $paidResponse->assertDontSee('DRP-UNPAID-TEST-01');
    }

    public function test_purchasing_can_access_drp_show_without_mutation_controls(): void
    {
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-SHOW-TEST-01',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 10000000,
            'total_bank_fee' => 5000,
            'total_net_amount' => 9995000,
            'created_by' => $this->finance->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Testing Supplier Jaya',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Testing Supplier Jaya',
            'subtotal_amount' => 10000000,
            'bank_fee' => 5000,
            'net_payment_amount' => 9995000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-DRP-TEST-01',
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-TEST-DRP-01',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'invoice_amount' => 10000000,
            'tax_amount' => 0,
            'po_number' => 'PO-TEST-01',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_by' => $this->supplier->id,
            'submitted_at' => now(),
        ]);

        $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 10000000,
            'subtotal_amount' => 10000000,
            'tax_amount' => 0,
            'total_amount' => 10000000,
            'item_reference' => 'INV-TEST-DRP-01',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.drp.show', $batch));

        $response->assertOk();
        $response->assertSee('DRP-SHOW-TEST-01');
        $response->assertSee('PT Testing Supplier Jaya');
        $response->assertSee('INV-TEST-DRP-01');

        // Verify strictly READ-ONLY: No mutation forms/buttons
        $response->assertDontSee('Finalisasi Batch');
        $response->assertDontSee('Batalkan Batch');
        $response->assertDontSee('Ubah Fee');
        $response->assertDontSee('Generate Voucher');
        $response->assertDontSee('Remove');
        $response->assertDontSee(route('finance.drp.finalize', $batch));
        $response->assertDontSee(route('finance.drp.cancel', $batch));
    }

    public function test_purchasing_can_print_voucher(): void
    {
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-VCH-TEST-01',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_FINALIZED,
            'total_subtotal' => 5000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 5000000,
            'created_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Testing Supplier Jaya',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Testing Supplier Jaya',
            'subtotal_amount' => 5000000,
            'bank_fee' => 0,
            'net_payment_amount' => 5000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-VCH-TEST-01',
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-VCH-TEST-01',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'invoice_amount' => 5000000,
            'tax_amount' => 0,
            'po_number' => 'PO-VCH-01',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_by' => $this->supplier->id,
            'submitted_at' => now(),
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 5000000,
            'subtotal_amount' => 5000000,
            'tax_amount' => 0,
            'total_amount' => 5000000,
            'item_reference' => 'INV-VCH-TEST-01',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        $voucher = LocalInvoiceVoucher::create([
            'voucher_number' => 'VCH-TEST-PURCHASING-01',
            'voucher_date' => now()->toDateString(),
            'payment_method' => 'BANK',
            'local_invoice_id' => $invoice->id,
            'payment_batch_id' => $batch->id,
            'payment_group_id' => $group->id,
            'payment_item_id' => $item->id,
            'dpp_snapshot' => 5000000,
            'ppn_snapshot' => 0,
            'pph_snapshot' => 0,
            'net_payable_snapshot' => 5000000,
            'amount' => 5000000,
            'bank_name_snapshot' => 'BCA',
            'bank_account_snapshot' => '1234567890',
            'bank_account_holder_snapshot' => 'PT Testing Supplier Jaya',
            'supplier_name_snapshot' => 'PT Testing Supplier Jaya',
            'invoice_number_snapshot' => 'INV-VCH-TEST-01',
            'po_number_snapshot' => 'PO-VCH-01',
            'gr_references_snapshot' => 'GR-01',
            'terbilang_snapshot' => 'Lima Juta Rupiah',
            'status' => LocalInvoiceVoucher::STATUS_FINAL,
            'finalized_by' => $this->finance->id,
            'finalized_at' => now(),
        ]);

        $response = $this->actingAs($this->purchasing)->get(route('purchasing.vouchers.print', $voucher));

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_purchasing_is_forbidden_from_finance_mutating_actions(): void
    {
        $batch = PaymentBatch::create([
            'batch_number' => 'DRP-SEC-TEST-01',
            'batch_type' => PaymentBatch::TYPE_SUPPLIER,
            'status' => PaymentBatch::STATUS_DRAFT,
            'total_subtotal' => 1000000,
            'total_bank_fee' => 0,
            'total_net_amount' => 1000000,
            'created_by' => $this->finance->id,
        ]);

        $group = $batch->groups()->create([
            'payee_type' => 'supplier',
            'payee_id' => $this->supplier->id,
            'payee_name' => 'PT Testing Supplier Jaya',
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Testing Supplier Jaya',
            'subtotal_amount' => 1000000,
            'bank_fee' => 0,
            'net_payment_amount' => 1000000,
            'status' => PaymentGroup::STATUS_UNPAID,
        ]);

        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-SEC-TEST-01',
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-SEC-TEST-01',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'invoice_amount' => 1000000,
            'tax_amount' => 0,
            'po_number' => 'PO-SEC-01',
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_by' => $this->supplier->id,
            'submitted_at' => now(),
        ]);

        $item = $group->items()->create([
            'payable_type' => LocalInvoice::class,
            'payable_id' => $invoice->id,
            'amount' => 1000000,
            'subtotal_amount' => 1000000,
            'tax_amount' => 0,
            'total_amount' => 1000000,
            'item_reference' => 'INV-SEC-TEST-01',
            'status' => PaymentItem::STATUS_ACTIVE,
        ]);

        // Attempting finance batch creation
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.supplier.create'), ['invoice_ids' => [$invoice->id]])
            ->assertForbidden();

        // Attempting finance finalize
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.finalize', $batch))
            ->assertForbidden();

        // Attempting finance cancel
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.cancel', $batch), ['reason' => 'Unauthorized attempt'])
            ->assertForbidden();

        // Attempting finance mark paid
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.paid.mark-paid', $batch), [
                'transfer_reference' => 'TRF-TEST',
                'transfer_date' => now()->toDateString(),
            ])
            ->assertForbidden();

        // Attempting remove item
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.remove-item', $item), ['reason' => 'Unauthorized'])
            ->assertForbidden();

        // Attempting override fee
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.override-fee', $group), ['bank_fee' => 10000, 'reason' => 'Unauthorized'])
            ->assertForbidden();

        // Attempting assign voucher
        $this->actingAs($this->purchasing)
            ->post(route('finance.drp.assign-voucher', $group), [
                'voucher_number' => 'VCH-HACK',
                'voucher_date' => now()->toDateString(),
            ])
            ->assertForbidden();
    }

    public function test_non_purchasing_roles_cannot_access_purchasing_drp(): void
    {
        // Unauthenticated
        $this->get(route('purchasing.drp.supplier'))->assertRedirect(route('login'));
        $this->get(route('purchasing.drp.ga'))->assertRedirect(route('login'));
        $this->get(route('purchasing.drp.paid.index'))->assertRedirect(route('login'));

        // Supplier role -> 403
        $this->actingAs($this->supplier)->get(route('purchasing.drp.supplier'))->assertForbidden();
        $this->actingAs($this->supplier)->get(route('purchasing.drp.ga'))->assertForbidden();
        $this->actingAs($this->supplier)->get(route('purchasing.drp.paid.index'))->assertForbidden();

        // QC role -> 403
        $this->actingAs($this->qc)->get(route('purchasing.drp.supplier'))->assertForbidden();
        $this->actingAs($this->qc)->get(route('purchasing.drp.ga'))->assertForbidden();
        $this->actingAs($this->qc)->get(route('purchasing.drp.paid.index'))->assertForbidden();
    }

    public function test_sidebar_organization_for_purchasing(): void
    {
        $response = $this->actingAs($this->purchasing)->get(route('purchasing.dashboard'));

        $response->assertOk();
        $html = $response->getContent();

        // Must contain "Local Invoice" section
        $this->assertStringContainsString('Local Invoice', $html);

        // Must contain all 5 items
        $this->assertStringContainsString('Local Vendors', $html);
        $this->assertStringContainsString('Local PO &amp; GR', $html);
        $this->assertStringContainsString('DRP Supplier', $html);
        $this->assertStringContainsString('DRP GA', $html);
        $this->assertStringContainsString('DRP Paid', $html);

        // Check relative ordering of the sections and items in HTML using aria-label:
        // "Procurement" heading -> "Local Invoice" heading -> "Local Vendors" -> "Local PO & GR" -> "DRP Supplier" -> "DRP GA" -> "DRP Paid" -> "Collaboration" heading
        $procurementPos = strpos($html, 'Procurement</span>');
        $localInvoicePos = strpos($html, 'Local Invoice</span>');
        $localVendorsPos = strpos($html, 'aria-label="Local Vendors"');
        $localPoGrPos = strpos($html, 'aria-label="Local PO &amp; GR"');
        $drpSupplierPos = strpos($html, 'aria-label="DRP Supplier"');
        $drpGaPos = strpos($html, 'aria-label="DRP GA"');
        $drpPaidPos = strpos($html, 'aria-label="DRP Paid"');
        $collaborationPos = strpos($html, 'Collaboration</span>');

        $this->assertNotFalse($procurementPos);
        $this->assertNotFalse($localInvoicePos);
        $this->assertNotFalse($localVendorsPos);
        $this->assertNotFalse($localPoGrPos);
        $this->assertNotFalse($drpSupplierPos);
        $this->assertNotFalse($drpGaPos);
        $this->assertNotFalse($drpPaidPos);
        $this->assertNotFalse($collaborationPos);

        // Verify order sequence
        $this->assertTrue($procurementPos < $localInvoicePos, 'Local Invoice heading must be after Procurement');
        $this->assertTrue($localInvoicePos < $localVendorsPos, 'Local Vendors must be under Local Invoice');
        $this->assertTrue($localVendorsPos < $localPoGrPos, 'Local PO & GR must be after Local Vendors');
        $this->assertTrue($localPoGrPos < $drpSupplierPos, 'DRP Supplier must be after Local PO & GR');
        $this->assertTrue($drpSupplierPos < $drpGaPos, 'DRP GA must be after DRP Supplier');
        $this->assertTrue($drpGaPos < $drpPaidPos, 'DRP Paid must be after DRP GA');
        $this->assertTrue($drpPaidPos < $collaborationPos, 'Local Invoice items must be before Collaboration');
    }
}
