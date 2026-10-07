<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoicePhysicalVerification;
use App\Models\LocalInvoiceVerification;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

class InvoicePhysicalReceiptService
{
    /**
     * Record physical document receipt by Cashier / Finance.
     * Starts the payment term clock and transitions invoice to UNDER_VERIFICATION.
     */
    public function recordReceipt(User $cashier, LocalInvoice $invoice, ?string $notes = null, ?string $scannedReceiptQr = null): LocalInvoice
    {
        if (! $cashier->isFinance() && ! $cashier->isAdmin()) {
            throw new InvalidArgumentException(__('local_invoice.validation.receipt_role'));
        }

        return DB::transaction(function () use ($cashier, $invoice, $notes, $scannedReceiptQr) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT) {
                throw new RuntimeException(__('local_invoice.validation.receipt_status', ['status' => $inv->status]));
            }

            $receiptNumber = $inv->receipt?->receipt_number;
            $scannedReceiptQr = is_string($scannedReceiptQr) ? trim($scannedReceiptQr) : null;
            $scannedQrParts = null;

            if ($scannedReceiptQr !== null && strlen($scannedReceiptQr) <= 2048) {
                try {
                    $scannedQrParts = parse_url($scannedReceiptQr);
                } catch (\ValueError) {
                    $scannedQrParts = false;
                }
            }

            $scannedQrRequest = is_array($scannedQrParts)
                && isset($scannedQrParts['scheme'], $scannedQrParts['host'], $scannedQrParts['path'])
                && in_array(strtolower($scannedQrParts['scheme']), ['http', 'https'], true)
                && ! isset($scannedQrParts['user']) && ! isset($scannedQrParts['pass']) && ! isset($scannedQrParts['fragment'])
                ? Request::create($scannedReceiptQr, 'GET')
                : null;
            $expectedReceiptPath = $receiptNumber
                ? route('receipts.verify-supplier', ['receipt' => $receiptNumber], false)
                : null;
            $hasValidReceiptSignature = $scannedQrRequest !== null
                && $scannedQrParts['path'] === $expectedReceiptPath
                && URL::hasValidSignature($scannedQrRequest);
            $scannedRevision = $scannedQrRequest?->query('revision');
            $matchesCurrentReceipt = $receiptNumber !== null
                && $hasValidReceiptSignature
                && is_string($scannedRevision)
                && hash_equals((string) $inv->revision_number, $scannedRevision);
            $matchesLegacyInitialReceipt = $receiptNumber !== null
                && (int) $inv->revision_number === 1
                && $hasValidReceiptSignature
                && ! $scannedQrRequest->query->has('revision');

            if (! $matchesCurrentReceipt && ! $matchesLegacyInitialReceipt) {
                throw ValidationException::withMessages([
                    'receipt_qr' => __('local_invoice.validation.receipt_qr'),
                ]);
            }

            // 1. Capture currently approved payment term from Vendor Master (P1-02)
            $supplierProfile = $inv->supplier?->supplier;
            $termRaw = $supplierProfile?->payment_term_days;
            if ($termRaw === null || ! is_numeric($termRaw) || (int) $termRaw < 1 || (int) $termRaw > 365) {
                throw new InvalidArgumentException(__('local_invoice.validation.receipt_term', ['supplier' => $inv->supplier?->name]));
            }
            $paymentTermDays = (int) $termRaw;

            // 2. Authoritative receipt timestamp & calculate due_date
            $receivedAt = now();
            $dueDate = BusinessTime::toBusiness($receivedAt)
                ->startOfDay()
                ->addDays($paymentTermDays)
                ->toDateString();

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
                    'notes' => $notes ?: __('local_invoice.history.physical_received', ['payment_term' => $paymentTermDays, 'due_date' => $dueDate]),
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
                'notes' => $notes ?: __('local_invoice.history.physical_received', ['payment_term' => $paymentTermDays, 'due_date' => $dueDate]),
                'created_at' => $receivedAt,
            ]);

            app(InvoiceNotificationService::class)->send($inv, $history, [
                'payment_term' => (string) $paymentTermDays,
                'due_date' => $dueDate,
                'raw_notes' => (string) ($notes ?? ''),
            ]);

            return $inv->fresh();
        });
    }
}
