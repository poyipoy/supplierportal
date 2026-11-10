<?php

namespace App\Services\Ga;

use App\Enums\GaClaimStatus;
use App\Events\GaClaimStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\GaClaim;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GaClaimStateEngine
{
    /**
     * Atomically transition a GaClaim to a target state with row-level locking and immutable status history.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function transition(
        GaClaim $model,
        GaClaimStatus $target,
        ?User $actor = null,
        ?string $reason = null,
        array $metadata = []
    ): GaClaim {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata): GaClaim {
            /** @var GaClaim $claim */
            $claim = GaClaim::whereKey($model->id)->lockForUpdate()->firstOrFail();

            $rawFromStatus = (string) ($claim->getRawOriginal('status') ?: $claim->status);
            $current = $claim->statusEnum();

            if (! $current->canTransitionTo($target)) {
                throw new InvalidStateTransitionException(
                    domain: 'GaClaim',
                    fromStatus: $current->value,
                    toStatus: $target->value,
                    message: (string) ($metadata['invalid_transition_message'] ?? '')
                );
            }

            /** @var array<string, mixed> $attributes */
            $attributes = $metadata['attributes'] ?? [];
            foreach ($attributes as $key => $value) {
                $claim->setAttribute($key, $value);
            }

            $claim->status = $target->value;
            $claim->save();

            $historyFromStatus = array_key_exists('from_status_override', $metadata)
                ? (string) $metadata['from_status_override']
                : ($rawFromStatus !== '' ? $rawFromStatus : $current->value);

            $event = (string) ($metadata['event'] ?? strtolower($target->value));
            $createdAt = $metadata['timestamp'] ?? now();
            $actorId = $actor?->id ?? $metadata['actor_id'] ?? $claim->submitted_by;

            $history = $claim->statusHistories()->create([
                'from_status' => $historyFromStatus,
                'to_status' => $target->value,
                'actor_id' => $actorId,
                'event' => $event,
                'notes' => $reason,
                'created_at' => $createdAt,
            ]);

            GaClaimStatusTransitioned::dispatch(
                $claim,
                $current,
                $target,
                $actor,
                $history,
                $reason,
                $metadata
            );

            return $claim;
        });
    }
}
