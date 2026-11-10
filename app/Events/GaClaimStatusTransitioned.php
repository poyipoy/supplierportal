<?php

namespace App\Events;

use App\Enums\GaClaimStatus;
use App\Models\GaClaim;
use App\Models\GaClaimStatusHistory;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GaClaimStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly GaClaim $claim,
        public readonly GaClaimStatus $fromStatus,
        public readonly GaClaimStatus $toStatus,
        public readonly ?User $actor,
        public readonly GaClaimStatusHistory $history,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
