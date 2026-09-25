<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalPurchaseOrder;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalProcurementMasterService
{
    public function __construct(private LocalFinanceAuditService $audit) {}

    public function createPurchaseOrder(User $actor, array $data, string $source = LocalPurchaseOrder::SOURCE_MANUAL): LocalPurchaseOrder
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $data, $source) {
            $poNumber = trim((string) ($data['po_number'] ?? ''));
            $poAmount = $this->money($data['total_amount'] ?? null, 'total_amount');
            if ($poNumber === '') {
                throw ValidationException::withMessages(['po_number' => 'PO Number is required.']);
            }
            if (LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [mb_strtolower($poNumber)])->exists()) {
                throw ValidationException::withMessages(['po_number' => 'This PO Number already exists.']);
            }
            $supplier = User::localEligible()->whereKey($data['supplier_id'])->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Select an active Local Supplier.']);
            }
            $po = LocalPurchaseOrder::create([
                'po_number' => $poNumber, 'supplier_id' => $supplier->id,
                'po_date' => $data['po_date'], 'total_amount' => $poAmount,
                'currency' => 'IDR', 'description' => $data['description'] ?? null,
                'status' => LocalPurchaseOrder::STATUS_OPEN, 'source' => $source,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->audit->record($po, 'po_created', $actor, null, $po->toArray());

            return $po;
        });
    }

    public function updatePurchaseOrder(User $actor, LocalPurchaseOrder $purchaseOrder, array $data): LocalPurchaseOrder
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $purchaseOrder, $data) {
            $po = LocalPurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($po->status === LocalPurchaseOrder::STATUS_CANCELLED) {
                throw ValidationException::withMessages(['status' => 'A cancelled PO cannot be edited.']);
            }
            $hasDependencies = $po->goodsReceipts()->exists() || $po->invoices()->exists();
            $poNumber = trim((string) ($data['po_number'] ?? ''));
            $poAmount = $this->money($data['total_amount'] ?? null, 'total_amount');
            if ($poNumber === '') {
                throw ValidationException::withMessages(['po_number' => 'PO Number is required.']);
            }
            if ($hasDependencies && ((int) $data['supplier_id'] !== (int) $po->supplier_id || $poNumber !== $po->po_number)) {
                throw ValidationException::withMessages(['po_number' => 'PO number and supplier are immutable after GR or invoice usage.']);
            }
            if (LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [mb_strtolower($poNumber)])->where('id', '!=', $po->id)->exists()) {
                throw ValidationException::withMessages(['po_number' => 'This PO Number already exists.']);
            }
            $supplier = User::localEligible()->whereKey($data['supplier_id'])->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => 'Select an active Local Supplier.']);
            }
            $activeInvoiced = (string) $po->invoices()
                ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])
                ->sum('invoice_amount');
            if (bccomp($poAmount, $activeInvoiced, 2) < 0) {
                throw ValidationException::withMessages(['total_amount' => 'PO Amount cannot be lower than the active invoiced total.']);
            }
            $before = $po->toArray();
            $po->update([
                'po_number' => $poNumber, 'supplier_id' => $supplier->id,
                'po_date' => $data['po_date'], 'total_amount' => $poAmount,
                'description' => $data['description'] ?? null, 'updated_by' => $actor->id,
            ]);
            $this->audit->record($po, 'po_updated', $actor, $before, $po->fresh()->toArray());

            return $po->fresh();
        });
    }

    public function closePurchaseOrder(User $actor, LocalPurchaseOrder $purchaseOrder): LocalPurchaseOrder
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $purchaseOrder) {
            $po = LocalPurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== LocalPurchaseOrder::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => 'Only an OPEN PO can be closed.']);
            }
            $po->update(['status' => LocalPurchaseOrder::STATUS_CLOSED, 'updated_by' => $actor->id]);
            $this->audit->record($po, 'po_closed', $actor, ['status' => LocalPurchaseOrder::STATUS_OPEN], ['status' => LocalPurchaseOrder::STATUS_CLOSED]);

            return $po->fresh();
        });
    }

    public function cancelPurchaseOrder(User $actor, LocalPurchaseOrder $purchaseOrder): LocalPurchaseOrder
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $purchaseOrder) {
            $po = LocalPurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== LocalPurchaseOrder::STATUS_OPEN) {
                throw ValidationException::withMessages(['status' => 'Only an OPEN PO can be cancelled.']);
            }
            $blocked = $po->goodsReceipts()->whereIn('status', [LocalGoodsReceipt::STATUS_RESERVED, LocalGoodsReceipt::STATUS_INVOICED])->exists()
                || LocalInvoiceGoodsReceipt::where('local_purchase_order_id', $po->id)->whereIn('state', [LocalInvoiceGoodsReceipt::STATE_RESERVED, LocalInvoiceGoodsReceipt::STATE_CONSUMED])->exists();
            if ($blocked) {
                throw ValidationException::withMessages(['status' => 'PO cannot be cancelled because it has a reserved or invoiced GR.']);
            }
            $po->update(['status' => LocalPurchaseOrder::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            $po->goodsReceipts()->where('status', LocalGoodsReceipt::STATUS_AVAILABLE)
                ->update(['status' => LocalGoodsReceipt::STATUS_CANCELLED, 'updated_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->record($po, 'po_cancelled', $actor, ['status' => LocalPurchaseOrder::STATUS_OPEN], ['status' => LocalPurchaseOrder::STATUS_CANCELLED]);

            return $po->fresh();
        });
    }

    public function createGoodsReceipt(User $actor, LocalPurchaseOrder $purchaseOrder, array $data, string $source = LocalPurchaseOrder::SOURCE_MANUAL): LocalGoodsReceipt
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $purchaseOrder, $data, $source) {
            $po = LocalPurchaseOrder::whereKey($purchaseOrder->id)->lockForUpdate()->firstOrFail();
            if ($po->status !== LocalPurchaseOrder::STATUS_OPEN) {
                throw ValidationException::withMessages(['local_purchase_order_id' => 'Goods Receipts may only be added to an OPEN PO.']);
            }
            $grNumber = trim((string) ($data['gr_number'] ?? ''));
            if ($grNumber === '') {
                throw ValidationException::withMessages(['gr_number' => 'GR Number is required.']);
            }
            if (LocalGoodsReceipt::whereRaw('LOWER(gr_number) = ?', [mb_strtolower($grNumber)])->exists()) {
                throw ValidationException::withMessages(['gr_number' => 'This GR Number already exists.']);
            }
            $qty = isset($data['qty']) ? (float) $data['qty'] : 0.0;
            if ($qty <= 0) {
                throw ValidationException::withMessages(['qty' => 'Quantity must be a positive number.']);
            }
            $gr = $po->goodsReceipts()->create([
                'gr_number' => $grNumber, 'gr_date' => $data['gr_date'],
                'qty' => $qty,
                'description' => $data['description'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => LocalGoodsReceipt::STATUS_AVAILABLE, 'source' => $source,
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->audit->record($gr, 'gr_created', $actor, null, $gr->toArray());

            return $gr;
        });
    }

    public function updateGoodsReceipt(User $actor, LocalGoodsReceipt $goodsReceipt, array $data): LocalGoodsReceipt
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $goodsReceipt, $data) {
            $gr = LocalGoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->firstOrFail();
            $po = LocalPurchaseOrder::whereKey($gr->local_purchase_order_id)->lockForUpdate()->firstOrFail();
            if ($gr->status !== LocalGoodsReceipt::STATUS_AVAILABLE) {
                throw ValidationException::withMessages(['status' => 'Only an AVAILABLE GR can be edited.']);
            }
            $grNumber = trim((string) ($data['gr_number'] ?? ''));
            if ($grNumber === '') {
                throw ValidationException::withMessages(['gr_number' => 'GR Number is required.']);
            }
            if (LocalGoodsReceipt::whereRaw('LOWER(gr_number) = ?', [mb_strtolower($grNumber)])->where('id', '!=', $gr->id)->exists()) {
                throw ValidationException::withMessages(['gr_number' => 'This GR Number already exists.']);
            }
            $qty = (isset($data['qty']) && $data['qty'] !== null && $data['qty'] !== '') ? (float) $data['qty'] : (float) ($gr->qty ?? 0);
            if ($qty <= 0) {
                throw ValidationException::withMessages(['qty' => 'Quantity must be a positive number.']);
            }
            $before = $gr->toArray();
            $gr->update([
                'gr_number' => $grNumber, 'gr_date' => $data['gr_date'],
                'qty' => $qty,
                'description' => array_key_exists('description', $data) ? $data['description'] : $gr->description,
                'notes' => $data['notes'] ?? null,
                'updated_by' => $actor->id,
            ]);
            $this->audit->record($gr, 'gr_updated', $actor, $before, $gr->fresh()->toArray());

            return $gr->fresh();
        });
    }

    public function cancelGoodsReceipt(User $actor, LocalGoodsReceipt $goodsReceipt): LocalGoodsReceipt
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $goodsReceipt) {
            $gr = LocalGoodsReceipt::whereKey($goodsReceipt->id)->lockForUpdate()->firstOrFail();
            if ($gr->status !== LocalGoodsReceipt::STATUS_AVAILABLE) {
                throw ValidationException::withMessages(['status' => 'Only an AVAILABLE GR can be cancelled.']);
            }
            $gr->update(['status' => LocalGoodsReceipt::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            $this->audit->record($gr, 'gr_cancelled', $actor, ['status' => LocalGoodsReceipt::STATUS_AVAILABLE], ['status' => LocalGoodsReceipt::STATUS_CANCELLED]);

            return $gr->fresh();
        });
    }

    private function authorize(User $actor): void
    {
        if (! $actor->is_active || ! ($actor->isFinance() || $actor->isAdmin() || $actor->isPurchasing())) {
            abort(403);
        }
    }

    private function money(mixed $value, string $field): string
    {
        $value = trim((string) $value);
        if (! preg_match('/^\d{1,18}(?:\.\d{1,2})?$/', $value) || bccomp($value, '0', 2) <= 0) {
            throw ValidationException::withMessages([$field => 'Amount must be a positive number with up to two decimal places.']);
        }

        if (! str_contains($value, '.')) {
            return $value.'.00';
        }

        [$whole, $fraction] = explode('.', $value, 2);

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
