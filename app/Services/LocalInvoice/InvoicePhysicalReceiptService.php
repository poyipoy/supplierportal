<?php

namespace App\Services\LocalInvoice;

use App\Enums\LocalInvoiceStatus;
use App\Exceptions\BusinessRuleArgumentException;
use App\Exceptions\BusinessRuleException;
use App\Models\LocalInvoice;
use App\Models\LocalInvoicePhysicalVerification;
use App\Models\LocalInvoiceVerification;
use App\Models\User;
use App\Support\BusinessTime;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class InvoicePhysicalReceiptService
{
    /**
     * Record physical document receipt by Cashier / Finance.
     * Starts the payment term clock and transitions invoice to UNDER_VERIFICATION.
     */
    public function recordReceipt(User $cashier, LocalInvoice $invoice, ?string $notes = null, ?string $scannedReceiptQr = null): LocalInvoice
    {
        if (! $cashier->isFinance() && ! $cashier->isAdmin()) {
            throw new BusinessRuleArgumentException(__('local_invoice.validation.receipt_role'));
        }

        return DB::transaction(function () use ($cashier, $invoice, $notes, $scannedReceiptQr) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT) {
                throw new BusinessRuleException(__('local_invoice.validation.receipt_status', ['status' => $inv->status]));
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
                throw new BusinessRuleArgumentException(__('local_invoice.validation.receipt_term', ['supplier' => $inv->supplier?->name]));
            }
            $paymentTermDays = (int) $termRaw;

            // 2. Authoritative receipt timestamp & calculate due_date
            $receivedAt = now();
            $dueDate = BusinessTime::toBusiness($receivedAt)
                ->startOfDay()
                ->addDays($paymentTermDays)
                ->toDateString();

            $historyNotes = $notes ?: __('local_invoice.history.physical_received', ['payment_term' => $paymentTermDays, 'due_date' => $dueDate]);

            return app(LocalInvoiceStateEngine::class)->transition(
                model: $inv,
                target: LocalInvoiceStatus::UNDER_VERIFICATION,
                actor: $cashier,
                reason: $historyNotes,
                metadata: [
                    'event' => 'physical_received',
                    'timestamp' => $receivedAt,
                    'error_message' => __('local_invoice.validation.receipt_status', ['status' => ':status']),
                    'attributes' => [
                        'cashier_received_at' => $receivedAt,
                        'cashier_received_by' => $cashier->id,
                        'payment_term_days_snapshot' => $paymentTermDays,
                        'due_date' => $dueDate,
                        'physical_verified_at' => $receivedAt,
                        'review_started_at' => $receivedAt,
                    ],
                    'after_transition' => function (LocalInvoice $lockedInv) use ($cashier, $receivedAt, $historyNotes, $supplierProfile): void {
                        LocalInvoicePhysicalVerification::updateOrCreate(
                            [
                                'local_invoice_id' => $lockedInv->id,
                                'revision_number' => $lockedInv->revision_number,
                                'status' => 'matched',
                            ],
                            [
                                'verified_by' => $cashier->id,
                                'verified_at' => $receivedAt,
                                'notes' => $historyNotes,
                                'created_at' => $receivedAt,
                            ]
                        );

                        LocalInvoiceVerification::firstOrCreate(
                            [
                                'local_invoice_id' => $lockedInv->id,
                                'revision_number' => $lockedInv->revision_number,
                            ],
                            [
                                'invoice_check' => LocalInvoiceVerification::CHECK_OK,
                                'tax_invoice_check' => ($supplierProfile && $supplierProfile->is_pkp) ? LocalInvoiceVerification::CHECK_OK : LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
                                'po_check' => LocalInvoiceVerification::CHECK_OK,
                                'delivery_note_check' => ($supplierProfile && $supplierProfile->requiresSuratJalan()) ? LocalInvoiceVerification::CHECK_OK : LocalInvoiceVerification::CHECK_NOT_APPLICABLE,
                                'gr_check' => LocalInvoiceVerification::CHECK_OK,
                                'submitted_ppn' => $lockedInv->tax_amount,
                                'verified_ppn' => $lockedInv->tax_amount,
                            ]
                        );
                    },
                    'notification_data' => [
                        'payment_term' => (string) $paymentTermDays,
                        'due_date' => $dueDate,
                        'raw_notes' => (string) ($notes ?? ''),
                    ],
                ]
            );
        });
    }
}
