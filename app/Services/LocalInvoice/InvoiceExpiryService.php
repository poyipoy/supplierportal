<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\User;
use App\Support\StatusHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class InvoiceExpiryService
{
    public function __construct(
        private LocalGrReservationService $reservations,
        private ?DeliveryScheduleValidator $deliveryScheduleValidator = null
    ) {
        $this->deliveryScheduleValidator ??= app(DeliveryScheduleValidator::class);
    }

    /**
     * Record a missed delivery schedule.
     * First missed Wednesday allows rescheduling.
     * Second missed Wednesday transitions the invoice to EXPIRED.
     */
    public function recordMissedDelivery(LocalInvoice $invoice, ?User $actor = null): LocalInvoice
    {
        return DB::transaction(function () use ($invoice, $actor) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT) {
                throw new RuntimeException(__('local_invoice.validation.missed_status', [
                    'status' => StatusHelper::localInvoiceLabel($inv->status),
                ]));
            }

            $currentMissed = $inv->missed_delivery_count;

            if ($currentMissed >= 1) {
                // Second missed delivery -> EXPIRED (Terminal)
                $inv->update([
                    'status' => LocalInvoice::STATUS_EXPIRED,
                    'missed_delivery_count' => $currentMissed + 1,
                    'expired_at' => now(),
                ]);

                $inv->statusHistories()->create([
                    'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                    'to_status' => LocalInvoice::STATUS_EXPIRED,
                    'actor_id' => $actor ? $actor->id : $inv->supplier_id,
                    'event' => 'expired',
                    'notes' => __('local_invoice.history.expired'),
                    'created_at' => now(),
                ]);
                $this->reservations->release($inv, $actor);
            } else {
                // First missed delivery
                $inv->update([
                    'missed_delivery_count' => $currentMissed + 1,
                ]);

                $inv->statusHistories()->create([
                    'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                    'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                    'actor_id' => $actor ? $actor->id : $inv->supplier_id,
                    'event' => 'delivery_missed',
                    'notes' => __('local_invoice.history.delivery_missed'),
                    'created_at' => now(),
                ]);
            }

            return $inv->fresh();
        });
    }

    /**
     * Reschedule physical delivery to another Wednesday.
     */
    public function rescheduleDelivery(LocalInvoice $invoice, Carbon $newDate, User $actor): LocalInvoice
    {
        try {
            $this->deliveryScheduleValidator->assert($newDate);
        } catch (ValidationException $e) {
            throw new InvalidArgumentException($e->validator->errors()->first());
        }

        return DB::transaction(function () use ($invoice, $newDate, $actor) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT) {
                throw new RuntimeException(__('local_invoice.validation.reschedule_status', [
                    'status' => StatusHelper::localInvoiceLabel($inv->status),
                ]));
            }

            if ($inv->missed_delivery_count >= 2) {
                throw new RuntimeException(__('local_invoice.validation.reschedule_limit'));
            }

            $inv->update([
                'scheduled_physical_delivery_date' => $newDate->toDateString(),
                'rescheduled_at' => now(),
                'rescheduled_by' => $actor->id,
            ]);

            $inv->statusHistories()->create([
                'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'to_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'actor_id' => $actor->id,
                'event' => 'rescheduled',
                'notes' => __('local_invoice.history.rescheduled', ['date' => $newDate->toDateString()]),
                'created_at' => now(),
            ]);

            return $inv->fresh();
        });
    }
}
