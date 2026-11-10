<?php

namespace App\Services\Procurement;

use App\Enums\QuotationStatus;
use App\Events\QuotationStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\Quotation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QuotationStateEngine
{
    /**
     * Atomically transition a Quotation to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        Quotation $model,
        QuotationStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): Quotation {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): Quotation {
            /** @var Quotation $quotation */
            $quotation = Quotation::withTrashed()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $quotation->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'Quotation',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $quotation->setAttribute($key, $value);
            }

            $quotation->status = $target->value;
            $quotation->save();

            $model->status = $target->value;
            foreach ($attributes as $key => $value) {
                $model->setAttribute($key, $value);
            }
            $model->syncOriginal();

            QuotationStatusTransitioned::dispatch(
                $quotation,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $quotation;
        });
    }
}
