<?php

namespace App\Services\Procurement;

use App\Enums\QcInspectionStatus;
use App\Events\QcInspectionStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\QcInspection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class QcInspectionStateEngine
{
    /**
     * Atomically transition a QcInspection to a target state with row-level locking.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        QcInspection $model,
        QcInspectionStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): QcInspection {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): QcInspection {
            /** @var QcInspection $inspection */
            $inspection = QcInspection::withTrashed()->whereKey($model->id)->lockForUpdate()->firstOrFail();

            $current = $inspection->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'QcInspection',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $inspection->setAttribute($key, $value);
            }

            $inspection->status = $target->value;
            $inspection->save();

            $model->status = $target->value;
            foreach ($attributes as $key => $value) {
                $model->setAttribute($key, $value);
            }
            $model->syncOriginal();

            QcInspectionStatusTransitioned::dispatch(
                $inspection,
                $current,
                $target,
                $actor,
                $reason,
                $metadata
            );

            return $inspection;
        });
    }
}
