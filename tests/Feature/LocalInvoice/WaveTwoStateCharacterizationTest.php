<?php

namespace Tests\Feature\LocalInvoice;

use App\Exceptions\BusinessRuleException;
use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\Supplier;
use App\Models\SupplierBankAccount;
use App\Models\SupplierScope;
use App\Models\User;
use App\Services\Ga\GaClaimService;
use App\Services\Ga\GaVerificationService;
use App\Services\LocalInvoice\LocalGrReservationService;
use App\Services\LocalInvoice\LocalProcurementMasterService;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use App\Support\StatusHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WaveTwoStateCharacterizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $supplier;

    protected User $finance;

    protected User $gaMaker;

    protected User $gaChecker;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');

        $this->supplier = User::factory()->create([
            'role' => 'supplier',
            'is_active' => true,
            'account_status' => User::ACCOUNT_STATUS_ACTIVE,
        ]);

        Supplier::create([
            'user_id' => $this->supplier->id,
            'company_name' => 'PT Wave Two Supplier',
            'country' => 'Indonesia',
            'city' => 'Bekasi',
            'npwp' => '01.234.567.8-999.000',
            'is_pkp' => true,
            'vendor_category' => 'Barang',
            'payment_term_days' => 30,
        ]);

        SupplierScope::create([
            'supplier_id' => $this->supplier->id,
            'scope' => 'local',
        ]);

        SupplierBankAccount::create([
            'supplier_id' => $this->supplier->id,
            'bank_name' => 'BCA',
            'account_number' => '1234567890',
            'account_holder_name' => 'PT Wave Two Supplier',
            'is_active' => true,
        ]);

        $this->finance = User::factory()->create(['role' => 'finance', 'is_active' => true]);
        $this->gaMaker = User::factory()->create(['role' => 'ga', 'is_active' => true]);
        $this->gaChecker = User::factory()->create(['role' => 'ga', 'is_active' => true]);
    }

    public function test_local_goods_receipt_lifecycle_transitions_and_audit(): void
    {
        $masterService = app(LocalProcurementMasterService::class);
        $reservationService = app(LocalGrReservationService::class);

        $po = $masterService->createPurchaseOrder($this->finance, [
            'po_number' => 'PO-W2-0001',
            'supplier_id' => $this->supplier->id,
            'po_date' => '2026-09-01',
            'total_amount' => '10000000.00',
            'description' => 'Wave 2 PO',
        ]);

        $gr = $masterService->createGoodsReceipt($this->finance, $po, [
            'gr_number' => 'GR-W2-0001',
            'gr_date' => '2026-09-02',
            'qty' => '10.000',
            'uom' => 'pcs',
            'description' => 'Wave 2 GR',
        ]);

        $this->assertSame(LocalGoodsReceipt::STATUS_AVAILABLE, $gr->status);
        $this->assertSame('success', StatusHelper::localFinanceTone($gr->status));

        // Cancel AVAILABLE -> CANCELLED and restore CANCELLED -> AVAILABLE
        $cancelledGr = $masterService->cancelGoodsReceipt($this->finance, $gr);
        $this->assertSame(LocalGoodsReceipt::STATUS_CANCELLED, $cancelledGr->status);
        $this->assertSame('error', StatusHelper::localFinanceTone($cancelledGr->status));

        $restoredGr = $masterService->restoreGoodsReceipt($this->finance, $cancelledGr, [
            'reason' => 'Valid physical delivery verified',
            'business_document_valid' => true,
        ]);
        $this->assertSame(LocalGoodsReceipt::STATUS_AVAILABLE, $restoredGr->status);

        // Reserve AVAILABLE -> RESERVED, Release RESERVED -> AVAILABLE, Reserve -> Consume RESERVED -> INVOICED
        $invoice = LocalInvoice::create([
            'submission_number' => 'SUB-W2-0001',
            'supplier_id' => $this->supplier->id,
            'local_purchase_order_id' => $po->id,
            'po_source' => 'INTERNAL',
            'po_number' => $po->po_number,
            'internal_po_reference' => $po->po_number,
            'internal_gr_reference' => $restoredGr->gr_number,
            'invoice_number' => 'INV-W2-0001',
            'tax_invoice_number' => '010.000-26.12345678',
            'invoice_date' => '2026-09-05',
            'invoice_amount' => 5000000,
            'tax_type' => 'PPN_11',
            'tax_amount' => 550000,
            'total_amount' => 5550000,
            'scheduled_delivery_date' => '2026-09-16',
            'status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
            'revision_number' => 1,
            'submitted_at' => now(),
        ]);

        $reservationService->reserve($this->supplier, $invoice, $po->id, [$restoredGr->id]);
        $this->assertSame(LocalGoodsReceipt::STATUS_RESERVED, $restoredGr->fresh()->status);
        $this->assertSame('warning', StatusHelper::localFinanceTone($restoredGr->fresh()->status));

        $reservationService->release($invoice, $this->finance);
        $this->assertSame(LocalGoodsReceipt::STATUS_AVAILABLE, $restoredGr->fresh()->status);

        $reservationService->reserve($this->supplier, $invoice, $po->id, [$restoredGr->id]);
        $reservationService->consume($invoice, $this->finance);
        $this->assertSame(LocalGoodsReceipt::STATUS_INVOICED, $restoredGr->fresh()->status);
        $this->assertSame('info', StatusHelper::localFinanceTone($restoredGr->fresh()->status));
    }

    public function test_ga_claim_and_payment_batch_lifecycle_transitions(): void
    {
        $claimService = app(GaClaimService::class);
        $verificationService = app(GaVerificationService::class);
        $batchService = app(PaymentBatchService::class);
        $executionService = app(PaymentExecutionService::class);
        $financeChecker = User::factory()->create(['role' => 'finance', 'is_active' => true]);

        $emp1 = Employee::create([
            'name' => 'Emp One',
            'department' => 'GA',
            'bank_name' => 'BCA',
            'account_number' => '1111111111',
            'account_holder_name' => 'Emp One',
            'is_active' => true,
        ]);

        $emp2 = Employee::create([
            'name' => 'Emp Two',
            'department' => 'GA',
            'bank_name' => 'MANDIRI',
            'account_number' => '2222222222',
            'account_holder_name' => 'Emp Two',
            'is_active' => true,
        ]);

        $claim1 = $claimService->submitClaim($this->gaMaker, [
            'employee_id' => $emp1->id,
            'claim_type' => GaClaim::TYPE_BUSINESS_TRAVEL,
            'claim_date' => '2026-09-10',
            'amount' => 300000,
        ]);

        $claim2 = $claimService->submitClaim($this->gaMaker, [
            'employee_id' => $emp2->id,
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM,
            'claim_date' => '2026-09-10',
            'amount' => 400000,
        ]);

        $this->assertSame(GaClaim::STATUS_SUBMITTED, $claim1->status);
        $claim1 = $claimService->basicVerify($claim1, $this->gaChecker, 'OK');
        $this->assertSame(GaClaim::STATUS_BASIC_VERIFIED, $claim1->status);

        // Request revision -> NEED_REVISION -> Resubmit -> SUBMITTED -> BASIC_VERIFIED -> READY_TO_PAY
        $claim1 = $verificationService->financeVerify($claim1, $this->finance, false, 'Mohon lampirkan detail');
        $this->assertSame(GaClaim::STATUS_NEED_REVISION, $claim1->status);

        $claim1 = $claimService->resubmitClaim($this->gaMaker, $claim1, [
            'claim_type' => GaClaim::TYPE_BUSINESS_TRAVEL,
            'claim_date' => '2026-09-10',
            'amount' => 300000,
        ]);
        $this->assertSame(GaClaim::STATUS_SUBMITTED, $claim1->status);

        $claim1 = $claimService->basicVerify($claim1, $this->gaChecker, 'OK 2');
        $claim1 = $verificationService->financeVerify($claim1, $this->finance, true);
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $claim1->status);
        $this->assertTrue($claim1->isReadyToPay());

        $claim2 = $claimService->basicVerify($claim2, $this->gaChecker, 'OK');
        $claim2 = $verificationService->financeVerify($claim2, $this->finance, true);
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $claim2->status);

        // Create and cancel a draft batch first
        $draftBatch = $batchService->createGaBatch($this->finance, [$claim1->id]);
        $this->assertTrue($draftBatch->isDraft());
        $cancelledBatch = $batchService->cancelBatch($draftBatch, $this->finance, 'Testing cancel');
        $this->assertTrue($cancelledBatch->isCancelled());

        // Create active batch with 2 groups -> Finalize -> Pay group 1 (PARTIALLY_PAID) -> Pay group 2 (PAID)
        $batch = $batchService->createGaBatch($this->finance, [$claim1->id, $claim2->id]);
        $this->assertTrue($batch->isDraft());

        $batch = $batchService->finalizeBatch($batch, $financeChecker);
        $this->assertTrue($batch->isFinalized());

        $groups = $batch->groups()->orderBy('id')->get();
        $this->assertCount(2, $groups);

        $executionService->markGroupPaid($groups[0], [
            'transfer_reference' => 'REF-GA-001',
            'transfer_date' => '2026-09-15',
        ], $this->finance);

        $batch->refresh();
        $this->assertTrue($batch->isPartiallyPaid());
        $this->assertTrue($claim1->fresh()->isPaid());

        $executionService->markGroupPaid($groups[1], [
            'transfer_reference' => 'REF-GA-002',
            'transfer_date' => '2026-09-15',
        ], $this->finance);

        $batch->refresh();
        $this->assertTrue($batch->isPaid());
        $this->assertTrue($claim2->fresh()->isPaid());

        // Illegal transition on finalized/paid batch throws BusinessRuleException
        $this->expectException(BusinessRuleException::class);
        $batchService->cancelBatch($batch, $this->finance, 'Illegal cancel');
    }
}
