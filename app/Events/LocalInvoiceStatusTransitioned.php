<?php

namespace App\Events;

use App\Enums\LocalInvoiceStatus;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceStatusHistory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocalInvoiceStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly LocalInvoice $invoice,
        public readonly LocalInvoiceStatus $fromStatus,
        public readonly LocalInvoiceStatus $toStatus,
        public readonly LocalInvoiceStatusHistory $history,
        public readonly ?int $actorId = null,
        public readonly array $metadata = [],
    ) {}
}
