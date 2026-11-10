<?php

namespace App\Services\Procurement;

use App\Enums\MaterialClaimStatus;
use App\Events\MaterialClaimStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\MaterialClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class MaterialClaimStateEngine
{
    /**
     * Atomically transition a MaterialClaim to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        MaterialClaim $model,
        MaterialClaimStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): MaterialClaim {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): MaterialClaim {
            /** @var MaterialClaim $claim */
            $claim = MaterialClaim::withTrashed()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $claim->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'MaterialClaim',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $claim->setAttribute($key, $value);
            }

            $claim->status = $target->value;
            $claim->save();

            $model->status = $target->value;
            foreach ($attributes as $key => $value) {
                $model->setAttribute($key, $value);
            }
            $model->syncOriginal();

            MaterialClaimStatusTransitioned::dispatch(
                $claim,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $claim;
        });
    }
}
