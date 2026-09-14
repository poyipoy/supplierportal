<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use App\Services\Payment\PaymentVoucherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class UnifiedPaymentEngineTest extends TestCase
{
    use RefreshDatabase;

    protected PaymentBatchService $batchService;
    protected PaymentExecutionService $executionService;
    protected PaymentVoucherService $voucherService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->batchService = app(PaymentBatchService::class);
        $this->executionService = app(PaymentExecutionService::class);
        $this->voucherService = app(PaymentVoucherService::class);
    }

    private function createSupplierWithBank(string $bankName, string $accountNo): User
    {
        $user = User::factory()->create(['role' => 'supplier', 'is_active' => true]);
        SupplierScope::create(['supplier_id' => $user->id, 'scope' => 'local']);

        Supplier::create([
            'user_id' => $user->id,
            'company_name' => 'PT Supplier '.$bankName,
            'vendor_category' => 'Barang',
            'is_pkp' => true,
            'payment_term_days' => 30,
        ]);

        SupplierBankAccount::create([
            'supplier_id' => $user->id,
            'bank_name' => $bankName,
            'account_number' => $accountNo,
            'account_holder_name' => 'PT Supplier '.$bankName,
            'status' => SupplierBankAccount::STATUS_VERIFIED,
            'activated_at' => now(),
        ]);

        return $user;
    }

    private function createReadyToPayInvoice(User $supplier, float $amount, string $invNumber): LocalInvoice
    {
        return LocalInvoice::create([
            'submission_number' => 'SUB-TEST-'.uniqid(),
            'supplier_id' => $supplier->id,
            'invoice_number' => $invNumber,
            'invoice_date' => '2026-09-10',
            'po_number' => 'PO-100',
            'invoice_amount' => $amount,
            'tax_amount' => 0,
            'status' => LocalInvoice::STATUS_READY_TO_PAY,
            'submitted_at' => now(),
            'ready_to_pay_at' => now(),
        ]);
    }

    public function test_supplier_drp_creation_grouping_and_fee_rules(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        // Supplier 1: BCA bank -> fee = 0
        $supplierBca = $this->createSupplierWithBank('BCA', '111111');
        $invBca1 = $this->createReadyToPayInvoice($supplierBca, 5000000, 'INV-BCA-1');
        $invBca2 = $this->createReadyToPayInvoice($supplierBca, 3000000, 'INV-BCA-2');

        // Supplier 2: Mandiri bank -> fee = 2.500
        $supplierMandiri = $this->createSupplierWithBank('Mandiri', '222222');
        $invMandiri = $this->createReadyToPayInvoice($supplierMandiri, 4000000, 'INV-MAN-1');

        $batch = $this->batchService->createSupplierBatch($finance, [
            $invBca1->id,
            $invBca2->id,
            $invMandiri->id,
        ], 'Batch Pembayaran Supplier September');

        $this->assertSame(PaymentBatch::STATUS_DRAFT, $batch->status);
        $this->assertSame(PaymentBatch::TYPE_SUPPLIER, $batch->batch_type);
        $this->assertCount(2, $batch->groups);

        // Verify BCA group: 2 invoices grouped, fee = 0, subtotal = 8,000,000, net = 8,000,000
        $bcaGroup = $batch->groups->where('account_number', '111111')->first();
        $this->assertNotNull($bcaGroup);
        $this->assertEquals(8000000.0, $bcaGroup->subtotal_amount);
        $this->assertEquals(0.0, $bcaGroup->bank_fee);
        $this->assertEquals(8000000.0, $bcaGroup->net_payment_amount);
        $this->assertCount(2, $bcaGroup->items);

        // Verify Mandiri group: 1 invoice, fee = 2,500, subtotal = 4,000,000, net = 3,997,500
        $mandiriGroup = $batch->groups->where('account_number', '222222')->first();
        $this->assertNotNull($mandiriGroup);
        $this->assertEquals(4000000.0, $mandiriGroup->subtotal_amount);
        $this->assertEquals(2500.0, $mandiriGroup->bank_fee);
        $this->assertEquals(3997500.0, $mandiriGroup->net_payment_amount);

        // Total batch: subtotal = 12,000,000, fee = 2,500, net = 11,997,500
        $this->assertEquals(12000000.0, $batch->total_subtotal);
        $this->assertEquals(2500.0, $batch->total_bank_fee);
        $this->assertEquals(11997500.0, $batch->total_net_amount);
    }

    public function test_double_payment_protection_prevents_adding_invoice_to_multiple_active_drps(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $supplier = $this->createSupplierWithBank('BCA', '333333');
        $inv = $this->createReadyToPayInvoice($supplier, 2000000, 'INV-DUP-1');

        // Create Batch 1 with this invoice
        $this->batchService->createSupplierBatch($finance, [$inv->id]);

        // Attempt to add same invoice to Batch 2 must fail!
        $this->expectException(RuntimeException::class);
        $this->batchService->createSupplierBatch($finance, [$inv->id]);
    }

    public function test_item_removal_from_draft_drp_restores_invoice_to_candidate_pool(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $supplier = $this->createSupplierWithBank('BCA', '444444');
        $inv1 = $this->createReadyToPayInvoice($supplier, 3000000, 'INV-REM-1');
        $inv2 = $this->createReadyToPayInvoice($supplier, 2000000, 'INV-REM-2');

        $batch = $this->batchService->createSupplierBatch($finance, [$inv1->id, $inv2->id]);
        $group = $batch->groups->first();
        $item1 = $group->items->where('payable_id', $inv1->id)->first();

        // Remove item 1
        $this->batchService->removeItem($item1, $finance, 'Salah pilih invoice.');

        $group->refresh();
        $batch->refresh();

        // Subtotal reduced from 5,000,000 to 2,000,000
        $this->assertEquals(2000000.0, $group->subtotal_amount);
        $this->assertEquals(2000000.0, $batch->total_subtotal);

        // Invoice 1 can now be added to another new DRP!
        $newBatch = $this->batchService->createSupplierBatch($finance, [$inv1->id]);
        $this->assertNotNull($newBatch);
    }

    public function test_ga_drp_always_has_zero_bank_fee_regardless_of_bank(): void
    {
        $gaUser = User::factory()->create(['role' => 'ga']);

        // Employee with non-BCA bank (Mandiri)
        $employee = Employee::create([
            'name' => 'Ahmad Dahlan',
            'department' => 'GA',
            'bank_name' => 'Mandiri',
            'account_number' => '888888',
            'account_holder_name' => 'Ahmad Dahlan',
            'is_active' => true,
        ]);

        $claim = GaClaim::create([
            'claim_number' => 'CLM-TEST-'.uniqid(),
            'employee_id' => $employee->id,
            'claim_type' => GaClaim::TYPE_UPD_GA,
            'claim_date' => '2026-09-10',
            'amount' => 1200000,
            'status' => GaClaim::STATUS_READY_TO_PAY,
            'submitted_by' => $gaUser->id,
            'submitted_at' => now(),
            'ready_to_pay_at' => now(),
        ]);

        $batch = $this->batchService->createGaBatch($gaUser, [$claim->id]);

        $this->assertSame(PaymentBatch::TYPE_GA, $batch->batch_type);
        $group = $batch->groups->first();

        // GA bank fee is 0.0 despite destination bank being Mandiri!
        $this->assertEquals(0.0, $group->bank_fee);
        $this->assertEquals(1200000.0, $group->net_payment_amount);
    }

    public function test_mark_payment_group_paid_and_partial_batch_state(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);

        // 2 suppliers in different groups
        $supplier1 = $this->createSupplierWithBank('BCA', '555555');
        $inv1 = $this->createReadyToPayInvoice($supplier1, 1000000, 'INV-PARTIAL-1');

        $supplier2 = $this->createSupplierWithBank('BCA', '666666');
        $inv2 = $this->createReadyToPayInvoice($supplier2, 2000000, 'INV-PARTIAL-2');

        $batch = $this->batchService->createSupplierBatch($finance, [$inv1->id, $inv2->id]);

        // Finalize DRP
        $finalizedBatch = $this->batchService->finalizeBatch($batch, $finance);
        $this->assertSame(PaymentBatch::STATUS_FINALIZED, $finalizedBatch->status);

        $group1 = $finalizedBatch->groups->where('account_number', '555555')->first();
        $group2 = $finalizedBatch->groups->where('account_number', '666666')->first();

        // 1. Pay Group 1
        $this->executionService->markGroupPaid($group1, [
            'transfer_reference' => 'TRF-BCA-001',
            'transfer_date' => '2026-09-11',
            'payment_notes' => 'Paid on time',
        ], $finance);

        $group1->refresh();
        $inv1->refresh();
        $finalizedBatch->refresh();

        $this->assertSame(PaymentGroup::STATUS_PAID, $group1->status);
        $this->assertSame(LocalInvoice::STATUS_PAID, $inv1->status);
        $this->assertTrue($inv1->isPaid());
        $this->assertSame(PaymentBatch::STATUS_PARTIALLY_PAID, $finalizedBatch->status);

        // 2. Pay Group 2
        $this->executionService->markGroupPaid($group2, [
            'transfer_reference' => 'TRF-BCA-002',
            'transfer_date' => '2026-09-11',
        ], $finance);

        $group2->refresh();
        $inv2->refresh();
        $finalizedBatch->refresh();

        $this->assertSame(PaymentGroup::STATUS_PAID, $group2->status);
        $this->assertSame(LocalInvoice::STATUS_PAID, $inv2->status);
        $this->assertSame(PaymentBatch::STATUS_PAID, $finalizedBatch->status);
        $this->assertNotNull($finalizedBatch->paid_at);
    }
}
