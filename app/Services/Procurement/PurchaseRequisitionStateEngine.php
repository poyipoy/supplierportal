<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseRequisitionStatus;
use App\Events\PurchaseRequisitionStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PurchaseRequisition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseRequisitionStateEngine
{
    /**
     * Atomically transition a PurchaseRequisition to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        PurchaseRequisition $model,
        PurchaseRequisitionStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): PurchaseRequisition {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): PurchaseRequisition {
            /** @var PurchaseRequisition $pr */
            $pr = PurchaseRequisition::withTrashed()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $pr->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'PurchaseRequisition',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $pr->setAttribute($key, $value);
            }

            $pr->status = $target->value;
            $pr->save();

            $model->status = $target->value;
            foreach ($attributes as $key => $value) {
                $model->setAttribute($key, $value);
            }
            $model->syncOriginal();

            PurchaseRequisitionStatusTransitioned::dispatch(
                $pr,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $pr;
        });
    }
}
