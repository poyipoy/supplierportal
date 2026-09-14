<?php

namespace App\Services\Ga;

use App\Models\GaClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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
            throw new InvalidArgumentException('Only Finance or Admin can perform finance verification on GA claims.');
        }

        return DB::transaction(function () use ($claim, $financeActor, $approve, $reason) {
            /** @var GaClaim $clm */
            $clm = GaClaim::where('id', $claim->id)->lockForUpdate()->firstOrFail();

            if (! in_array($clm->status, [GaClaim::STATUS_BASIC_VERIFIED, GaClaim::STATUS_SUBMITTED, GaClaim::STATUS_UNDER_VERIFICATION], true)) {
                throw new RuntimeException("Cannot verify claim in status [{$clm->status}].");
            }

            if ($approve) {
                $clm->update([
                    'status' => GaClaim::STATUS_READY_TO_PAY,
                    'finance_verified_by' => $financeActor->id,
                    'finance_verified_at' => now(),
                    'ready_to_pay_at' => now(),
                ]);

                $clm->statusHistories()->create([
                    'from_status' => $clm->getOriginal('status'),
                    'to_status' => GaClaim::STATUS_READY_TO_PAY,
                    'actor_id' => $financeActor->id,
                    'event' => 'approved',
                    'notes' => 'Claim verified and marked Ready to Pay by Finance.',
                    'created_at' => now(),
                ]);
            } else {
                if (empty(trim((string) $reason))) {
                    throw new InvalidArgumentException('Reason is mandatory when requesting claim revision.');
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
}
