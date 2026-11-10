<?php

namespace App\Events;

use App\Enums\MaterialClaimStatus;
use App\Models\MaterialClaim;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MaterialClaimStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly MaterialClaim $claim,
        public readonly MaterialClaimStatus $fromStatus,
        public readonly MaterialClaimStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
