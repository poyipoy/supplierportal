<?php

namespace App\Services\VendorMaster;

use App\Enums\SupplierBankAccountStatus;
use App\Events\SupplierBankAccountStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\SupplierBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplierBankAccountStateEngine
{
    /**
     * Atomically transition a SupplierBankAccount to a target status.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        SupplierBankAccount $bankAccount,
        SupplierBankAccountStatus|string $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
        array $attributes = [],
    ): SupplierBankAccount {
        $targetEnum = SupplierBankAccountStatus::tryFromWithLegacy($target);

        return DB::transaction(function () use ($bankAccount, $targetEnum, $actor, $reason, $metadata, $attributes): SupplierBankAccount {
            /** @var SupplierBankAccount $locked */
            $locked = SupplierBankAccount::query()
                ->whereKey($bankAccount->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sourceEnum = $locked->statusEnum();

            if (! $sourceEnum->canTransitionTo($targetEnum)) {
                $message = $metadata['invalid_transition_message']
                    ?? sprintf('Transisi status SupplierBankAccount dari %s ke %s tidak diizinkan.', $sourceEnum->value, $targetEnum->value);

                throw new InvalidStateTransitionException(
                    domain: 'SupplierBankAccount',
                    fromStatus: $sourceEnum->value,
                    toStatus: $targetEnum->value,
                    message: (string) $message,
                );
            }

            $locked->fill(array_merge($attributes, [
                'status' => $targetEnum->value,
            ]));
            $locked->save();

            SupplierBankAccountStatusTransitioned::dispatch(
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
