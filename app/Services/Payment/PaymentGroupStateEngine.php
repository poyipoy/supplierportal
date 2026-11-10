<?php

namespace App\Services\Payment;

use App\Enums\PaymentGroupStatus;
use App\Events\PaymentGroupStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PaymentGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PaymentGroupStateEngine
{
    /**
     * Atomically transition a PaymentGroup to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        PaymentGroup $model,
        PaymentGroupStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): PaymentGroup {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): PaymentGroup {
            /** @var PaymentGroup $group */
            $group = PaymentGroup::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $group->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'PaymentGroup',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $group->setAttribute($key, $value);
            }

            $group->status = $target->value;
            $group->save();

            PaymentGroupStatusTransitioned::dispatch(
                $group,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $group;
        });
    }
}
