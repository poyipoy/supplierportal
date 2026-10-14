<?php

namespace Tests\Feature\Finance;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\LocalFinanceAuditLog;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVerification;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\User;
use App\Services\Ga\GaClaimService;
use App\Services\Ga\GaVerificationService;
use App\Services\LocalInvoice\InvoiceVerificationService;
use App\Services\Payment\PaymentBatchService;
use App\Services\Payment\PaymentExecutionService;
use App\Services\Payment\PaymentVoucherService;
use App\Support\FinanceConfigurationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinancialSecurityControlsTest extends TestCase
{
    use RefreshDatabase;

    private function employee(): Employee
    {
        return Employee::create(['name' => 'Employee', 'department' => 'GA', 'bank_name' => 'BCA', 'account_number' => '001234', 'account_holder_name' => 'Employee', 'is_active' => true]);
    }

    private function claim(User $actor, Employee $employee, string $status = 'SUBMITTED'): GaClaim
    {
        return GaClaim::create(['claim_number' => 'CLM-'.uniqid(), 'employee_id' => $employee->id, 'submitted_by' => $actor->id,
            'claim_type' => GaClaim::TYPE_REIMBURSE_CLAIM, 'claim_date' => '2026-10-01', 'amount' => '1000.00', 'description' => 'Expense', 'status' => $status, 'submitted_at' => now()]);
    }

    private function reject(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('The protected action was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_ga_submitter_cannot_verify_own_claim_and_other_actor_can(): void
    {
        $maker = User::factory()->create(['role' => 'ga']);
        $checker = User::factory()->create(['role' => 'ga']);
        $claim = $this->claim($maker, $this->employee());
        $service = app(GaClaimService::class);
        $this->reject(fn () => $service->basicVerify($claim, $maker), 'maker_checker');
        $this->assertSame(GaClaim::STATUS_SUBMITTED, $claim->fresh()->status);
        $verified = $service->basicVerify($claim, $checker);
        $this->assertSame($checker->id, $verified->basic_verified_by);
    }

    public function test_admin_override_requires_reason_and_is_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $claim = $this->claim($admin, $this->employee());
        $service = app(GaClaimService::class);
        $this->reject(fn () => $service->basicVerify($claim, $admin), 'maker_checker');
        $service->basicVerify($claim, $admin, overrideReason: 'Approved emergency coverage');
        $audit = LocalFinanceAuditLog::where('action', 'maker_checker_override')->sole();
        $this->assertSame('Approved emergency coverage', $audit->metadata['reason']);
        $this->assertSame($admin->id, $audit->actor_id);
    }

    public function test_disabling_one_control_does_not_disable_the_other_controls(): void
    {
        config(['finance.maker_checker.ga_basic_verify' => false]);
        $actor = User::factory()->create(['role' => 'ga']);
        $claim = $this->claim($actor, $this->employee());
        $this->assertSame(GaClaim::STATUS_BASIC_VERIFIED, app(GaClaimService::class)->basicVerify($claim, $actor)->status);
        $this->assertTrue(config('finance.maker_checker.batch_finalize'));
    }

    public function test_bank_changes_are_frozen_for_verified_claims_and_other_changes_are_audited(): void
    {
        $actor = User::factory()->create(['role' => 'ga']);
        $employee = $this->employee();
        $claim = $this->claim($actor, $employee, GaClaim::STATUS_BASIC_VERIFIED);
        $this->reject(fn () => DB::transaction(function () use ($employee, $actor) {
            $employee->bankChangeActor = $actor;
            $employee->update(['account_number' => '999999']);
        }), 'bank_name');
        $this->assertSame('001234', $employee->fresh()->account_number);
        $claim->update(['status' => GaClaim::STATUS_CANCELLED]);
        DB::transaction(function () use ($employee, $actor) {
            $employee->bankChangeActor = $actor;
            $employee->update(['account_number' => '888888']);
        });
        $audit = LocalFinanceAuditLog::where('action', 'employee_bank_changed')->sole();
        $this->assertSame('001234', $audit->before_values['account_number']);
        $this->assertSame('888888', $audit->after_values['account_number']);
    }

    public function test_finance_bank_snapshot_is_required_and_mismatch_blocks_batch(): void
    {
        $ga = User::factory()->create(['role' => 'ga']);
        $finance = User::factory()->create(['role' => 'finance']);
        $employee = $this->employee();
        $claim = $this->claim($ga, $employee, GaClaim::STATUS_READY_TO_PAY);
        $batches = app(PaymentBatchService::class);
        $this->reject(fn () => $batches->createGaBatch($finance, [$claim->id]), 'claim');
        $this->assertSame(0, PaymentBatch::count());
        app(GaVerificationService::class)->reverifyBank($claim, $finance);
        // Simulate an out-of-band write, which model-level controls cannot intercept.
        DB::table('employees')->where('id', $employee->id)->update(['account_number' => '999999']);
        $this->reject(fn () => $batches->createGaBatch($finance, [$claim->id]), 'claim');
        $this->assertSame(0, PaymentBatch::count());
    }

    public function test_ga_amount_limit_is_exact_and_rejects_one_cent_over(): void
    {
        config(['finance.limits.ga_claim_max_amount' => '1000.00']);
        $guard = app(FinanceConfigurationGuard::class);
        $this->assertSame('1000.00', $guard->amount('1000.00', 'ga_claim_max_amount', 'amount'));
        $this->reject(fn () => $guard->amount('1000.01', 'ga_claim_max_amount', 'amount'), 'amount');
    }

    public function test_finance_can_approve_own_verification_and_new_records_require_actor_metadata(): void
    {
        $maker = User::factory()->create(['role' => 'finance']);
        $supplier = User::factory()->create(['role' => 'supplier']);
        $invoice = LocalInvoice::create(['supplier_id' => $supplier->id, 'submission_number' => 'SUB-SECURE', 'invoice_number' => 'INV-SECURE', 'po_number' => 'LEGACY-PO',
            'invoice_date' => '2026-10-01', 'invoice_amount' => '1000.00', 'tax_amount' => '0.00', 'revision_number' => 1, 'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'cashier_received_at' => now(), 'cashier_received_by' => $maker->id, 'submitted_at' => now()]);
        $verification = LocalInvoiceVerification::create(['local_invoice_id' => $invoice->id, 'revision_number' => $invoice->revision_number,
            'is_section_a_passed' => true, 'is_section_b_passed' => true, 'section_a_verified_by' => $maker->id, 'section_b_verified_by' => $maker->id]);
        $service = app(InvoiceVerificationService::class);
        $verification->update(['section_b_verified_by' => null]);
        $this->reject(fn () => $service->lockAndApprove($invoice, $maker), 'maker_checker');
        $this->assertSame(LocalInvoice::STATUS_UNDER_VERIFICATION, $invoice->fresh()->status);
        $verification->update(['section_b_verified_by' => $maker->id]);
        $this->actingAs($maker)->post(route('finance.invoices.approve-ready-to-pay', $invoice))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, $invoice->fresh()->status);
        $this->assertTrue($verification->fresh()->is_locked);
        $this->assertSame($maker->id, $verification->fresh()->verified_by);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'maker_checker_override')->count());
    }

    public function test_batch_maker_can_finalize_and_ga_fee_must_stay_zero(): void
    {
        $maker = User::factory()->create(['role' => 'finance']);
        $ga = User::factory()->create(['role' => 'ga']);
        $claim = $this->claim($ga, $this->employee(), GaClaim::STATUS_BASIC_VERIFIED);
        $claim = app(GaVerificationService::class)->financeVerify($claim, $maker, true);
        $service = app(PaymentBatchService::class);
        $batch = $service->createGaBatch($maker, [$claim->id]);
        $this->reject(fn () => $service->overrideGroupFee($batch->groups->first(), '0.01', 'Fee correction', $maker), 'bank_fee');
        $this->actingAs($maker)->post(route('finance.drp.finalize', $batch))
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(PaymentBatch::STATUS_FINALIZED, $batch->fresh()->status);
        $this->assertSame($maker->id, $batch->fresh()->finalized_by);
    }

    public function test_finalizer_can_assign_voucher_and_settle_with_recorded_actor_metadata(): void
    {
        $maker = User::factory()->create(['role' => 'finance']);
        $ga = User::factory()->create(['role' => 'ga']);
        $claim = $this->claim($ga, $this->employee(), GaClaim::STATUS_BASIC_VERIFIED);
        $claim = app(GaVerificationService::class)->financeVerify($claim, $maker, true);
        $batches = app(PaymentBatchService::class);
        $batch = $batches->finalizeBatch($batches->createGaBatch($maker, [$claim->id]), $maker);
        $group = $batch->groups->first();
        $voucher = app(PaymentVoucherService::class);
        $execution = app(PaymentExecutionService::class);
        $data = ['transfer_reference' => 'TRF-TEST', 'transfer_date' => '2026-10-01'];
        $batch->update(['finalized_by' => null]);
        $this->reject(fn () => $voucher->assignVoucher($group, 'VC-TEST', '2026-10-01', $maker), 'maker_checker');
        $this->reject(fn () => $execution->markGroupPaid($group, $data, $maker), 'maker_checker');
        $this->assertSame(GaClaim::STATUS_READY_TO_PAY, $claim->fresh()->status);
        $batch->update(['finalized_by' => $maker->id]);
        $voucher->assignVoucher($group, 'VC-TEST', '2026-10-01', $maker);
        $this->assertSame(PaymentGroup::STATUS_PAID, $execution->markGroupPaid($group, $data, $maker)->status);
        $this->assertSame(0, LocalFinanceAuditLog::where('action', 'maker_checker_override')->count());
    }

    public function test_unknown_historical_reviewers_are_allowed_and_recorded_for_audit(): void
    {
        $supplier = User::factory()->create(['role' => 'supplier']);
        $reviewer = User::factory()->create(['role' => 'finance']);
        $invoice = LocalInvoice::create(['supplier_id' => $supplier->id, 'submission_number' => 'SUB-HISTORICAL',
            'invoice_number' => 'INV-HISTORICAL', 'po_number' => 'LEGACY-PO', 'invoice_date' => '2026-10-01',
            'invoice_amount' => '1000.00', 'tax_amount' => '0.00', 'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
            'revision_number' => 1, 'cashier_received_at' => now(), 'submitted_at' => now()]);
        $verification = LocalInvoiceVerification::create(['local_invoice_id' => $invoice->id, 'revision_number' => 1,
            'is_section_a_passed' => true, 'is_section_b_passed' => true]);
        // The migration leaves existing verification rows unmarked; normal creates require actors.
        DB::table('local_invoice_verifications')->where('id', $verification->id)->update(['actor_tracking_required' => false]);
        $this->assertSame(LocalInvoice::STATUS_READY_TO_PAY, app(InvoiceVerificationService::class)->lockAndApprove($invoice, $reviewer)->status);
        $this->assertSame(2, LocalFinanceAuditLog::where('action', 'historical_actor_unknown')->where('actor_id', $reviewer->id)->count());
    }
}
