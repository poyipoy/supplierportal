<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoicePhysicalVerification;
use App\Models\LocalInvoiceVerification;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InvoicePhysicalReceiptService
{
    /**
     * Record physical document receipt by Cashier / Finance.
     * Starts the payment term clock and transitions invoice to UNDER_VERIFICATION.
     */
    public function recordReceipt(User $cashier, LocalInvoice $invoice, ?string $notes = null): LocalInvoice
    {
        if (! $cashier->isFinance() && ! $cashier->isAdmin()) {
            throw new InvalidArgumentException('Only Finance / Cashier can record physical document receipt.');
        }

        return DB::transaction(function () use ($cashier, $invoice, $notes) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT) {
                throw new RuntimeException("Cannot record physical receipt: invoice is in [{$inv->status}] status, expected WAITING_PHYSICAL_DOCUMENT.");
            }

            // 1. Capture currently approved payment term from Vendor Master (P1-02)
            $supplierProfile = $inv->supplier?->supplier;
            $termRaw = $supplierProfile?->payment_term_days;
            if ($termRaw === null || ! is_numeric($termRaw) || (int) $termRaw < 1 || (int) $termRaw > 365) {
                throw new InvalidArgumentException("Vendor Master payment term is missing or invalid for supplier [{$inv->supplier?->name}]. Physical receipt cannot be recorded until a valid term (1-365 days) is configured.");
            }
            $paymentTermDays = (int) $termRaw;

            // 2. Authoritative receipt timestamp & calculate due_date
            $receivedAt = now();
            $dueDate = (clone $receivedAt)->addDays($paymentTermDays)->toDateString();

            $inv->update([
                'status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
                'cashier_received_at' => $receivedAt,
                'cashier_received_by' => $cashier->id,
                'payment_term_days_snapshot' => $paymentTermDays,
                'due_date' => $dueDate,
                'physical_verified_at' => $receivedAt,
                'review_started_at' => $receivedAt,
            ]);

            // 3. Create or update physical verification record
            LocalInvoicePhysicalVerification::updateOrCreate(
                [
                    'local_invoice_id' => $inv->id,
                    'revision_number' => $inv->revision_number,
                    'status' => 'matched',
                ],
                [
                    'verified_by' => $cashier->id,
                    'verified_at' => $receivedAt,
                    'notes' => $notes ?: 'Physical invoice received by Cashier.',
                    'created_at' => $receivedAt,
                ]
            );

            // 4. Initialize Section A & B verification record if not present
            LocalInvoiceVerification::firstOrCreate(
                [
                    'local_invoice_id' => $inv->id,
                    'revision_number' => $inv->revision_number,
                ],
                [
                    'invoice_check' => LocalInvoiceVerification::CHECK_OK,
                    'tax_invoice_check' => ($supplierProfile && $supplierProfile->is_pkp) ? LocalInvoiceVerification::CHECK_OK : LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
                    'po_check' => LocalInvoiceVerification::CHECK_OK,
                    'delivery_note_check' => ($supplierProfile && $supplierProfile->requiresSuratJalan()) ? LocalInvoiceVerification::CHECK_OK : LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
                    'gr_check' => LocalInvoiceVerification::CHECK_OK,
                    'submitted_ppn' => $inv->tax_amount,
                    'verified_ppn' => $inv->tax_amount,
                ]
            );

            // 5. Record status history & send notification
            $history = $inv->statusHistories()->create([
                'from_status' => LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT,
                'to_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
                'actor_id' => $cashier->id,
                'event' => 'physical_received',
                'notes' => $notes ?: "Physical document received. Payment term: {$paymentTermDays} days, Due date: {$dueDate}",
                'created_at' => $receivedAt,
            ]);

            app(InvoiceNotificationService::class)->send($inv, $history);

            return $inv->fresh();
        });
    }
}
