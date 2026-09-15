<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceVerification;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class InvoiceVerificationService
{
    public function __construct(
        private InvoiceNotificationService $notifications
    ) {}

    /**
     * Perform Section A (Document & Reference checks).
     */
    public function verifySectionA(LocalInvoice $invoice, array $data, User $reviewer): LocalInvoiceVerification
    {
        $this->assertFinanceOrAdmin($reviewer);

        return DB::transaction(function () use ($invoice, $data, $reviewer) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_UNDER_VERIFICATION) {
                throw new RuntimeException("Cannot verify: invoice is in [{$inv->status}] status, expected UNDER_VERIFICATION.");
            }

            /** @var LocalInvoiceVerification $verification */
            $verification = LocalInvoiceVerification::firstOrNew([
                'local_invoice_id' => $inv->id,
                'revision_number' => $inv->revision_number,
            ]);

            if ($verification->is_locked) {
                throw new RuntimeException('Verification is locked and cannot be modified.');
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
                    throw new InvalidArgumentException('Tax Invoice (Faktur Pajak) cannot be NOT APPLICABLE for PKP suppliers.');
                }

                if ($check === 'delivery_note' && $status === LocalInvoiceVerification::CHECK_NOT_APPLICABLE && $requiresSuratJalan) {
                    throw new InvalidArgumentException('Delivery Note (Surat Jalan) cannot be NOT APPLICABLE for goods (Barang) suppliers.');
                }

                if ($status === LocalInvoiceVerification::CHECK_NOT_OK) {
                    $allPassed = false;
                    if (empty(trim((string) $notes))) {
                        throw new InvalidArgumentException("Notes are mandatory when [{$check}] is marked NOT OK.");
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

        return DB::transaction(function () use ($invoice, $data, $reviewer) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if ($inv->status !== LocalInvoice::STATUS_UNDER_VERIFICATION) {
                throw new RuntimeException("Cannot verify: invoice is in [{$inv->status}] status.");
            }

            /** @var LocalInvoiceVerification $verification */
            $verification = LocalInvoiceVerification::where('local_invoice_id', $inv->id)
                ->where('revision_number', $inv->revision_number)
                ->firstOrFail();

            if ($verification->is_locked) {
                throw new RuntimeException('Verification is locked and cannot be modified.');
            }

            if (! $verification->is_section_a_passed) {
                throw new RuntimeException('Section A must be complete and passed before Section B can be finalized.');
            }

            $ppnStatus = $data['ppn_status'] ?? LocalInvoiceVerification::PPN_SESUAI;
            if ($ppnStatus === LocalInvoiceVerification::PPN_TIDAK_SESUAI) {
                if (! isset($data['verified_ppn']) || $data['verified_ppn'] === null || $data['verified_ppn'] === '') {
                    throw new InvalidArgumentException('Corrected PPN amount is mandatory when PPN status is TIDAK SESUAI.');
                }
                if (empty(trim((string) ($data['tax_notes'] ?? '')))) {
                    throw new InvalidArgumentException('Verification notes are mandatory when PPN status is TIDAK SESUAI.');
                }
            }

            $verification->ppn_status = $ppnStatus;
            $verification->submitted_ppn = $inv->tax_amount;
            $verification->verified_ppn = ($ppnStatus === LocalInvoiceVerification::PPN_SESUAI)
                ? $inv->tax_amount
                : (float) $data['verified_ppn'];

            $verification->pph_23_applicable = (bool) ($data['pph_23_applicable'] ?? false);
            $verification->pph_23_base = isset($data['pph_23_base']) ? (float) $data['pph_23_base'] : null;
            $verification->pph_23_rate = isset($data['pph_23_rate']) ? (float) $data['pph_23_rate'] : null;
            $verification->pph_23_amount = $verification->pph_23_applicable && isset($data['pph_23_amount']) ? (float) $data['pph_23_amount'] : 0.0;

            $verification->pph_4_2_applicable = (bool) ($data['pph_4_2_applicable'] ?? false);
            $verification->pph_4_2_amount = $verification->pph_4_2_applicable && isset($data['pph_4_2_amount']) ? (float) $data['pph_4_2_amount'] : 0.0;

            $verification->pph_21_applicable = (bool) ($data['pph_21_applicable'] ?? false);
            $verification->pph_21_amount = $verification->pph_21_applicable && isset($data['pph_21_amount']) ? (float) $data['pph_21_amount'] : 0.0;

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
                throw new RuntimeException("Cannot approve: invoice status is [{$inv->status}], expected UNDER_VERIFICATION.");
            }

            if (! $inv->cashier_received_at) {
                throw new RuntimeException('Cannot approve: cashier physical receipt must exist before Ready to Pay.');
            }

            /** @var LocalInvoiceVerification $verification */
            $verification = LocalInvoiceVerification::where('local_invoice_id', $inv->id)
                ->where('revision_number', $inv->revision_number)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $verification->is_section_a_passed || ! $verification->is_section_b_passed) {
                throw new RuntimeException('Both Section A and Section B must be complete and valid before transitioning to Ready to Pay.');
            }

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
                'notes' => 'Invoice verification finalized and approved as Ready to Pay.',
                'created_at' => $now,
            ]);

            $this->notifications->send($inv, $history);

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
            throw new InvalidArgumentException('Revision reason is mandatory.');
        }

        return DB::transaction(function () use ($invoice, $reason, $reviewer) {
            /** @var LocalInvoice $inv */
            $inv = LocalInvoice::where('id', $invoice->id)->lockForUpdate()->firstOrFail();

            if (! in_array($inv->status, [LocalInvoice::STATUS_WAITING_PHYSICAL_DOCUMENT, LocalInvoice::STATUS_UNDER_VERIFICATION], true)) {
                throw new RuntimeException("Cannot request revision for invoice in status [{$inv->status}].");
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

            $this->notifications->send($inv, $history);

            return $inv->fresh();
        });
    }

    private function assertFinanceOrAdmin(User $user): void
    {
        if (! $user->isFinance() && ! $user->isAdmin()) {
            throw new InvalidArgumentException('Only Finance or Admin can perform invoice verification.');
        }
    }
}
