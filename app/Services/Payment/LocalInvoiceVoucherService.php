<?php

namespace App\Services\Payment;

use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalInvoiceVoucher;
use App\Models\PaymentBatch;
use App\Models\PaymentItem;
use App\Models\User;
use App\Services\LocalInvoice\LocalFinanceAuditService;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class LocalInvoiceVoucherService
{
    public function __construct(private PaymentVoucherService $numbers, private LocalFinanceAuditService $audit) {}

    public function finalize(PaymentItem $item, array $data, User $actor): LocalInvoiceVoucher
    {
        $this->authorize($actor);
        if (! in_array($data['payment_method'] ?? null, [LocalInvoiceVoucher::METHOD_BANK, LocalInvoiceVoucher::METHOD_KAS], true)) {
            throw ValidationException::withMessages(['payment_method' => 'Choose exactly one payment method: Bank or Kas.']);
        }
        if (blank($data['voucher_date'] ?? null)) {
            throw ValidationException::withMessages(['voucher_date' => 'Voucher date is required.']);
        }

        return DB::transaction(function () use ($item, $data, $actor) {
            $lockedItem = PaymentItem::whereKey($item->id)->lockForUpdate()->firstOrFail();
            $existing = LocalInvoiceVoucher::where('payment_item_id', $lockedItem->id)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }
            if (! $lockedItem->isActive() || $lockedItem->payable_type !== LocalInvoice::class) {
                throw ValidationException::withMessages(['payment_item' => 'Voucher Bayar is only available for an active Local Supplier invoice item.']);
            }

            $group = $lockedItem->group()->lockForUpdate()->firstOrFail();
            $batch = $group->batch()->lockForUpdate()->firstOrFail();
            if ($batch->batch_type !== PaymentBatch::TYPE_SUPPLIER || ! in_array($batch->status, [PaymentBatch::STATUS_FINALIZED, PaymentBatch::STATUS_PARTIALLY_PAID], true)) {
                throw ValidationException::withMessages(['payment_item' => 'Finalize the Supplier DRP batch before issuing its vouchers.']);
            }

            $invoice = LocalInvoice::whereKey($lockedItem->payable_id)->lockForUpdate()->firstOrFail();
            if (! $invoice->isReadyToPay()) {
                throw new RuntimeException("Invoice [{$invoice->invoice_number}] is not Ready to Pay.");
            }
            $verification = $invoice->currentVerification()->lockForUpdate()->first();
            if (! $verification || ! $verification->is_locked) {
                throw ValidationException::withMessages(['payment_item' => 'The latest invoice verification must be locked.']);
            }
            if ($invoice->local_purchase_order_id) {
                $histories = $invoice->goodsReceiptHistories()->whereIn('state', [LocalInvoiceGoodsReceipt::STATE_RESERVED, LocalInvoiceGoodsReceipt::STATE_CONSUMED])->get();
                $receipts = \App\Models\LocalGoodsReceipt::where('current_invoice_id', $invoice->id)->lockForUpdate()->get();
                $historyIds = $histories->pluck('local_goods_receipt_id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                $receiptIds = $receipts->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
                if ($histories->isEmpty() || $histories->contains(fn (LocalInvoiceGoodsReceipt $history) => $history->state !== LocalInvoiceGoodsReceipt::STATE_CONSUMED) || $receipts->isEmpty() || $receipts->contains(fn ($receipt) => $receipt->status !== \App\Models\LocalGoodsReceipt::STATUS_INVOICED) || $historyIds !== $receiptIds) {
                    throw ValidationException::withMessages(['payment_item' => 'The authoritative GR allocation has not been fully consumed.']);
                }
            }

            $supplier = $invoice->supplier()->with('supplier')->firstOrFail();
            $profile = $supplier->supplier;
            $pph = $verification->totalWithholdingExact();
            $net = $verification->netPayableExact($invoice->invoice_amount);
            if (Money::compare($net, $lockedItem->amount) !== 0) {
                throw ValidationException::withMessages(['payment_item' => 'DRP item amount no longer matches the verified invoice payable.']);
            }
            $grReferences = $invoice->goodsReceiptHistories()->where('state', LocalInvoiceGoodsReceipt::STATE_CONSUMED)
                ->orderBy('id')->pluck('gr_number_snapshot')->implode(', ');
            if ($grReferences === '') {
                $grReferences = (string) ($invoice->internal_gr_reference ?: $invoice->manual_gr_reference ?: 'Legacy record');
            }

            $voucher = LocalInvoiceVoucher::create([
                'local_invoice_id' => $invoice->id,
                'payment_batch_id' => $batch->id,
                'payment_group_id' => $group->id,
                'payment_item_id' => $lockedItem->id,
                'voucher_number' => $this->numbers->nextVoucherNumber(),
                'voucher_date' => $data['voucher_date'],
                'payment_method' => $data['payment_method'],
                'status' => LocalInvoiceVoucher::STATUS_FINAL,
                'supplier_name_snapshot' => $profile?->company_name ?: $supplier->name,
                'bank_name_snapshot' => $group->bank_name,
                'bank_account_snapshot' => $group->account_number,
                'bank_account_holder_snapshot' => $group->account_holder_name,
                'npwp_snapshot' => $profile?->npwp,
                'invoice_number_snapshot' => $invoice->invoice_number,
                'po_number_snapshot' => $invoice->po_number,
                'gr_references_snapshot' => $grReferences,
                'dpp_snapshot' => $invoice->invoice_amount,
                'ppn_snapshot' => $verification->verified_ppn,
                'pph_snapshot' => $pph,
                'net_payable_snapshot' => $net,
                'amount' => $net,
                // Display-only conversion; the persisted amount above stays exact.
                'terbilang_snapshot' => $this->numbers->terbilang((float) $net).' Rupiah',
                'remarks_snapshot' => $data['remarks'] ?? null,
                'finalized_by' => $actor->id,
                'finalized_at' => now(),
            ]);
            $this->audit->record($voucher, 'voucher_finalized', $actor, null, $voucher->toArray());

            return $voucher;
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->is_active && ($actor->isFinance() || $actor->isAdmin()), 403);
    }
}
