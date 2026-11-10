<?php

namespace App\Events;

use App\Enums\SupplierChangeRequestStatus;
use App\Models\SupplierChangeRequest;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierChangeRequestStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly SupplierChangeRequest $changeRequest,
        public readonly SupplierChangeRequestStatus $fromStatus,
        public readonly SupplierChangeRequestStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}
}
