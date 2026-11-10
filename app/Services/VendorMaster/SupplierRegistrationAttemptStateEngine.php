<?php

namespace App\Services\VendorMaster;

use App\Enums\SupplierRegistrationAttemptStatus;
use App\Events\SupplierRegistrationAttemptStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplierRegistrationAttemptStateEngine
{
    /**
     * Atomically transition a SupplierRegistrationAttempt to a target status.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        SupplierRegistrationAttempt $attempt,
        SupplierRegistrationAttemptStatus|string $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
        array $attributes = [],
    ): SupplierRegistrationAttempt {
        $targetEnum = SupplierRegistrationAttemptStatus::tryFromWithLegacy($target);

        return DB::transaction(function () use ($attempt, $targetEnum, $actor, $reason, $metadata, $attributes): SupplierRegistrationAttempt {
            /** @var SupplierRegistrationAttempt $locked */
            $locked = SupplierRegistrationAttempt::query()
                ->whereKey($attempt->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sourceEnum = $locked->statusEnum();

            if (! $sourceEnum->canTransitionTo($targetEnum)) {
                $message = $metadata['invalid_transition_message']
                    ?? sprintf('Transisi status SupplierRegistrationAttempt dari %s ke %s tidak diizinkan.', $sourceEnum->value, $targetEnum->value);

                throw new InvalidStateTransitionException(
                    domain: 'SupplierRegistrationAttempt',
                    fromStatus: $sourceEnum->value,
                    toStatus: $targetEnum->value,
                    message: (string) $message,
                );
            }

            $locked->fill(array_merge($attributes, [
                'status' => $targetEnum->value,
            ]));
            $locked->save();

            SupplierRegistrationAttemptStatusTransitioned::dispatch(
                $locked,
                $sourceEnum,
                $targetEnum,
                $actor,
                $reason,
                $metadata,
            );

            return $locked;
        });
    }
}
