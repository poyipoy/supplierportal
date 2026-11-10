<?php

namespace App\Events;

use App\Enums\ShipmentStatus;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ShipmentStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly Shipment $shipment,
        public readonly ShipmentStatus $fromStatus,
        public readonly ShipmentStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
