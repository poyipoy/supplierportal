<?php

namespace App\Services\VendorMaster;

use App\Enums\SupplierChangeRequestStatus;
use App\Events\SupplierChangeRequestStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\SupplierChangeRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplierChangeRequestStateEngine
{
    /**
     * Atomically transition a SupplierChangeRequest to a target status.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        SupplierChangeRequest $changeRequest,
        SupplierChangeRequestStatus|string $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
        array $attributes = [],
    ): SupplierChangeRequest {
        $targetEnum = SupplierChangeRequestStatus::tryFromWithLegacy($target);

        return DB::transaction(function () use ($changeRequest, $targetEnum, $actor, $reason, $metadata, $attributes): SupplierChangeRequest {
            /** @var SupplierChangeRequest $locked */
            $locked = SupplierChangeRequest::query()
                ->whereKey($changeRequest->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sourceEnum = $locked->statusEnum();

            if (! $sourceEnum->canTransitionTo($targetEnum)) {
                $message = $metadata['invalid_transition_message']
                    ?? sprintf('Transisi status SupplierChangeRequest dari %s ke %s tidak diizinkan.', $sourceEnum->value, $targetEnum->value);

                throw new InvalidStateTransitionException(
                    domain: 'SupplierChangeRequest',
                    fromStatus: $sourceEnum->value,
                    toStatus: $targetEnum->value,
                    message: (string) $message,
                );
            }

            $locked->fill(array_merge($attributes, [
                'status' => $targetEnum->value,
            ]));
            $locked->save();

            SupplierChangeRequestStatusTransitioned::dispatch(
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
