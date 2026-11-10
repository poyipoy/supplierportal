<?php

namespace App\Services\LocalInvoice;

use App\Enums\LocalPurchaseOrderStatus;
use App\Events\LocalPurchaseOrderStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LocalPurchaseOrderStateEngine
{
    public function __construct(
        private LocalFinanceAuditService $audit
    ) {}

    /**
     * Atomically transition a LocalPurchaseOrder to a target state with row-level locking and audit logging.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        LocalPurchaseOrder $model,
        LocalPurchaseOrderStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): LocalPurchaseOrder {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): LocalPurchaseOrder {
            /** @var LocalPurchaseOrder $po */
            $po = LocalPurchaseOrder::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $po->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'LocalPurchaseOrder',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            $before = $po->toArray();

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $po->setAttribute($key, $value);
            }

            $po->status = $target->value;
            $po->save();

            $auditActor = $metadata['audit_actor'] ?? $actor;
            if (isset($metadata['audit_action']) && $auditActor instanceof User) {
                $this->audit->record(
                    $po,
                    (string) $metadata['audit_action'],
                    $auditActor,
                    $metadata['audit_before'] ?? $before,
                    $metadata['audit_after'] ?? $po->fresh()->toArray(),
                    $metadata['audit_meta'] ?? []
                );
            }

            LocalPurchaseOrderStatusTransitioned::dispatch(
                $po,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $po;
        });
    }
}
