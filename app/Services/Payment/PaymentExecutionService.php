<?php

namespace App\Services\Payment;

use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class PaymentExecutionService
{
    private ?LocalInvoiceVoucherService $voucherService;
    private ?LocalInvoicePaymentService $paymentService;

    public function __construct(
        private InvoiceNotificationService $notifications,
        ?LocalInvoiceVoucherService $voucherService = null,
        ?LocalInvoicePaymentService $paymentService = null
    ) {
        $this->voucherService = $voucherService ?? app(LocalInvoiceVoucherService::class);
        $this->paymentService = $paymentService ?? app(LocalInvoicePaymentService::class);
    }

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

            // New authoritative Local Supplier invoices settle through their
            // per-invoice Voucher Bayar/Payment Settlement. Legacy invoices
            // without a PO master link keep the historical group execution
            // path so existing DRP records remain payable and readable.
            $activeItems = $grp->activeItems()->get();

            if ($batch->batch_type === PaymentBatch::TYPE_SUPPLIER) {
                $hasAuthoritativeInvoice = $activeItems->contains(function (PaymentItem $item): bool {
                    if ($item->payable_type !== LocalInvoice::class) {
                        return false;
                    }

                    $invoice = LocalInvoice::whereKey($item->payable_id)->lockForUpdate()->first();

                    return $invoice?->getAttribute('local_purchase_order_id') !== null;
                });

                if ($hasAuthoritativeInvoice) {
                    throw new RuntimeException('Supplier payments must be recorded per invoice through its Voucher Bayar settlement.');
                }
            }

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
            foreach ($activeItems as $item) {
                if ($item->payable_type === LocalInvoice::class) {
                    /** @var LocalInvoice $inv */
                    $inv = LocalInvoice::where('id', $item->payable_id)->lockForUpdate()->first();
                    if ($inv && $inv->status !== LocalInvoice::STATUS_PAID) {
                        if (! $inv->isReadyToPay()) {
                            throw new RuntimeException("Invoice [{$inv->invoice_number}] is not in READY_TO_PAY status (current: {$inv->status}).");
                        }

                        $originalStatus = $inv->status;

                        $inv->update([
                            'status' => LocalInvoice::STATUS_PAID,
                            'paid_at' => $now,
                            'completed_at' => $now,
                        ]);

                        $history = $inv->statusHistories()->create([
                            'from_status' => $originalStatus,
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
                        if ($clm->status !== GaClaim::STATUS_READY_TO_PAY) {
                            throw new RuntimeException("Claim [{$clm->claim_number}] is not in READY_TO_PAY status (current: {$clm->status}).");
                        }

                        $originalStatus = $clm->status;

                        $clm->update([
                            'status' => GaClaim::STATUS_PAID,
                            'paid_at' => $now,
                        ]);

                        $clm->statusHistories()->create([
                            'from_status' => $originalStatus,
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
                ->whereNotIn('status', [PaymentGroup::STATUS_PAID, PaymentGroup::STATUS_CANCELLED])
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

    /**
     * Mark an entire DRP Payment Batch as Paid.
     * Settles all eligible unpaid groups/items atomically with row locking.
     */
    public function markEntireBatchPaid(PaymentBatch $batch, array $data, User $actor): PaymentBatch
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only Finance or Admin can confirm payment.');
        }

        $transferRef = trim((string) ($data['transfer_reference'] ?? ''));
        if ($transferRef === '') {
            throw new InvalidArgumentException('Bank transfer reference is required.');
        }

        $transferDate = $data['transfer_date'] ?? null;
        if (empty($transferDate)) {
            throw new InvalidArgumentException('Transfer date is required.');
        }

        return DB::transaction(function () use ($batch, $data, $actor) {
            /** @var PaymentBatch $lockedBatch */
            $lockedBatch = PaymentBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();

            if ($lockedBatch->status === PaymentBatch::STATUS_PAID) {
                throw new RuntimeException("DRP Batch [{$lockedBatch->batch_number}] has already been marked as PAID.");
            }

            if (! in_array($lockedBatch->status, [PaymentBatch::STATUS_FINALIZED, PaymentBatch::STATUS_PARTIALLY_PAID], true)) {
                throw new RuntimeException("Cannot mark batch as paid: batch is in [{$lockedBatch->status}] status, expected FINALIZED or PARTIALLY_PAID.");
            }

            if ($lockedBatch->batch_type === PaymentBatch::TYPE_GA) {
                $groups = $lockedBatch->groups()->lockForUpdate()->get();
                foreach ($groups as $group) {
                    if ($group->status !== PaymentGroup::STATUS_PAID && $group->status !== PaymentGroup::STATUS_CANCELLED) {
                        $this->markGroupPaid($group, $data, $actor);
                    }
                }
            } elseif ($lockedBatch->batch_type === PaymentBatch::TYPE_SUPPLIER) {
                $groups = $lockedBatch->groups()->lockForUpdate()->get();

                // Pre-validation: all active supplier invoice items must already
                // have a FINAL voucher before the batch can be marked paid.
                $unvoucheredCount = 0;
                foreach ($groups as $group) {
                    $items = $group->activeItems()->lockForUpdate()->get();
                    foreach ($items as $item) {
                        if ($item->payable_type === LocalInvoice::class) {
                            $voucher = LocalInvoiceVoucher::where('payment_item_id', $item->id)->first();
                            if (! $voucher || $voucher->status !== LocalInvoiceVoucher::STATUS_FINAL) {
                                $unvoucheredCount++;
                            }
                        }
                    }
                }

                if ($unvoucheredCount > 0) {
                    throw ValidationException::withMessages([
                        'batch' => "Terdapat {$unvoucheredCount} tagihan supplier yang belum diterbitkan Voucher Bayar. Harap generate seluruh Voucher Bayar pada Detail DRP sebelum menandai DRP ini lunas.",
                    ]);
                }

                // All vouchers verified — proceed with settlement
                foreach ($groups as $group) {
                    $items = $group->activeItems()->lockForUpdate()->get();
                    foreach ($items as $item) {
                        if ($item->payable_type === LocalInvoice::class) {
                            $voucher = LocalInvoiceVoucher::where('payment_item_id', $item->id)->lockForUpdate()->first();

                            // Settle voucher payment if not finalized yet
                            $existingPayment = LocalInvoicePayment::where('local_invoice_voucher_id', $voucher->id)->lockForUpdate()->first();
                            if (! $existingPayment) {
                                $transferAmount = (string) $voucher->amount;
                                $correctionReason = null;

                                if (isset($data['custom_amounts'][$item->id]) && filled($data['custom_amounts'][$item->id])) {
                                    $transferAmount = (string) $data['custom_amounts'][$item->id];
                                    if (bccomp($transferAmount, (string) $voucher->amount, 2) < 0) {
                                        $correctionReason = trim((string) ($data['correction_reasons'][$item->id] ?? ''));
                                        if ($correctionReason === '') {
                                            $correctionReason = 'Penyesuaian transfer kurang bayar saat pelunasan DRP';
                                        }
                                    }
                                }

                                $this->paymentService->recordPrimary($voucher, [
                                    'amount' => $transferAmount,
                                    'transfer_reference' => $data['transfer_reference'],
                                    'transfer_date' => $data['transfer_date'],
                                    'correction_reason' => $correctionReason,
                                    'notes' => $data['payment_notes'] ?? null,
                                ], $actor);
                            } elseif ($existingPayment->status === LocalInvoicePayment::STATUS_CORRECTION_REQUIRED) {
                                $remaining = bcsub((string) $existingPayment->expected_amount, (string) $existingPayment->actual_paid_total, 2);
                                $correctionAmount = $remaining;
                                if (isset($data['custom_amounts'][$item->id]) && filled($data['custom_amounts'][$item->id])) {
                                    $correctionAmount = (string) $data['custom_amounts'][$item->id];
                                }
                                if (bccomp($correctionAmount, '0.00', 2) > 0) {
                                    $this->paymentService->recordCorrection($existingPayment, [
                                        'amount' => $correctionAmount,
                                        'transfer_reference' => $data['transfer_reference'],
                                        'transfer_date' => $data['transfer_date'],
                                        'correction_reason' => $data['correction_reasons'][$item->id] ?? 'Batch paid clearance',
                                        'notes' => $data['payment_notes'] ?? null,
                                    ], $actor);
                                }
                            }
                        }
                    }
                }
            }

            $now = now();
            $unpaidCount = $lockedBatch->groups()
                ->whereNotIn('status', [PaymentGroup::STATUS_PAID, PaymentGroup::STATUS_CANCELLED])
                ->where('subtotal_amount', '>', 0)
                ->count();

            if ($unpaidCount === 0) {
                $lockedBatch->update([
                    'status' => PaymentBatch::STATUS_PAID,
                    'paid_at' => $now,
                ]);
            } else {
                $lockedBatch->update([
                    'status' => PaymentBatch::STATUS_PARTIALLY_PAID,
                ]);
            }

            return $lockedBatch->fresh(['groups.items', 'creator', 'finalizer']);
        });
    }
}
