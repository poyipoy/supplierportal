<?php

namespace App\Services\Payment;

use App\Enums\PaymentBatchStatus;
use App\Events\PaymentBatchStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PaymentBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentBatchStateEngine
{
    /**
     * Atomically transition a PaymentBatch to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        PaymentBatch $model,
        PaymentBatchStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): PaymentBatch {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): PaymentBatch {
            /** @var PaymentBatch $batch */
            $batch = PaymentBatch::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $batch->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'PaymentBatch',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $batch->setAttribute($key, $value);
            }

            $batch->status = $target->value;
            $batch->save();

            PaymentBatchStatusTransitioned::dispatch(
                $batch,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $batch;
        });
    }
}
