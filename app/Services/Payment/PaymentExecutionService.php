<?php

namespace App\Services\Payment;

use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceNotificationService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PaymentExecutionService
{
    public function __construct(
        private InvoiceNotificationService $notifications
    ) {}

    /**
     * Mark a payment group Paid.
     * Atomically transitions the group and its active payable items to PAID,
     * then recalculates the batch status (PARTIALLY_PAID or PAID).
     */
    public function markGroupPaid(PaymentGroup $group, array $data, User $actor): PaymentGroup
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only Finance or Admin can confirm payment.');
        }

        if (empty($data['transfer_reference'])) {
            throw new InvalidArgumentException('Bank transfer reference is required.');
        }

        if (empty($data['transfer_date'])) {
            throw new InvalidArgumentException('Transfer date is required.');
        }

        return DB::transaction(function () use ($group, $data, $actor) {
            /** @var PaymentGroup $grp */
            $grp = PaymentGroup::where('id', $group->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentBatch $batch */
            $batch = $grp->batch()->lockForUpdate()->firstOrFail();

            if (! in_array($batch->status, [PaymentBatch::STATUS_FINALIZED, PaymentBatch::STATUS_PARTIALLY_PAID], true)) {
                throw new RuntimeException("Cannot record payment: batch is in [{$batch->status}] status, expected FINALIZED or PARTIALLY_PAID.");
            }

            if ($grp->status === PaymentGroup::STATUS_PAID) {
                throw new RuntimeException('Payment group has already been marked as PAID.');
            }

            $now = now();

            $grp->update([
                'status' => PaymentGroup::STATUS_PAID,
                'transfer_reference' => trim((string) $data['transfer_reference']),
                'transfer_date' => $data['transfer_date'],
                'payment_notes' => $data['payment_notes'] ?? null,
                'paid_by' => $actor->id,
                'paid_at' => $now,
            ]);

            // Update all active payable items in this group
            $activeItems = $grp->activeItems;

            foreach ($activeItems as $item) {
                if ($item->payable_type === LocalInvoice::class) {
                    /** @var LocalInvoice $inv */
                    $inv = LocalInvoice::where('id', $item->payable_id)->lockForUpdate()->first();
                    if ($inv && $inv->status !== LocalInvoice::STATUS_PAID) {
                        $inv->update([
                            'status' => LocalInvoice::STATUS_PAID,
                            'paid_at' => $now,
                            'completed_at' => $now,
                        ]);

                        $history = $inv->statusHistories()->create([
                            'from_status' => LocalInvoice::STATUS_READY_TO_PAY,
                            'to_status' => LocalInvoice::STATUS_PAID,
                            'actor_id' => $actor->id,
                            'event' => 'paid',
                            'notes' => "Payment confirmed via transfer ref: {$data['transfer_reference']}",
                            'created_at' => $now,
                        ]);

                        $this->notifications->send($inv, $history);
                    }
                } elseif ($item->payable_type === GaClaim::class) {
                    /** @var GaClaim $clm */
                    $clm = GaClaim::where('id', $item->payable_id)->lockForUpdate()->first();
                    if ($clm && $clm->status !== GaClaim::STATUS_PAID) {
                        $clm->update([
                            'status' => GaClaim::STATUS_PAID,
                            'paid_at' => $now,
                        ]);

                        $clm->statusHistories()->create([
                            'from_status' => GaClaim::STATUS_READY_TO_PAY,
                            'to_status' => GaClaim::STATUS_PAID,
                            'actor_id' => $actor->id,
                            'event' => 'paid',
                            'notes' => "Payment confirmed via transfer ref: {$data['transfer_reference']}",
                            'created_at' => $now,
                        ]);
                    }
                }
            }

            // Recalculate batch status
            $unpaidCount = $batch->groups()
                ->where('status', '!=', PaymentGroup::STATUS_PAID)
                ->where('subtotal_amount', '>', 0)
                ->count();

            if ($unpaidCount === 0) {
                $batch->update([
                    'status' => PaymentBatch::STATUS_PAID,
                    'paid_at' => $now,
                ]);
            } else {
                $batch->update([
                    'status' => PaymentBatch::STATUS_PARTIALLY_PAID,
                ]);
            }

            return $grp->fresh(['items', 'batch']);
        });
    }
}
