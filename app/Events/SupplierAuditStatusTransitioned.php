<?php

namespace App\Events;

use App\Enums\SupplierAuditStatus;
use App\Models\SupplierAudit;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupplierAuditStatusTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly SupplierAudit $audit,
        public readonly SupplierAuditStatus $fromStatus,
        public readonly SupplierAuditStatus $toStatus,
        public readonly ?User $actor = null,
        public readonly ?string $reason = null,
        public readonly array $metadata = [],
    ) {}
}
