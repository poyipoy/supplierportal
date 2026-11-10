<?php

namespace App\Services\Procurement;

use App\Enums\ShipmentStatus;
use App\Events\ShipmentStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ShipmentStateEngine
{
    /**
     * Atomically transition a Shipment to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        Shipment $model,
        ShipmentStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): Shipment {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): Shipment {
            /** @var Shipment $shipment */
            $shipment = Shipment::withTrashed()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $shipment->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'Shipment',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $shipment->setAttribute($key, $value);
            }

            $shipment->status = $target->value;
            $shipment->save();

            $model->status = $target->value;
            foreach ($attributes as $key => $value) {
                $model->setAttribute($key, $value);
            }
            $model->syncOriginal();

            ShipmentStatusTransitioned::dispatch(
                $shipment,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $shipment;
        });
    }
}
