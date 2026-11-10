<?php

namespace App\Events;

use App\Enums\SupplierRegistrationAttemptStatus;
use App\Models\SupplierRegistrationAttempt;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierRegistrationAttemptStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly SupplierRegistrationAttempt $attempt,
        public readonly SupplierRegistrationAttemptStatus $fromStatus,
        public readonly SupplierRegistrationAttemptStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}
}
