<?php

namespace App\Events;

use App\Enums\LocalInvoicePaymentStatus;
use App\Models\LocalInvoicePayment;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LocalInvoicePaymentStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly LocalInvoicePayment $payment,
        public readonly LocalInvoicePaymentStatus $fromStatus,
        public readonly LocalInvoicePaymentStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = []
    ) {}
}
