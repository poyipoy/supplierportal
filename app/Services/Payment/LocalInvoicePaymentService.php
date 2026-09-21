<?php

namespace App\Services\Payment;

use App\Models\LocalInvoice;
use App\Models\LocalInvoicePayment;
use App\Models\LocalInvoicePaymentTransfer;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceNotificationService;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalInvoicePaymentService
{
    public function __construct(private InvoiceNotificationService $notifications, private LocalFinanceAuditService $audit) {}

    public function recordPrimary(LocalInvoiceVoucher $voucher, array $data, User $actor): LocalInvoicePayment
    {
        $this->authorize($actor);
        $data['amount'] = $this->money($data['amount'] ?? null);
        return DB::transaction(function () use ($voucher, $data, $actor) {
            $lockedVoucher = LocalInvoiceVoucher::whereKey($voucher->id)->lockForUpdate()->firstOrFail();
            if ($lockedVoucher->status !== LocalInvoiceVoucher::STATUS_FINAL) {
                throw new RuntimeException('Only a FINAL Voucher Bayar can be settled.');
            }
            if (LocalInvoicePayment::where('local_invoice_id', $lockedVoucher->local_invoice_id)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['payment' => 'This invoice already has a Payment Settlement.']);
            }
            $invoice = LocalInvoice::whereKey($lockedVoucher->local_invoice_id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isReadyToPay()) {
                throw new RuntimeException('The invoice is no longer Ready to Pay.');
            }
            $amount = (string) $data['amount'];
            if (bccomp($amount, (string) $lockedVoucher->amount, 2) < 0 && trim((string) ($data['correction_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['correction_reason' => 'A short transfer reason is required.']);
            }
            $payment = LocalInvoicePayment::create([
                'local_invoice_id' => $invoice->id, 'local_invoice_voucher_id' => $lockedVoucher->id,
                'payment_item_id' => $lockedVoucher->payment_item_id, 'expected_amount' => $lockedVoucher->amount,
                'actual_paid_total' => '0.00', 'status' => LocalInvoicePayment::STATUS_OPEN,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->addTransfer($payment, LocalInvoicePaymentTransfer::TYPE_PRIMARY, $data, $actor);
            return $this->reconcile($payment, $actor);
        });
    }

    public function recordCorrection(LocalInvoicePayment $payment, array $data, User $actor): LocalInvoicePayment
    {
        $this->authorize($actor);
        $data['amount'] = $this->money($data['amount'] ?? null);
        return DB::transaction(function () use ($payment, $data, $actor) {
            $locked = LocalInvoicePayment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== LocalInvoicePayment::STATUS_CORRECTION_REQUIRED) {
                throw ValidationException::withMessages(['payment' => 'A correction is only allowed for an underpaid settlement.']);
            }
            if (trim((string) ($data['correction_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['correction_reason' => 'Correction reason is required.']);
            }
            $this->addTransfer($locked, LocalInvoicePaymentTransfer::TYPE_CORRECTION, $data, $actor);
            return $this->reconcile($locked, $actor);
        });
    }

    private function addTransfer(LocalInvoicePayment $payment, string $type, array $data, User $actor): void
    {
        $transferReference = trim((string) ($data['transfer_reference'] ?? ''));
        if ($transferReference === '' || mb_strlen($transferReference) > 100) {
            throw ValidationException::withMessages(['transfer_reference' => 'Transfer reference is required and may not exceed 100 characters.']);
        }
        $transferDate = $data['transfer_date'] ?? null;
        if (! is_string($transferDate) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $transferDate)) {
            throw ValidationException::withMessages(['transfer_date' => 'Transfer date must use YYYY-MM-DD format.']);
        }

        $sequence = (int) $payment->transfers()->lockForUpdate()->max('sequence_no') + 1;
        $transfer = $payment->transfers()->create([
            'sequence_no' => $sequence, 'transfer_type' => $type,
            'primary_guard' => $type === LocalInvoicePaymentTransfer::TYPE_PRIMARY ? $payment->id : null,
            'amount' => $data['amount'], 'transfer_reference' => $transferReference,
            'transfer_date' => $transferDate, 'correction_reason' => $data['correction_reason'] ?? null,
            'notes' => $data['notes'] ?? null, 'entered_by' => $actor->id,
        ]);
        $this->audit->record($transfer, strtolower($type).'_transfer_recorded', $actor, null, $transfer->toArray());
    }

    private function reconcile(LocalInvoicePayment $payment, User $actor): LocalInvoicePayment
    {
        $actual = $payment->transfers()->sum('amount');
        $comparison = bccomp((string) $actual, (string) $payment->expected_amount, 2);
        if ($comparison < 0) {
            $payment->update(['actual_paid_total' => $actual, 'status' => LocalInvoicePayment::STATUS_CORRECTION_REQUIRED, 'updated_by' => $actor->id]);

            $invoice = LocalInvoice::whereKey($payment->local_invoice_id)->lockForUpdate()->firstOrFail();
            $latestTransfer = $payment->transfers()->latest('sequence_no')->first();
            $remaining = bcsub((string) $payment->expected_amount, (string) $actual, 2);
            $reasonText = $latestTransfer?->correction_reason ? " Alasan: {$latestTransfer->correction_reason}." : '';

            $history = $invoice->statusHistories()->create([
                'from_status' => $invoice->status,
                'to_status' => $invoice->status,
                'actor_id' => $actor->id,
                'event' => 'partial_payment',
                'notes' => "Pembayaran parsial sebesar Rp " . number_format((float) ($latestTransfer?->amount ?? $actual), 0, ',', '.') . " dicatat (Ref: {$latestTransfer?->transfer_reference}). Total terbayar: Rp " . number_format((float) $actual, 0, ',', '.') . ", Sisa tagihan: Rp " . number_format((float) $remaining, 0, ',', '.') . ".{$reasonText}",
                'created_at' => now(),
            ]);
            $this->notifications->send($invoice, $history);

            return $payment->fresh('transfers');
        }

        $now = now();
        $payment->update(['actual_paid_total' => $actual, 'status' => LocalInvoicePayment::STATUS_FINALIZED, 'finalized_by' => $actor->id, 'finalized_at' => $now, 'updated_by' => $actor->id]);
        $invoice = LocalInvoice::whereKey($payment->local_invoice_id)->lockForUpdate()->firstOrFail();
        if ($invoice->status !== LocalInvoice::STATUS_PAID) {
            $invoice->update(['status' => LocalInvoice::STATUS_PAID, 'paid_at' => $now, 'completed_at' => $now]);
            $history = $invoice->statusHistories()->create(['from_status' => LocalInvoice::STATUS_READY_TO_PAY, 'to_status' => LocalInvoice::STATUS_PAID, 'actor_id' => $actor->id, 'event' => 'paid', 'notes' => 'Invoice settlement finalized.', 'created_at' => $now]);
            $this->notifications->send($invoice, $history);
        }
        if ($comparison > 0) {
            $overpaymentAmount = bcsub((string) $actual, (string) $payment->expected_amount, 2);
            $overpayment = SupplierOverpaymentRefund::firstOrCreate(
                ['local_invoice_payment_id' => $payment->id],
                ['local_invoice_id' => $invoice->id, 'supplier_id' => $invoice->supplier_id,
                    'overpayment_amount' => $overpaymentAmount,
                    'status' => SupplierOverpaymentRefund::STATUS_OPEN, 'created_by' => $actor->id]
            );
            $this->audit->record($overpayment, 'overpayment_created', $actor, null, $overpayment->toArray());

            $overHistory = $invoice->statusHistories()->create([
                'from_status' => $invoice->status,
                'to_status' => $invoice->status,
                'actor_id' => $actor->id,
                'event' => 'overpaid',
                'notes' => "Terjadi kelebihan bayar sebesar Rp " . number_format((float) $overpaymentAmount, 0, ',', '.') . ". Kelebihan dana tercatat sebagai pengembalian (refund) ke ADASI.",
                'created_at' => $now,
            ]);
            $this->notifications->send($invoice, $overHistory);
        }
        $this->syncContainers($payment);
        $this->audit->record($payment, 'settlement_finalized', $actor, null, $payment->fresh()->toArray());
        return $payment->fresh(['transfers', 'overpayment']);
    }

    private function syncContainers(LocalInvoicePayment $payment): void
    {
        $item = PaymentItem::whereKey($payment->payment_item_id)->firstOrFail();
        $group = PaymentGroup::whereKey($item->payment_group_id)->lockForUpdate()->firstOrFail();
        $unsettled = $group->activeItems()->where('payable_type', LocalInvoice::class)
            ->whereDoesntHave('localInvoicePayment', fn ($q) => $q->where('status', LocalInvoicePayment::STATUS_FINALIZED))->exists();
        if (! $unsettled) {
            $primary = $payment->transfers()->where('transfer_type', LocalInvoicePaymentTransfer::TYPE_PRIMARY)->first();
            $group->update(['status' => PaymentGroup::STATUS_PAID, 'transfer_reference' => $primary?->transfer_reference,
                'transfer_date' => $primary?->transfer_date, 'paid_by' => $payment->finalized_by, 'paid_at' => $payment->finalized_at]);
        }
        $batch = PaymentBatch::whereKey($group->payment_batch_id)->lockForUpdate()->firstOrFail();
        $remaining = $batch->groups()->whereNotIn('status', [PaymentGroup::STATUS_PAID, PaymentGroup::STATUS_CANCELLED])->where('subtotal_amount', '>', 0)->exists();
        $batch->update($remaining ? ['status' => PaymentBatch::STATUS_PARTIALLY_PAID] : ['status' => PaymentBatch::STATUS_PAID, 'paid_at' => now()]);
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && ($actor->isFinance() || $actor->isAdmin()), 403);
    }

    private function money(mixed $value): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0) {
            throw ValidationException::withMessages(['amount' => 'Transfer amount must be positive with up to two decimal places.']);
        }
        if (! str_contains($value, '.')) return $value.'.00';
        [$whole, $fraction] = explode('.', $value, 2);
        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
