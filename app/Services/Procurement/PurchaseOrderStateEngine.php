<?php

namespace App\Services\Procurement;

use App\Enums\PurchaseOrderStatus;
use App\Events\PurchaseOrderStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PurchaseOrderStateEngine
{
    /**
     * Atomically transition a PurchaseOrder to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        PurchaseOrder $model,
        PurchaseOrderStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): PurchaseOrder {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::withTrashed()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $po->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'PurchaseOrder',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $po->setAttribute($key, $value);
            }

            $po->status = $target->value;
            $po->save();

            $model->status = $target->value;
            foreach ($attributes as $key => $value) {
                $model->setAttribute($key, $value);
            }
            $model->syncOriginal();

            PurchaseOrderStatusTransitioned::dispatch(
                $po,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $po;
        });
    }
}
