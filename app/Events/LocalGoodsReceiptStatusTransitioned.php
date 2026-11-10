<?php

namespace App\Events;

use App\Enums\LocalGoodsReceiptStatus;
use App\Models\LocalGoodsReceipt;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocalGoodsReceiptStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly LocalGoodsReceipt $goodsReceipt,
        public readonly LocalGoodsReceiptStatus $fromStatus,
        public readonly LocalGoodsReceiptStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
