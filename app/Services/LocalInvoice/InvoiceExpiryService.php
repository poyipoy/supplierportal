<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InvoiceExpiryService
{
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
                throw new RuntimeException("Cannot record missed delivery: invoice is in [{$inv->status}] status.");
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
                    'notes' => 'Invoice expired after missing scheduled physical document delivery twice.',
                    'created_at' => now(),
                ]);
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
                    'notes' => 'Physical document delivery was missed on the scheduled date. Rescheduling required.',
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
        if ($newDate->dayOfWeek !== Carbon::WEDNESDAY) {
            throw new InvalidArgumentException('New delivery date must be on a Wednesday.');
        }

        return DB::transaction(function () use ($invoice, $newDate, $actor) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT) {
                throw new RuntimeException("Cannot reschedule: invoice is in [{$inv->status}] status.");
            }

            if ($inv->missed_delivery_count >= 2) {
                throw new RuntimeException('Cannot reschedule: invoice has exceeded maximum missed delivery attempts.');
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
                'notes' => "Physical delivery rescheduled to Wednesday, {$newDate->toDateString()}.",
                'created_at' => now(),
            ]);

            return $inv->fresh();
        });
    }
}
