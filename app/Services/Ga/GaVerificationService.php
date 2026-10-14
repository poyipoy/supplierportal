<?php

namespace App\Services\Ga;

use App\Models\Employee;
use App\Models\GaClaim;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class GaVerificationService
{
    /**
     * Finance verifies GA claim supporting documents and payment eligibility.
     */
    public function financeVerify(GaClaim $claim, User $financeActor, bool $approve, ?string $reason = null): GaClaim
    {
        if (! $financeActor->isFinance() && ! $financeActor->isAdmin()) {
            throw new InvalidArgumentException(__('ga.validation.finance_role'));
        }

        if (! $approve && empty(trim((string) $reason))) {
            throw new InvalidArgumentException(__('ga.validation.revision_reason'));
        }

        return DB::transaction(function () use ($claim, $financeActor, $approve, $reason) {
            $employee = Employee::whereKey($claim->employee_id)->lockForUpdate()->firstOrFail();
            /** @var GaClaim $clm */
            $clm = GaClaim::where('id', $claim->id)->lockForUpdate()->firstOrFail();

            if ($clm->status !== GaClaim::STATUS_BASIC_VERIFIED) {
                throw new RuntimeException(__('ga.validation.basic_required'));
            }

            if ($approve) {
                $this->assertCompleteBank($employee);
                $clm->update([
                    'status' => GaClaim::STATUS_READY_TO_PAY,
                    'finance_verified_by' => $financeActor->id,
                    'finance_verified_at' => now(),
                    'ready_to_pay_at' => now(),
                    'approved_bank_snapshot' => $employee->bankDetails(),
                    'approved_bank_hash' => $employee->bankFingerprint(),
                ]);

                $clm->statusHistories()->create([
                    'from_status' => $clm->getOriginal('status'),
                    'to_status' => GaClaim::STATUS_READY_TO_PAY,
                    'actor_id' => $financeActor->id,
                    'event' => 'approved',
                    'notes' => __('ga.history.approved'),
                    'created_at' => now(),
                ]);
            } else {
                if (empty(trim((string) $reason))) {
                    throw new InvalidArgumentException(__('ga.validation.revision_reason'));
                }

                $clm->update([
                    'status' => GaClaim::STATUS_NEED_REVISION,
                    'revision_reason' => trim((string) $reason),
                ]);

                $clm->statusHistories()->create([
                    'from_status' => $clm->getOriginal('status'),
                    'to_status' => GaClaim::STATUS_NEED_REVISION,
                    'actor_id' => $financeActor->id,
                    'event' => 'revision_requested',
                    'notes' => trim((string) $reason),
                    'created_at' => now(),
                ]);
            }

            return $clm->fresh();
        });
    }

    public function assertBankSnapshot(GaClaim $claim, Employee $employee): array
    {
        $this->assertCompleteBank($employee);
        $snapshot = $claim->approved_bank_snapshot;
        $canonical = is_array($snapshot) ? array_replace(array_fill_keys(Employee::BANK_FIELDS, ''), array_intersect_key($snapshot, array_flip(Employee::BANK_FIELDS))) : [];
        if (! is_array($snapshot) || count($snapshot) !== count(Employee::BANK_FIELDS) || in_array('', $canonical, true) || ! is_string($claim->approved_bank_hash)
            || ! hash_equals($employee->bankFingerprint(), $claim->approved_bank_hash)
            || ! hash_equals(hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR)), $claim->approved_bank_hash)) {
            throw ValidationException::withMessages(['claim' => __('finance_controls.bank_snapshot')]);
        }

        return $canonical;
    }

    public function reverifyBank(GaClaim $claim, User $actor): GaClaim
    {
        Gate::forUser($actor)->authorize('reverifyBank', $claim);

        return DB::transaction(function () use ($claim, $actor) {
            $employee = Employee::whereKey($claim->employee_id)->lockForUpdate()->firstOrFail();
            $locked = GaClaim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('reverifyBank', $locked);
            $reserved = PaymentItem::where('payable_type', GaClaim::class)->where('payable_id', $locked->id)
                ->where('status', PaymentItem::STATUS_ACTIVE)
                ->whereHas('group', fn ($group) => $group->where('status', PaymentGroup::STATUS_UNPAID)
                    ->whereHas('batch', fn ($batch) => $batch->whereIn('status', PaymentBatch::ACTIVE_STATUSES)))
                ->exists();
            if ($reserved || $locked->approved_bank_snapshot !== null || $locked->approved_bank_hash !== null) {
                throw ValidationException::withMessages(['claim' => __('finance_controls.bank_reverify')]);
            }
            $this->assertCompleteBank($employee);
            $before = $locked->only(['approved_bank_snapshot', 'approved_bank_hash', 'finance_verified_by', 'finance_verified_at']);
            $locked->update(['approved_bank_snapshot' => $employee->bankDetails(), 'approved_bank_hash' => $employee->bankFingerprint(), 'finance_verified_by' => $actor->id, 'finance_verified_at' => now()]);
            app(LocalFinanceAuditService::class)->record($locked, 'ga_bank_reverified', $actor, $before, $locked->only(array_keys($before)));

            return $locked->fresh();
        });
    }

    private function assertCompleteBank(Employee $employee): void
    {
        if (! $employee->is_active || in_array('', $employee->bankDetails(), true)) {
            throw ValidationException::withMessages(['claim' => __('finance_controls.bank_snapshot')]);
        }
    }
}
