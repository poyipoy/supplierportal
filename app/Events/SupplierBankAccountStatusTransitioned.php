<?php

namespace App\Events;

use App\Enums\SupplierBankAccountStatus;
use App\Models\SupplierBankAccount;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierBankAccountStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly SupplierBankAccount $bankAccount,
        public readonly SupplierBankAccountStatus $fromStatus,
        public readonly SupplierBankAccountStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}
}
