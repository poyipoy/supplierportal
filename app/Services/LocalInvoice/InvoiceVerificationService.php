<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVerification;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InvoiceVerificationService
{
    public function __construct(
        private InvoiceNotificationService $notifications,
        private LocalGrReservationService $reservations
    ) {}

    /**
     * Perform Section A (Document & Reference checks).
     */
    public function verifySectionA(LocalInvoice $invoice, array $data, User $reviewer): LocalInvoiceVerification
    {
        $this->assertFinanceOrAdmin($reviewer);

        return DB::transaction(function () use ($invoice, $data) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_UNDER_VERIFICATION) {
                throw new RuntimeException(__('local_invoice.validation.verify_status', ['status' => $inv->status]));
            }

            /** @var LocalInvoiceVerification $verification */
            $verification = LocalInvoiceVerification::firstOrNew([
                'local_invoice_id' => $inv->id,
                'revision_number' => $inv->revision_number,
            ]);

            if ($verification->is_locked) {
                throw new RuntimeException(__('local_invoice.validation.verification_locked'));
            }

            $checks = ['invoice', 'tax_invoice', 'po', 'delivery_note', 'gr'];
            $allPassed = true;

            $supplier = $inv->supplier?->supplier;
            $isPkp = $supplier ? (bool) $supplier->is_pkp : false;
            $requiresSuratJalan = $supplier ? strcasecmp(trim((string) ($supplier->vendor_category ?: $supplier->category)), 'Barang') === 0 : false;

            foreach ($checks as $check) {
                $status = $data["{$check}_check"] ?? LocalInvoiceVerification::CHECK_OK;
                $notes = $data["{$check}_notes"] ?? null;

                if ($check === 'tax_invoice' && $status === LocalInvoiceVerification::CHECK_NOT_APPLICABLE && $isPkp) {
                    throw new InvalidArgumentException(__('local_invoice.validation.pkp_tax_required'));
                }

                if ($check === 'delivery_note' && $status === LocalInvoiceVerification::CHECK_NOT_APPLICABLE && $requiresSuratJalan) {
                    throw new InvalidArgumentException(__('local_invoice.validation.goods_dn_required'));
                }

                if ($status === LocalInvoiceVerification::CHECK_NOT_OK) {
                    $allPassed = false;
                    if (empty(trim((string) $notes))) {
                        throw new InvalidArgumentException(__('local_invoice.validation.check_notes', ['check' => $check]));
                    }
                }

                $verification->{"{$check}_check"} = $status;
                $verification->{"{$check}_notes"} = $notes;
            }

            $verification->is_section_a_passed = $allPassed;
            $verification->save();

            return $verification->fresh();
        });
    }

    /**
     * Perform Section B (Tax Verification).
     */
    public function verifySectionB(LocalInvoice $invoice, array $data, User $reviewer): LocalInvoiceVerification
    {
        $this->assertFinanceOrAdmin($reviewer);

        return DB::transaction(function () use ($invoice, $data) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_UNDER_VERIFICATION) {
                throw new RuntimeException(__('local_invoice.validation.verify_current_status', ['status' => $inv->status]));
            }

            /** @var LocalInvoiceVerification $verification */
            $verification = LocalInvoiceVerification::where('local_invoice_id', $inv->id)
                ->where('revision_number', $inv->revision_number)
                ->firstOrFail();

            if ($verification->is_locked) {
                throw new RuntimeException(__('local_invoice.validation.verification_locked'));
            }

            if (! $verification->is_section_a_passed) {
                throw new RuntimeException(__('local_invoice.validation.section_a_required'));
            }

            $ppnStatus = $data['ppn_status'] ?? LocalInvoiceVerification::PPN_SESUAI;
            if ($ppnStatus === LocalInvoiceVerification::PPN_TIDAK_SESUAI) {
                if (! isset($data['verified_ppn']) || $data['verified_ppn'] === null || $data['verified_ppn'] === '') {
                    throw new InvalidArgumentException(__('local_invoice.validation.corrected_ppn'));
                }
                if (empty(trim((string) ($data['tax_notes'] ?? '')))) {
                    throw new InvalidArgumentException(__('local_invoice.validation.ppn_notes'));
                }
            }

            $verification->ppn_status = $ppnStatus;
            $verification->submitted_ppn = $inv->tax_amount;
            $verification->verified_ppn = ($ppnStatus === LocalInvoiceVerification::PPN_SESUAI)
                ? $inv->tax_amount
                : Money::normalize($data['verified_ppn']);

            $verification->pph_23_applicable = (bool) ($data['pph_23_applicable'] ?? false);
            $verification->pph_23_base = isset($data['pph_23_base']) ? Money::normalize($data['pph_23_base']) : null;
            $verification->pph_23_rate = isset($data['pph_23_rate']) ? Money::normalize($data['pph_23_rate']) : null;

            // base * rate% computed at working precision, rounded once at the end.
            $calculatedPph23 = Money::multiply(
                Money::multiply($verification->pph_23_base ?? Money::ZERO, $verification->pph_23_rate ?? Money::ZERO, 8),
                '0.01'
            );
            if ($verification->pph_23_applicable) {
                $submittedAmount = isset($data['pph_23_amount']) ? Money::normalize($data['pph_23_amount']) : null;
                $verification->pph_23_amount = ($submittedAmount !== null && Money::compare($submittedAmount, Money::ZERO) > 0)
                    ? $submittedAmount
                    : $calculatedPph23;
            } else {
                $verification->pph_23_amount = Money::ZERO;
            }

            $verification->pph_4_2_applicable = (bool) ($data['pph_4_2_applicable'] ?? false);
            $verification->pph_4_2_amount = $verification->pph_4_2_applicable && isset($data['pph_4_2_amount']) ? Money::normalize($data['pph_4_2_amount']) : Money::ZERO;

            $verification->pph_21_applicable = (bool) ($data['pph_21_applicable'] ?? false);
            $verification->pph_21_amount = $verification->pph_21_applicable && isset($data['pph_21_amount']) ? Money::normalize($data['pph_21_amount']) : Money::ZERO;

            $verification->tax_notes = $data['tax_notes'] ?? null;
            $verification->is_section_b_passed = true;
            $verification->save();

            return $verification->fresh();
        });
    }

    /**
     * Lock verification and transition invoice to READY_TO_PAY.
     */
    public function lockAndApprove(LocalInvoice $invoice, User $reviewer): LocalInvoice
    {
        $this->assertFinanceOrAdmin($reviewer);

        return DB::transaction(function () use ($invoice, $reviewer) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_UNDER_VERIFICATION) {
                throw new RuntimeException(__('local_invoice.validation.approve_status', ['status' => $inv->status]));
            }

            if (! $inv->cashier_received_at) {
                throw new RuntimeException(__('local_invoice.validation.cashier_required'));
            }

            /** @var LocalInvoiceVerification $verification */
            $verification = LocalInvoiceVerification::where('local_invoice_id', $inv->id)
                ->where('revision_number', $inv->revision_number)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $verification->is_section_a_passed || ! $verification->is_section_b_passed) {
                throw new RuntimeException(__('local_invoice.validation.sections_required'));
            }

            $this->reservations->consume($inv, $reviewer);
            $now = now();
            $verification->update([
                'is_locked' => true,
                'verified_by' => $reviewer->id,
                'verified_at' => $now,
            ]);

            $inv->update([
                'status' => LocalInvoice::STATUS_READY_TO_PAY,
                'approved_at' => $now,
                'ready_to_pay_at' => $now,
            ]);

            $history = $inv->statusHistories()->create([
                'from_status' => LocalInvoice::STATUS_UNDER_VERIFICATION,
                'to_status' => LocalInvoice::STATUS_READY_TO_PAY,
                'actor_id' => $reviewer->id,
                'event' => 'approved',
                'notes' => __('local_invoice.history.verification_approved'),
                'created_at' => $now,
            ]);

            $this->notifications->send($inv, $history, [
                'due_date' => $inv->due_date?->format('Y-m-d') ?? '',
                'payment_term' => (string) ($inv->payment_term_days_snapshot ?? ''),
            ]);

            return $inv->fresh();
        });
    }

    /**
     * Request invoice revision from Supplier.
     */
    public function requestRevision(LocalInvoice $invoice, string $reason, User $reviewer): LocalInvoice
    {
        $this->assertFinanceOrAdmin($reviewer);

        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('local_invoice.validation.revision_reason'));
        }

        return DB::transaction(function () use ($invoice, $reason, $reviewer) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($inv->status, [LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, LocalInvoice::STATUS_UNDER_VERIFICATION], true)) {
                throw new RuntimeException(__('local_invoice.validation.revision_status', ['status' => $inv->status]));
            }

            $now = now();
            $fromStatus = $inv->status;

            $inv->update([
                'status' => LocalInvoice::STATUS_NEED_REVISION,
            ]);

            $history = $inv->statusHistories()->create([
                'from_status' => $fromStatus,
                'to_status' => LocalInvoice::STATUS_NEED_REVISION,
                'actor_id' => $reviewer->id,
                'event' => 'revision_requested',
                'notes' => trim($reason),
                'created_at' => $now,
            ]);

            $this->notifications->send($inv, $history, ['reason' => trim($reason)]);

            return $inv->fresh();
        });
    }

    public function reject(LocalInvoice $invoice, string $reason, User $reviewer): LocalInvoice
    {
        $this->assertFinanceOrAdmin($reviewer);
        if (trim($reason) === '') {
            throw new InvalidArgumentException(__('local_invoice.validation.rejection_reason'));
        }

        return DB::transaction(function () use ($invoice, $reason, $reviewer) {
            $inv = LocalInvoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            if (! in_array($inv->status, [LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, LocalInvoice::STATUS_UNDER_VERIFICATION, LocalInvoice::STATUS_NEED_REVISION], true)) {
                throw new RuntimeException(__('local_invoice.validation.rejection_status', ['status' => $inv->status]));
            }
            $from = $inv->status;
            $this->reservations->release($inv, $reviewer);
            $inv->update(['status' => LocalInvoice::STATUS_REJECTED]);
            $history = $inv->statusHistories()->create(['from_status' => $from, 'to_status' => LocalInvoice::STATUS_REJECTED, 'actor_id' => $reviewer->id, 'event' => 'rejected', 'notes' => trim($reason), 'created_at' => now()]);
            $this->notifications->send($inv, $history, ['reason' => trim($reason)]);

            return $inv->fresh();
        });
    }

    private function assertFinanceOrAdmin(User $user): void
    {
        if (! $user->isFinance() && ! $user->isAdmin()) {
            throw new InvalidArgumentException(__('local_invoice.validation.verification_role'));
        }
    }
}
