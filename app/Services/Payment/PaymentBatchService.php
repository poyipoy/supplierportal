<?php

namespace App\Services\Payment;

use App\Models\GaClaim;
use App\Models\LocalInvoice;
use App\Models\PaymentBatch;
use App\Models\PaymentGroup;
use App\Models\PaymentItem;
use App\Models\SupplierBankAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class PaymentBatchService
{
    /**
     * Create a DRP batch for Supplier invoices.
     */
    public function createSupplierBatch(User $actor, array $invoiceIds, ?string $notes = null): PaymentBatch
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only Finance or Admin can create Supplier DRP batches.');
        }

        if (empty($invoiceIds)) {
            throw new InvalidArgumentException('At least one Ready to Pay invoice must be selected.');
        }

        return DB::transaction(function () use ($actor, $invoiceIds, $notes) {
            $year = now()->year;
            DB::table('local_invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $seq = DB::table('local_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            $num = $seq->last_number + 1;
            DB::table('local_invoice_sequences')->where('year', $year)->update(['last_number' => $num]);
            $batchNumber = 'DRP-'.$year.'-'.str_pad((string) $num, 5, '0', STR_PAD_LEFT);

            $batch = PaymentBatch::create([
                'batch_number' => $batchNumber,
                'batch_type' => PaymentBatch::TYPE_SUPPLIER,
                'status' => PaymentBatch::STATUS_DRAFT,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            // Lock and validate invoices
            $invoices = LocalInvoice::whereIn('id', $invoiceIds)->lockForUpdate()->get();

            if ($invoices->count() !== count($invoiceIds)) {
                throw new InvalidArgumentException('One or more selected invoices do not exist.');
            }

            // Check if any invoice is already in an active (non-cancelled, non-paid) payment item
            $alreadyBatched = PaymentItem::where('payable_type', LocalInvoice::class)
                ->whereIn('payable_id', $invoiceIds)
                ->where('status', PaymentItem::STATUS_ACTIVE)
                ->whereHas('group', fn ($g) => $g->where('status', PaymentGroup::STATUS_UNPAID)
                    ->whereHas('batch', fn ($q) => $q->whereIn('status', PaymentBatch::ACTIVE_STATUSES))
                )
                ->exists();

            if ($alreadyBatched) {
                throw new RuntimeException('One or more invoices are already reserved in an active DRP batch.');
            }

            // Group by Supplier + Active Bank Account
            $grouped = [];
            foreach ($invoices as $invoice) {
                if ($invoice->status !== LocalInvoice::STATUS_READY_TO_PAY) {
                    throw new RuntimeException("Invoice [{$invoice->invoice_number}] is not Ready to Pay (status: {$invoice->status}).");
                }

                $supplierUser = $invoice->supplier;
                $activeBank = $supplierUser->activeSupplierBankAccount;

                if (! $activeBank) {
                    throw new RuntimeException("Supplier [{$supplierUser->name}] does not have a verified bank account.");
                }

                $groupKey = $supplierUser->id.'_'.$activeBank->id;

                if (! isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = [
                        'payee_id' => $supplierUser->id,
                        'payee_name' => $supplierUser->supplier?->company_name ?: $supplierUser->name,
                        'bank_name' => $activeBank->bank_name,
                        'account_number' => $activeBank->account_number,
                        'account_holder_name' => $activeBank->account_holder_name,
                        'is_bca' => $activeBank->isBca(),
                        'invoices' => [],
                    ];
                }

                $grouped[$groupKey]['invoices'][] = $invoice;
            }

            $totalSubtotal = 0.0;
            $totalFee = 0.0;
            $totalNet = 0.0;

            foreach ($grouped as $groupData) {
                $subtotal = 0.0;
                foreach ($groupData['invoices'] as $inv) {
                    // Net payable calculated from verification if available, else invoice_amount + tax_amount
                    $verification = $inv->currentVerification;
                    $amount = $verification ? $verification->calculateNetPayable((float) $inv->invoice_amount) : ((float) $inv->invoice_amount + (float) $inv->tax_amount);
                    $subtotal += $amount;
                }

                // Default bank fee: BCA = 0, Non-BCA = 2.500
                $fee = $groupData['is_bca'] ? 0.0 : 2500.0;
                $net = max(0.0, round($subtotal - $fee, 2));

                $group = $batch->groups()->create([
                    'payee_type' => 'supplier',
                    'payee_id' => $groupData['payee_id'],
                    'payee_name' => $groupData['payee_name'],
                    'bank_name' => $groupData['bank_name'],
                    'account_number' => $groupData['account_number'],
                    'account_holder_name' => $groupData['account_holder_name'],
                    'subtotal_amount' => $subtotal,
                    'bank_fee' => $fee,
                    'net_payment_amount' => $net,
                    'status' => PaymentGroup::STATUS_UNPAID,
                ]);

                foreach ($groupData['invoices'] as $inv) {
                    $verification = $inv->currentVerification;
                    $amount = $verification ? $verification->calculateNetPayable((float) $inv->invoice_amount) : ((float) $inv->invoice_amount + (float) $inv->tax_amount);

                    $group->items()->create([
                        'payable_type' => LocalInvoice::class,
                        'payable_id' => $inv->id,
                        'amount' => $amount,
                        'status' => PaymentItem::STATUS_ACTIVE,
                    ]);
                }

                $totalSubtotal += $subtotal;
                $totalFee += $fee;
                $totalNet += $net;
            }

            $batch->update([
                'total_subtotal' => $totalSubtotal,
                'total_bank_fee' => $totalFee,
                'total_net_amount' => $totalNet,
            ]);

            return $batch->fresh(['groups.items']);
        });
    }

    /**
     * Create a DRP batch for GA claims.
     */
    public function createGaBatch(User $actor, array $claimIds, ?string $notes = null): PaymentBatch
    {
        if (! $actor->isGa() && ! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only GA, Finance, or Admin can create GA DRP batches.');
        }

        if (empty($claimIds)) {
            throw new InvalidArgumentException('At least one Ready to Pay GA claim must be selected.');
        }

        return DB::transaction(function () use ($actor, $claimIds, $notes) {
            $year = now()->year;
            DB::table('local_invoice_sequences')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $seq = DB::table('local_invoice_sequences')->where('year', $year)->lockForUpdate()->first();
            $num = $seq->last_number + 1;
            DB::table('local_invoice_sequences')->where('year', $year)->update(['last_number' => $num]);
            $batchNumber = 'DRP-GA-'.$year.'-'.str_pad((string) $num, 5, '0', STR_PAD_LEFT);

            $batch = PaymentBatch::create([
                'batch_number' => $batchNumber,
                'batch_type' => PaymentBatch::TYPE_GA,
                'status' => PaymentBatch::STATUS_DRAFT,
                'created_by' => $actor->id,
                'notes' => $notes,
            ]);

            $claims = GaClaim::whereIn('id', $claimIds)->lockForUpdate()->get();

            if ($claims->count() !== count($claimIds)) {
                throw new InvalidArgumentException('One or more selected claims do not exist.');
            }

            $alreadyBatched = PaymentItem::where('payable_type', GaClaim::class)
                ->whereIn('payable_id', $claimIds)
                ->where('status', PaymentItem::STATUS_ACTIVE)
                ->whereHas('group', fn ($g) => $g->where('status', PaymentGroup::STATUS_UNPAID)
                    ->whereHas('batch', fn ($q) => $q->whereIn('status', PaymentBatch::ACTIVE_STATUSES))
                )
                ->exists();

            if ($alreadyBatched) {
                throw new RuntimeException('One or more claims are already reserved in an active DRP batch.');
            }

            // Group by Employee + Bank details
            $grouped = [];
            foreach ($claims as $claim) {
                if ($claim->status !== GaClaim::STATUS_READY_TO_PAY) {
                    throw new RuntimeException("Claim [{$claim->claim_number}] is not Ready to Pay (status: {$claim->status}).");
                }

                $employee = $claim->employee;
                $groupKey = (string) $employee->id;

                if (! isset($grouped[$groupKey])) {
                    $grouped[$groupKey] = [
                        'payee_id' => $employee->id,
                        'payee_name' => $employee->name,
                        'bank_name' => $employee->bank_name,
                        'account_number' => $employee->account_number,
                        'account_holder_name' => $employee->account_holder_name,
                        'claims' => [],
                    ];
                }

                $grouped[$groupKey]['claims'][] = $claim;
            }

            $totalSubtotal = 0.0;
            $totalFee = 0.0;
            $totalNet = 0.0;

            foreach ($grouped as $groupData) {
                $subtotal = 0.0;
                foreach ($groupData['claims'] as $clm) {
                    $subtotal += (float) $clm->amount;
                }

                // Rule: GA bank fee is ALWAYS Rp 0 for all banks
                $fee = 0.0;
                $net = $subtotal;

                $group = $batch->groups()->create([
                    'payee_type' => 'employee',
                    'payee_id' => $groupData['payee_id'],
                    'payee_name' => $groupData['payee_name'],
                    'bank_name' => $groupData['bank_name'],
                    'account_number' => $groupData['account_number'],
                    'account_holder_name' => $groupData['account_holder_name'],
                    'subtotal_amount' => $subtotal,
                    'bank_fee' => $fee,
                    'net_payment_amount' => $net,
                    'status' => PaymentGroup::STATUS_UNPAID,
                ]);

                foreach ($groupData['claims'] as $clm) {
                    $group->items()->create([
                        'payable_type' => GaClaim::class,
                        'payable_id' => $clm->id,
                        'amount' => (float) $clm->amount,
                        'status' => PaymentItem::STATUS_ACTIVE,
                    ]);
                }

                $totalSubtotal += $subtotal;
                $totalFee += $fee;
                $totalNet += $net;
            }

            $batch->update([
                'total_subtotal' => $totalSubtotal,
                'total_bank_fee' => $totalFee,
                'total_net_amount' => $totalNet,
            ]);

            return $batch->fresh(['groups.items']);
        });
    }

    /**
     * Remove an item from a DRP batch while in DRAFT status.
     * The item returns to the Ready to Pay candidate pool.
     */
    public function removeItem(PaymentItem $item, User $actor, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Removal reason is mandatory.');
        }

        DB::transaction(function () use ($item, $actor, $reason) {
            /** @var PaymentItem $it */
            $it = PaymentItem::where('id', $item->id)->lockForUpdate()->firstOrFail();
            $group = $it->group()->lockForUpdate()->firstOrFail();
            $batch = $group->batch()->lockForUpdate()->firstOrFail();

            if ($batch->status !== PaymentBatch::STATUS_DRAFT) {
                throw new RuntimeException("Items can only be removed while DRP is in DRAFT status (current: {$batch->status}).");
            }

            if ($it->status !== PaymentItem::STATUS_ACTIVE) {
                return;
            }

            $it->update([
                'status' => PaymentItem::STATUS_REMOVED,
                'removed_by' => $actor->id,
                'removed_at' => now(),
                'removal_reason' => trim($reason),
            ]);

            // Recalculate group
            $activeItems = $group->activeItems;
            $newSubtotal = $activeItems->sum('amount');
            $newFee = $activeItems->isEmpty() ? 0.0 : (float) $group->bank_fee;
            $newNet = max(0.0, round($newSubtotal - $newFee, 2));

            $group->update([
                'subtotal_amount' => $newSubtotal,
                'bank_fee' => $newFee,
                'net_payment_amount' => $newNet,
                'status' => $activeItems->isEmpty() ? PaymentGroup::STATUS_CANCELLED : $group->status,
            ]);

            // Recalculate batch
            $allActiveGroups = $batch->groups()->where('status', '!=', PaymentGroup::STATUS_CANCELLED)->get();
            $batch->update([
                'total_subtotal' => $allActiveGroups->sum('subtotal_amount'),
                'total_bank_fee' => $allActiveGroups->sum('bank_fee'),
                'total_net_amount' => $allActiveGroups->sum('net_payment_amount'),
            ]);
        });
    }

    /**
     * Override bank fee for a group while in DRAFT status.
     */
    public function overrideGroupFee(PaymentGroup $group, float $newFee, string $reason, User $actor): PaymentGroup
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('Reason is mandatory when overriding bank fee.');
        }

        return DB::transaction(function () use ($group, $newFee, $reason, $actor) {
            /** @var PaymentGroup $grp */
            $grp = PaymentGroup::where('id', $group->id)->lockForUpdate()->firstOrFail();
            $batch = $grp->batch()->lockForUpdate()->firstOrFail();

            if ($batch->status !== PaymentBatch::STATUS_DRAFT) {
                throw new RuntimeException("Fee override is only permitted while DRP is in DRAFT status.");
            }

            $subtotal = (float) $grp->subtotal_amount;
            $net = max(0.0, round($subtotal - $newFee, 2));

            $grp->update([
                'bank_fee' => $newFee,
                'net_payment_amount' => $net,
                'fee_override_reason' => trim($reason).' (by '.$actor->name.')',
            ]);

            $allActiveGroups = $batch->groups()->where('status', '!=', PaymentGroup::STATUS_CANCELLED)->get();
            $batch->update([
                'total_bank_fee' => $allActiveGroups->sum('bank_fee'),
                'total_net_amount' => $allActiveGroups->sum('net_payment_amount'),
            ]);

            return $grp->fresh();
        });
    }

    /**
     * Finalize a DRP batch. Locks membership, grouping, and fee snapshot.
     */
    public function finalizeBatch(PaymentBatch $batch, User $actor): PaymentBatch
    {
        if (! $actor->isFinance() && ! $actor->isAdmin()) {
            throw new InvalidArgumentException('Only Finance or Admin can finalize a DRP batch.');
        }

        return DB::transaction(function () use ($batch, $actor) {
            /** @var PaymentBatch $b */
            $b = PaymentBatch::where('id', $batch->id)->lockForUpdate()->firstOrFail();

            if ($b->status !== PaymentBatch::STATUS_DRAFT) {
                throw new RuntimeException("Cannot finalize: DRP batch is in [{$b->status}] status, expected DRAFT.");
            }

            // P5-07 revalidation:
            // 1. Must have active items
            $activeItems = PaymentItem::whereHas('group', fn ($g) => $g->where('payment_batch_id', $b->id)->where('status', '!=', PaymentGroup::STATUS_CANCELLED))
                ->where('status', PaymentItem::STATUS_ACTIVE)
                ->get();

            if ($activeItems->isEmpty()) {
                throw new RuntimeException("Cannot finalize DRP: batch contains no active items.");
            }

            // 2. Each payable must still be in READY_TO_PAY status
            foreach ($activeItems as $item) {
                if ($item->payable_type === LocalInvoice::class) {
                    $inv = LocalInvoice::find($item->payable_id);
                    if (! $inv || $inv->status !== LocalInvoice::STATUS_READY_TO_PAY) {
                        throw new RuntimeException("Cannot finalize DRP: invoice [".($inv?->invoice_number ?? $item->payable_id)."] is no longer Ready to Pay.");
                    }
                } elseif ($item->payable_type === GaClaim::class) {
                    $clm = GaClaim::find($item->payable_id);
                    if (! $clm || $clm->status !== GaClaim::STATUS_READY_TO_PAY) {
                        throw new RuntimeException("Cannot finalize DRP: claim [".($clm?->claim_number ?? $item->payable_id)."] is no longer Ready to Pay.");
                    }
                }
            }

            $b->update([
                'status' => PaymentBatch::STATUS_FINALIZED,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ]);

            return $b->fresh(['groups.items']);
        });
    }
}
