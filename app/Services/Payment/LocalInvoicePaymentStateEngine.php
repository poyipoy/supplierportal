<?php

namespace App\Services\Payment;

use App\Enums\LocalInvoicePaymentStatus;
use App\Events\LocalInvoicePaymentStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\LocalInvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LocalInvoicePaymentStateEngine
{
    /**
     * Atomically transition a LocalInvoicePayment to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        LocalInvoicePayment $model,
        LocalInvoicePaymentStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): LocalInvoicePayment {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): LocalInvoicePayment {
            /** @var LocalInvoicePayment $payment */
            $payment = LocalInvoicePayment::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $payment->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'LocalInvoicePayment',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $payment->setAttribute($key, $value);
            }

            $payment->status = $target->value;
            $payment->save();

            LocalInvoicePaymentStatusTransitioned::dispatch(
                $payment,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $payment;
        });
    }
}
