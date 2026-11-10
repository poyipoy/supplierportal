<?php

namespace App\Services\SupplierAudit;

use App\Enums\SupplierAuditStatus;
use App\Events\SupplierAuditStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\SupplierAudit;
use App\Models\SupplierAuditStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SupplierAuditStateEngine
{
    /**
     * Atomically transition a SupplierAudit to a target status, recording
     * SupplierAuditStatusHistory and dispatching SupplierAuditStatusTransitioned.
     *
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        SupplierAudit $audit,
        SupplierAuditStatus|string $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = [],
        array $attributes = [],
    ): SupplierAudit {
        $targetEnum = SupplierAuditStatus::tryFromWithLegacy($target);

        return DB::transaction(function () use ($audit, $targetEnum, $actor, $reason, $metadata, $attributes): SupplierAudit {
            /** @var SupplierAudit $locked */
            $locked = SupplierAudit::query()
                ->whereKey($audit->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $sourceEnum = $locked->statusEnum();

            if (! $sourceEnum->canTransitionTo($targetEnum)) {
                $message = $metadata['invalid_transition_message']
                    ?? sprintf('Transisi status SupplierAudit dari %s ke %s tidak diizinkan.', $sourceEnum->value, $targetEnum->value);

                throw new InvalidStateTransitionException(
                    domain: 'SupplierAudit',
                    fromStatus: $sourceEnum->value,
                    toStatus: $targetEnum->value,
                    message: (string) $message,
                );
            }

            $locked->fill(array_merge($attributes, [
                'status' => $targetEnum->value,
            ]));
            $locked->save();

            $historyEvent = $metadata['history_event'] ?? match ($targetEnum) {
                SupplierAuditStatus::ASSIGNED => SupplierAuditStatusHistory::EVENT_ASSIGNED,
                SupplierAuditStatus::DRAFT => SupplierAuditStatusHistory::EVENT_DRAFT_STARTED,
                SupplierAuditStatus::SUBMITTED => SupplierAuditStatusHistory::EVENT_SUBMITTED,
                SupplierAuditStatus::REVISION_REQUESTED => SupplierAuditStatusHistory::EVENT_REVISION_REQUESTED,
                SupplierAuditStatus::RESULT_PUBLISHED => SupplierAuditStatusHistory::EVENT_RESULT_PUBLISHED,
                SupplierAuditStatus::CANCELLED => SupplierAuditStatusHistory::EVENT_CANCELLED,
            };

            $history = $locked->statusHistories()->create([
                'from_status' => $sourceEnum->value,
                'to_status' => $targetEnum->value,
                'event' => $historyEvent,
                'actor_id' => $actor?->id,
                'notes' => $reason,
            ]);

            $locked->setRelation('latestTransitionHistory', $history);

            SupplierAuditStatusTransitioned::dispatch(
                $locked,
                $sourceEnum,
                $targetEnum,
                $actor,
                $reason,
                $metadata,
            );

            return $locked;
        });
    }
}
