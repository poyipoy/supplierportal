<?php

namespace App\Events;

use App\Enums\PaymentBatchStatus;
use App\Models\PaymentBatch;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentBatchStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly PaymentBatch $batch,
        public readonly PaymentBatchStatus $fromStatus,
        public readonly PaymentBatchStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
