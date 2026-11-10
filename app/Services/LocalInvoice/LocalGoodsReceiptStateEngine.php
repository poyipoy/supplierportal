<?php

namespace App\Services\LocalInvoice;

use App\Enums\LocalGoodsReceiptStatus;
use App\Events\LocalGoodsReceiptStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\LocalGoodsReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LocalGoodsReceiptStateEngine
{
    public function __construct(
        private LocalFinanceAuditService $audit
    ) {}

    /**
     * Atomically transition a LocalGoodsReceipt to a target state with row-level locking and audit logging.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        LocalGoodsReceipt $model,
        LocalGoodsReceiptStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): LocalGoodsReceipt {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): LocalGoodsReceipt {
            /** @var LocalGoodsReceipt $gr */
            $gr = LocalGoodsReceipt::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $gr->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'LocalGoodsReceipt',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            $before = $gr->toArray();

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $gr->setAttribute($key, $value);
            }

            $gr->status = $target->value;
            $gr->save();

            $auditActor = $metadata['audit_actor'] ?? $actor;
            if (isset($metadata['audit_action']) && $auditActor instanceof User) {
                $this->audit->record(
                    $gr,
                    (string) $metadata['audit_action'],
                    $auditActor,
                    $metadata['audit_before'] ?? $before,
                    $metadata['audit_after'] ?? $gr->fresh()->toArray(),
                    $metadata['audit_meta'] ?? []
                );
            }

            LocalGoodsReceiptStatusTransitioned::dispatch(
                $gr,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $gr;
        });
    }
}
