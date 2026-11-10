<?php

namespace App\Events;

use App\Enums\LocalPurchaseOrderStatus;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocalPurchaseOrderStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly LocalPurchaseOrder $purchaseOrder,
        public readonly LocalPurchaseOrderStatus $fromStatus,
        public readonly LocalPurchaseOrderStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
