<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoice;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalGrReservationService
{
    public function reserve(User $supplier, LocalInvoice $invoice, int $purchaseOrderId, array $goodsReceiptIds): void
    {
        DB::transaction(fn () => $this->reserveWithinTransaction($supplier, $invoice, $purchaseOrderId, $goodsReceiptIds));
    }

    private function reserveWithinTransaction(User $supplier, LocalInvoice $invoice, int $purchaseOrderId, array $goodsReceiptIds): void
    {
        $ids = $this->normalizeIds($goodsReceiptIds);
        if ($ids === []) {
            throw ValidationException::withMessages(['goods_receipt_ids' => 'Select at least one Goods Receipt.']);
        }

        $po = LocalPurchaseOrder::whereKey($purchaseOrderId)->lockForUpdate()->first();
        if (! $po || (int) $po->supplier_id !== (int) $supplier->id) {
            throw ValidationException::withMessages(['local_purchase_order_id' => 'The selected PO does not belong to this supplier.']);
        }
        $current = LocalGoodsReceipt::where('current_invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        if ($po->status !== LocalPurchaseOrder::STATUS_OPEN) {
            $continuingClosedReservation = $po->status === LocalPurchaseOrder::STATUS_CLOSED
                && $current->isNotEmpty()
                && $current->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $ids;
            if (! $continuingClosedReservation) {
                throw ValidationException::withMessages(['local_purchase_order_id' => 'Only an OPEN PO can accept a new GR reservation. Existing reservations may continue after closure.']);
            }
        }
        $receipts = LocalGoodsReceipt::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($receipts->count() !== count($ids)) {
            throw ValidationException::withMessages(['goods_receipt_ids' => 'One or more selected Goods Receipts do not exist.']);
        }

        foreach ($receipts as $receipt) {
            if ((int) $receipt->local_purchase_order_id !== (int) $po->id) {
                throw ValidationException::withMessages(['goods_receipt_ids' => "GR {$receipt->gr_number} does not belong to PO {$po->po_number}."]);
            }
            $ownedByInvoice = (int) $receipt->current_invoice_id === (int) $invoice->id;
            if (! ($receipt->status === LocalGoodsReceipt::STATUS_AVAILABLE || ($receipt->status === LocalGoodsReceipt::STATUS_RESERVED && $ownedByInvoice))) {
                throw ValidationException::withMessages(['goods_receipt_ids' => "GR {$receipt->gr_number} is no longer available."]);
            }
        }

        $otherInvoiced = LocalInvoice::where('local_purchase_order_id', $po->id)
            ->where('id', '!=', $invoice->id)
            ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])
            ->sum('invoice_amount');

        if (bccomp(bcadd((string) $otherInvoiced, (string) $invoice->invoice_amount, 2), (string) $po->total_amount, 2) > 0) {
            $remaining = bcsub((string) $po->total_amount, (string) $otherInvoiced, 2);
            throw ValidationException::withMessages([
                'goods_receipt_ids' => 'Invoice DPP exceeds the remaining PO financial ceiling of Rp '.number_format((float) $remaining, 2, ',', '.').'.',
            ]);
        }

        $removed = $current->whereNotIn('id', $ids);
        foreach ($removed as $receipt) {
            $before = $receipt->toArray();
            $receipt->update(['status' => LocalGoodsReceipt::STATUS_AVAILABLE, 'current_invoice_id' => null, 'updated_by' => $supplier->id]);
            LocalInvoiceGoodsReceipt::where('local_invoice_id', $invoice->id)
                ->where('local_goods_receipt_id', $receipt->id)
                ->where('state', LocalInvoiceGoodsReceipt::STATE_RESERVED)
                ->update(['state' => LocalInvoiceGoodsReceipt::STATE_RELEASED, 'released_at' => now(), 'updated_at' => now()]);
            app(LocalFinanceAuditService::class)->record($receipt, 'gr_released', $supplier, $before, $receipt->fresh()->toArray(), ['invoice_id' => $invoice->id]);
        }

        foreach ($receipts as $receipt) {
            if ((int) $receipt->current_invoice_id === (int) $invoice->id) {
                continue;
            }
            $before = $receipt->toArray();
            $receipt->update(['status' => LocalGoodsReceipt::STATUS_RESERVED, 'current_invoice_id' => $invoice->id, 'updated_by' => $supplier->id]);
            LocalInvoiceGoodsReceipt::create([
                'local_invoice_id' => $invoice->id,
                'local_purchase_order_id' => $po->id,
                'local_goods_receipt_id' => $receipt->id,
                'state' => LocalInvoiceGoodsReceipt::STATE_RESERVED,
                'gr_number_snapshot' => $receipt->gr_number,
                'gr_qty_snapshot' => $receipt->qty,
                'reserved_at' => now(),
            ]);
            app(LocalFinanceAuditService::class)->record($receipt, 'gr_reserved', $supplier, $before, $receipt->fresh()->toArray(), ['invoice_id' => $invoice->id, 'po_id' => $po->id]);
        }

        $invoice->update([
            'local_purchase_order_id' => $po->id,
            'po_source' => 'INTERNAL',
            'po_number' => $po->po_number,
            'internal_po_reference' => $po->po_number,
            'manual_po_number' => null,
            'internal_gr_reference' => $receipts->pluck('gr_number')->implode(', '),
            'manual_gr_reference' => null,
            'po_value_snapshot' => $po->total_amount,
            'po_invoiced_snapshot' => null,
            'po_remaining_snapshot' => null,
            'has_po_discrepancy' => false,
        ]);
    }

    public function release(LocalInvoice $invoice, ?User $actor = null): void
    {
        DB::transaction(fn () => $this->releaseWithinTransaction($invoice, $actor));
    }

    private function releaseWithinTransaction(LocalInvoice $invoice, ?User $actor = null): void
    {
        $auditActor = $actor ?: User::findOrFail($invoice->supplier_id);
        $receipts = LocalGoodsReceipt::where('current_invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        foreach ($receipts as $receipt) {
            if ($receipt->status !== LocalGoodsReceipt::STATUS_RESERVED) {
                continue;
            }
            $before = $receipt->toArray();
            $receipt->update(['status' => LocalGoodsReceipt::STATUS_AVAILABLE, 'current_invoice_id' => null, 'updated_by' => $actor?->id]);
            app(LocalFinanceAuditService::class)->record($receipt, 'gr_released', $auditActor, $before, $receipt->fresh()->toArray(), ['invoice_id' => $invoice->id]);
        }
        LocalInvoiceGoodsReceipt::where('local_invoice_id', $invoice->id)
            ->where('state', LocalInvoiceGoodsReceipt::STATE_RESERVED)
            ->update(['state' => LocalInvoiceGoodsReceipt::STATE_RELEASED, 'released_at' => now(), 'updated_at' => now()]);
    }

    public function consume(LocalInvoice $invoice, User $actor): void
    {
        DB::transaction(fn () => $this->consumeWithinTransaction($invoice, $actor));
    }

    private function consumeWithinTransaction(LocalInvoice $invoice, User $actor): void
    {
        if (! $invoice->local_purchase_order_id) {
            return; // Grandfathered legacy invoice without authoritative GR mapping.
        }
        $receipts = LocalGoodsReceipt::where('current_invoice_id', $invoice->id)->orderBy('id')->lockForUpdate()->get();
        if ($receipts->isEmpty()) {
            throw ValidationException::withMessages(['goods_receipt_ids' => 'The invoice has no active GR reservation.']);
        }
        foreach ($receipts as $receipt) {
            if ($receipt->status !== LocalGoodsReceipt::STATUS_RESERVED) {
                throw ValidationException::withMessages(['goods_receipt_ids' => "GR {$receipt->gr_number} is not RESERVED by this invoice."]);
            }
            $before = $receipt->toArray();
            $receipt->update(['status' => LocalGoodsReceipt::STATUS_INVOICED, 'updated_by' => $actor->id]);
            app(LocalFinanceAuditService::class)->record($receipt, 'gr_consumed', $actor, $before, $receipt->fresh()->toArray(), ['invoice_id' => $invoice->id]);
        }
        LocalInvoiceGoodsReceipt::where('local_invoice_id', $invoice->id)
            ->where('state', LocalInvoiceGoodsReceipt::STATE_RESERVED)
            ->update(['state' => LocalInvoiceGoodsReceipt::STATE_CONSUMED, 'consumed_at' => now(), 'updated_at' => now()]);
    }

    private function normalizeIds(array $ids): array
    {
        $normalized = array_map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT), $ids);
        if (in_array(false, $normalized, true) || in_array(0, $normalized, true) || count(array_unique($normalized)) !== count($normalized)) {
            throw ValidationException::withMessages(['goods_receipt_ids' => 'Goods Receipt selection contains an invalid or duplicate value.']);
        }
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }
}
