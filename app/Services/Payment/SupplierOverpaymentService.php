<?php

namespace App\Services\Payment;

use App\Models\SupplierOverpaymentRefund;
use App\Models\User;
use App\Services\LocalInvoice\InvoiceNotificationService;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SupplierOverpaymentService
{
    public function __construct(
        private LocalFinanceAuditService $audit,
        private InvoiceNotificationService $notifications
    ) {}

    public function settle(SupplierOverpaymentRefund $refund, array $data, ?UploadedFile $proof, User $actor): SupplierOverpaymentRefund
    {
        abort_unless($actor->is_active && ($actor->isFinance() || $actor->isAdmin()), 403);
        if (! $proof || ! $proof->isValid() || $proof->getSize() > 10 * 1024 * 1024 || ! in_array(strtolower((string) $proof->getClientOriginalExtension()), ['pdf', 'jpg', 'jpeg', 'png'], true) || ! in_array(strtolower((string) $proof->getMimeType()), ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw ValidationException::withMessages(['proof' => 'Refund proof must be a valid PDF, JPG, or PNG file up to 10 MB.']);
        }
        $refundAmount = trim((string) ($data['refund_amount'] ?? ''));
        if (! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/', $refundAmount) || bccomp($refundAmount, '0', 2) <= 0) {
            throw ValidationException::withMessages(['refund_amount' => 'Refund amount must be positive with up to two decimal places.']);
        }
        if (blank(trim((string) ($data['refund_reference'] ?? ''))) || blank($data['refund_date'] ?? null)) {
            throw ValidationException::withMessages(['refund_reference' => 'Refund reference and date are required.']);
        }
        $path = 'attachments/'.now()->format('Y/m').'/'.$proof->hashName();
        $stream = fopen($proof->getPathname(), 'r');
        if ($stream === false) {
            throw ValidationException::withMessages(['proof' => 'Refund proof could not be read.']);
        }
        try {
            if (! Storage::disk('private')->put($path, $stream)) {
                throw ValidationException::withMessages(['proof' => 'Refund proof could not be stored.']);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        try {
            return DB::transaction(function () use ($refund, $data, $proof, $actor, $path) {
                $locked = SupplierOverpaymentRefund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== SupplierOverpaymentRefund::STATUS_OPEN) {
                    throw ValidationException::withMessages(['refund' => 'This overpayment has already been settled.']);
                }
                if (bccomp((string) $data['refund_amount'], (string) $locked->overpayment_amount, 2) !== 0) {
                    throw ValidationException::withMessages(['refund_amount' => 'The refund must equal the full overpayment amount. Partial refunds are not allowed.']);
                }
                $locked->update(['status' => SupplierOverpaymentRefund::STATUS_SETTLED, 'refund_amount' => $data['refund_amount'],
                    'refund_reference' => trim($data['refund_reference']), 'refund_date' => $data['refund_date'],
                    'notes' => $data['notes'] ?? null, 'settled_by' => $actor->id, 'settled_at' => now()]);
                $locked->attachments()->create(['file_path' => $path, 'file_name' => $proof->getClientOriginalName(),
                    'file_type' => $proof->getMimeType(), 'uploaded_by' => $actor->id]);
                $this->audit->record($locked, 'overpayment_refunded', $actor, ['status' => SupplierOverpaymentRefund::STATUS_OPEN], $locked->fresh()->toArray());

                $invoice = $locked->invoice;
                if ($invoice) {
                    $notes = 'Refund kelebihan bayar sebesar Rp '.number_format((float) $data['refund_amount'], 0, ',', '.').' telah diselesaikan oleh Finance ADASI (Ref: '.trim($data['refund_reference']).').';
                    if (! empty($data['notes'])) {
                        $notes .= ' Catatan: '.trim($data['notes']);
                    }

                    $history = $invoice->statusHistories()->create([
                        'from_status' => $invoice->status,
                        'to_status' => $invoice->status,
                        'actor_id' => $actor->id,
                        'event' => 'refund_settled',
                        'notes' => $notes,
                        'created_at' => now(),
                    ]);

                    $this->notifications->send($invoice, $history);
                }

                return $locked->fresh('attachments');
            });
        } catch (Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }
    }
}
