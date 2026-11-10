<?php

namespace App\Services\LocalInvoice;

use App\Enums\LocalInvoiceStatus;
use App\Events\LocalInvoiceStatusTransitioned;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\LocalInvoice;
use App\Models\User;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LocalInvoiceStateEngine
{
    public function __construct(
        private readonly InvoiceNotificationService $notifications
    ) {}

    /**
     * Atomically transition a LocalInvoice to $target status under row lock,
     * recording immutable LocalInvoiceStatusHistory and dispatching afterCommit events.
     *
     * @param  array{
     *     event?: string,
     *     attributes?: array<string, mixed>,
     *     notification_data?: array<string, mixed>,
     *     send_notification?: bool,
     *     error_message?: string,
     *     from_status_override?: string,
     *     before_check?: Closure(LocalInvoice): void,
     *     after_transition?: Closure(LocalInvoice): void,
     *     timestamp?: Carbon
     * }  $metadata
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        LocalInvoice $model,
        LocalInvoiceStatus $target,
        ?User $actor,
        ?string $reason = null,
        array $metadata = []
    ): LocalInvoice {
        return DB::transaction(function () use ($model, $target, $actor, $reason, $metadata) {
            /** @var LocalInvoice $locked */
            $locked = LocalInvoice::whereKey($model->id)->lockForUpdate()->firstOrFail();

            if (isset($metadata['before_check']) && $metadata['before_check'] instanceof Closure) {
                ($metadata['before_check'])($locked);
            }

            $rawFromStatus = (string) ($locked->getRawOriginal('status') ?: $locked->status);
            $currentEnum = LocalInvoiceStatus::tryFromWithLegacy($rawFromStatus);

            if (! $currentEnum->canTransitionTo($target)) {
                $errorMessage = isset($metadata['error_message']) && is_string($metadata['error_message'])
                    ? str_replace(':status', $rawFromStatus, $metadata['error_message'])
                    : null;

                throw InvalidStateTransitionException::forLocalInvoice($rawFromStatus, $target, $errorMessage);
            }

            $now = $metadata['timestamp'] ?? now();
            $extraAttributes = $metadata['attributes'] ?? [];

            $locked->update(array_merge($extraAttributes, [
                'status' => $target->value,
            ]));

            $eventName = $metadata['event'] ?? $this->defaultEventName($currentEnum, $target);
            $historyFromStatus = $metadata['from_status_override'] ?? $rawFromStatus;

            $history = $locked->statusHistories()->create([
                'from_status' => $historyFromStatus,
                'to_status' => $target->value,
                'actor_id' => $actor?->id,
                'event' => $eventName,
                'notes' => $reason,
                'created_at' => $now,
            ]);

            if (isset($metadata['after_transition']) && $metadata['after_transition'] instanceof Closure) {
                ($metadata['after_transition'])($locked);
            }

            if (($metadata['send_notification'] ?? true) === true) {
                $this->notifications->send(
                    $locked,
                    $history,
                    $metadata['notification_data'] ?? []
                );
            }

            event(new LocalInvoiceStatusTransitioned(
                invoice: $locked,
                fromStatus: $currentEnum,
                toStatus: $target,
                history: $history,
                actorId: $actor?->id,
                metadata: [
                    'event' => $eventName,
                    'reason' => $reason,
                ],
            ));

            return $locked->fresh();
        });
    }

    private function defaultEventName(LocalInvoiceStatus $from, LocalInvoiceStatus $to): string
    {
        return match ($to) {
            LocalInvoiceStatus::WAITING_PHYSICAL_DOCUMENT => $from === LocalInvoiceStatus::NEED_REVISION || $from === LocalInvoiceStatus::CANCELLED
                ? 'resubmitted'
                : 'submitted',
            LocalInvoiceStatus::UNDER_VERIFICATION => 'physical_received',
            LocalInvoiceStatus::NEED_REVISION => 'revision_requested',
            LocalInvoiceStatus::READY_TO_PAY => 'approved',
            LocalInvoiceStatus::PAID => 'paid',
            LocalInvoiceStatus::REJECTED => 'rejected',
            LocalInvoiceStatus::CANCELLED => 'cancelled',
            LocalInvoiceStatus::EXPIRED => 'expired',
        };
    }
}
