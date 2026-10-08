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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegacyGaDraftCleanupTest extends TestCase
{
    use RefreshDatabase;

    private function batch(string $type = PaymentBatch::TYPE_GA, string $status = PaymentBatch::STATUS_DRAFT): PaymentBatch
    {
        return PaymentBatch::create(['batch_number' => 'DRP-CLEANUP-'.uniqid(), 'batch_type' => $type, 'status' => $status,
            'created_by' => User::factory()->create(['role' => 'finance'])->id]);
    }

    private function group(PaymentBatch $batch): PaymentGroup
    {
        return $batch->groups()->create(['payee_type' => 'employee', 'payee_id' => 1, 'payee_name' => 'Employee',
            'bank_name' => 'Mandiri', 'account_number' => '123', 'account_holder_name' => 'Employee']);
    }

    public function test_preflight_does_not_delete_and_execution_requires_explicit_ids(): void
    {
        $batch = $this->batch();
        $this->artisan('payments:cleanup-legacy-ga-drafts')->expectsOutput('GA DRAFT targets: 1')->assertSuccessful();
        $this->artisan('payments:cleanup-legacy-ga-drafts', ['--execute' => true])->assertFailed();
        $this->assertDatabaseHas('payment_batches', ['id' => $batch->id]);
    }

    public function test_cleanup_deletes_only_selected_ga_drafts_and_releases_ready_claim(): void
    {
        $batch = $this->batch();
        $group = $this->group($batch);
        $employee = Employee::create(['name' => 'Employee', 'department' => 'GA', 'bank_name' => 'Mandiri', 'account_number' => '123', 'account_holder_name' => 'Employee']);
        $claim = GaClaim::create(['claim_number' => 'CLM-CLEANUP', 'employee_id' => $employee->id, 'claim_type' => GaClaim::TYPE_BUSINESS_TRAVEL,
            'claim_date' => '2026-10-07', 'amount' => '100.00', 'status' => GaClaim::STATUS_READY_TO_PAY, 'submitted_by' => $batch->created_by, 'submitted_at' => now()]);
        $item = $group->items()->create(['payable_type' => GaClaim::class, 'payable_id' => $claim->id, 'amount' => '100.00']);
        $protected = collect([PaymentBatch::STATUS_FINALIZED, PaymentBatch::STATUS_PARTIALLY_PAID, PaymentBatch::STATUS_PAID, PaymentBatch::STATUS_CANCELLED])
            ->map(fn ($status) => $this->batch(PaymentBatch::TYPE_GA, $status));
        $protected->push($this->batch(PaymentBatch::TYPE_SUPPLIER));
        $protected->push($this->batch()); // A new Finance draft not selected as legacy stays intact.

        $this->artisan('payments:cleanup-legacy-ga-drafts', ['--batch-id' => [$batch->id], '--execute' => true])->assertSuccessful();
        $this->assertDatabaseMissing('payment_batches', ['id' => $batch->id]);
        $this->assertDatabaseMissing('payment_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('payment_items', ['id' => $item->id]);
        foreach ($protected as $other) {
            $this->assertDatabaseHas('payment_batches', ['id' => $other->id]);
        }
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $claim->fresh()->status);
        $this->assertFalse(PaymentItem::where('payable_type', GaClaim::class)->where('payable_id', $claim->id)->exists());
        $this->assertTrue(GaClaim::eligibleForPaymentBatch()->whereKey($claim->id)->exists());
    }

    public function test_conflicting_voucher_or_execution_metadata_blocks_entire_cleanup(): void
    {
        foreach (['voucher_number', 'transfer_reference', 'paid_at'] as $field) {
            $safe = $this->batch();
            $conflicting = $this->batch();
            $group = $this->group($conflicting);
            $group->update([$field => $field === 'paid_at' ? now() : 'EXISTING-ARTIFACT']);
            $this->artisan('payments:cleanup-legacy-ga-drafts', ['--batch-id' => [$safe->id, $conflicting->id], '--execute' => true])->assertFailed();
            $this->assertDatabaseHas('payment_batches', ['id' => $safe->id]);
            $this->assertDatabaseHas('payment_batches', ['id' => $conflicting->id]);
            $this->assertDatabaseHas('payment_groups', ['id' => $group->id]);
        }
    }

    public function test_non_draft_or_supplier_selection_fails_without_deletion(): void
    {
        $safe = $this->batch();
        $supplier = $this->batch(PaymentBatch::TYPE_SUPPLIER);
        $this->artisan('payments:cleanup-legacy-ga-drafts', ['--batch-id' => [$safe->id, $supplier->id], '--execute' => true])->assertFailed();
        $this->assertDatabaseHas('payment_batches', ['id' => $safe->id]);
        $this->assertDatabaseHas('payment_batches', ['id' => $supplier->id]);
    }

    private function voucher(PaymentBatch $batch, PaymentGroup $group, PaymentItem $item): LocalInvoiceVoucher
    {
        $invoice = LocalInvoice::create(['submission_number' => 'SUB-CLEANUP-'.uniqid(),
            'supplier_id' => User::factory()->create(['role' => 'supplier'])->id, 'invoice_number' => 'INV-CLEANUP-'.uniqid(),
            'invoice_date' => '2026-10-07', 'po_number' => 'PO-CLEANUP', 'invoice_amount' => '100.00',
            'tax_amount' => '0.00', 'status' => LocalInvoice::STATUS_READY_TO_PAY, 'submitted_at' => now()]);

        return LocalInvoiceVoucher::create(['local_invoice_id' => $invoice->id, 'payment_batch_id' => $batch->id,
            'payment_group_id' => $group->id, 'payment_item_id' => $item->id, 'voucher_number' => 'VB-CLEANUP-'.uniqid(),
            'voucher_date' => '2026-10-07', 'payment_method' => 'BANK', 'supplier_name_snapshot' => 'Supplier',
            'bank_name_snapshot' => 'BCA', 'bank_account_snapshot' => '123', 'bank_account_holder_snapshot' => 'Supplier',
            'invoice_number_snapshot' => $invoice->invoice_number, 'po_number_snapshot' => 'PO-CLEANUP',
            'gr_references_snapshot' => 'GR-CLEANUP', 'dpp_snapshot' => '100.00', 'ppn_snapshot' => '0.00',
            'net_payable_snapshot' => '100.00', 'amount' => '100.00', 'terbilang_snapshot' => 'Seratus Rupiah',
            'finalized_by' => $batch->created_by, 'finalized_at' => now()]);
    }

    public function test_downstream_voucher_blocks_cleanup_without_deleting_children(): void
    {
        $batch = $this->batch();
        $group = $this->group($batch);
        $item = $group->items()->create(['payable_type' => GaClaim::class, 'payable_id' => 999, 'amount' => '100.00']);
        // A corrupted draft may have a downstream supplier voucher despite its GA label.
        $voucher = $this->voucher($batch, $group, $item);
        $this->artisan('payments:cleanup-legacy-ga-drafts', ['--batch-id' => [$batch->id], '--execute' => true])->assertFailed();
        $this->assertDatabaseHas('payment_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('payment_groups', ['id' => $group->id]);
        $this->assertDatabaseHas('payment_items', ['id' => $item->id]);
        $this->assertDatabaseHas('local_invoice_vouchers', ['id' => $voucher->id]);
    }

    public function test_downstream_payment_reference_blocks_cleanup_even_when_voucher_belongs_to_another_batch(): void
    {
        $batch = $this->batch();
        $group = $this->group($batch);
        $item = $group->items()->create(['payable_type' => GaClaim::class, 'payable_id' => 999, 'amount' => '100.00']);
        $supplierBatch = $this->batch(PaymentBatch::TYPE_SUPPLIER, PaymentBatch::STATUS_FINALIZED);
        $supplierGroup = $this->group($supplierBatch);
        $supplierItem = $supplierGroup->items()->create(['payable_type' => LocalInvoice::class, 'payable_id' => 999, 'amount' => '100.00']);
        $voucher = $this->voucher($supplierBatch, $supplierGroup, $supplierItem);
        // The FK graph permits mismatched item/voucher associations; cleanup must detect the item reference independently.
        $payment = LocalInvoicePayment::create(['local_invoice_id' => $voucher->local_invoice_id,
            'local_invoice_voucher_id' => $voucher->id, 'payment_item_id' => $item->id,
            'expected_amount' => '100.00', 'created_by' => $batch->created_by, 'updated_by' => $batch->created_by]);
        $this->artisan('payments:cleanup-legacy-ga-drafts', ['--batch-id' => [$batch->id], '--execute' => true])->assertFailed();
        $this->assertDatabaseHas('payment_batches', ['id' => $batch->id]);
        $this->assertDatabaseHas('payment_items', ['id' => $item->id]);
        $this->assertDatabaseHas('local_invoice_payments', ['id' => $payment->id]);
        $this->assertDatabaseHas('local_invoice_vouchers', ['id' => $voucher->id]);
    }
}
