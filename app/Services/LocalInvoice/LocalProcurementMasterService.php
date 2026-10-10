<?php

namespace App\Services\LocalInvoice;

use App\Models\LocalGoodsReceipt;
use App\Models\LocalInvoiceGoodsReceipt;
use App\Models\LocalProcurementImport;
use App\Models\LocalPurchaseOrder;
use App\Models\SupplierScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LocalProcurementMasterService
{
    public function __construct(private LocalFinanceAuditService $audit) {}

    public function createImportBatch(User $actor, string $kind, array $records, array $normalized): array
    {
        $this->authorize($actor);
        if (! in_array($kind, LocalProcurementImport::KINDS, true)) {
            throw new \InvalidArgumentException('Unsupported import kind.');
        }
        if (count($records) > (int) config('local_procurement_imports.batch_size') || DB::transactionLevel() === 0) {
            throw new \LogicException('Import batches require a bounded batch inside the import transaction.');
        }
        $valuesByRow = collect($normalized)->keyBy('_row');
        $numbers = array_map(fn ($r) => json_decode($r->values, true)['po_number'], $records);
        // MySQL otherwise chooses a full scan for large IN lists, widening locks.
        $poMap = LocalPurchaseOrder::forceIndex('local_purchase_orders_po_number_unique')->whereIn('po_number', $numbers)->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($po) => mb_strtolower($po->po_number));
        $counts = ['newPo' => 0, 'existingPo' => 0, 'newGr' => 0, 'existingGr' => 0];
        $inserts = [];
        if ($records[0]->kind === 'PO') {
            $supplierIds = array_unique(array_column($normalized, 'supplier_id'));
            $eligible = User::localEligible()->whereIn('id', $supplierIds)->orderBy('id')->sharedLock()->pluck('id')->all();
            $scoped = SupplierScope::whereIn('supplier_id', $supplierIds)->where('scope', 'local')->orderBy('supplier_id')->sharedLock()->pluck('supplier_id')->all();
            $eligible = array_map('intval', array_intersect($eligible, $scoped));
            foreach ($records as $record) {
                $row = $valuesByRow->get($record->source_row);
                $existing = $poMap->get(mb_strtolower($row['po_number']));
                $amount = $this->money($row['po_amount'], 'po_amount');
                if (! in_array((int) $row['supplier_id'], array_map('intval', $eligible), true)) {
                    throw ValidationException::withMessages(['supplier_id' => __('local_procurement.validation.supplier_required')]);
                }
                if ($existing) {
                    if ((int) $existing->supplier_id !== (int) $row['supplier_id'] || $existing->po_date->format('Y-m-d') !== $row['po_date']
                        || bccomp((string) $existing->total_amount, $amount, 2) !== 0) {
                        throw ValidationException::withMessages(['po_number' => __('local_procurement.import.po_existing_conflict', ['po' => $row['po_number']])]);
                    }
                    $counts['existingPo']++;

                    continue;
                }
                $inserts[] = ['po_number' => $row['po_number'], 'supplier_id' => $row['supplier_id'], 'po_date' => $row['po_date'],
                    'total_amount' => $amount, 'currency' => 'IDR', 'status' => LocalPurchaseOrder::STATUS_OPEN,
                    'description' => 'Imported via Infor ERP Purchase Order',
                    'source' => LocalPurchaseOrder::SOURCE_IMPORT, 'created_by' => $actor->id, 'updated_by' => $actor->id,
                    'created_at' => now(), 'updated_at' => now()];
            }
            if ($inserts) {
                LocalPurchaseOrder::insert($inserts);
                $created = LocalPurchaseOrder::forceIndex('local_purchase_orders_po_number_unique')->whereIn('po_number', array_column($inserts, 'po_number'))->get();
                $this->audit->recordCreatedBatch($created, 'po_created', $actor);
                $counts['newPo'] = count($inserts);
            }
        } else {
            $grNumbers = array_map(fn ($r) => json_decode($r->values, true)['gr_number'], $records);
            $existingMap = LocalGoodsReceipt::whereIn('gr_number', $grNumbers)->orderBy('id')->lockForUpdate()->get()->keyBy(fn ($gr) => mb_strtolower($gr->gr_number));
            foreach ($records as $record) {
                $row = $valuesByRow->get($record->source_row);
                $po = $poMap->get(mb_strtolower($row['po_number']));
                if (! $po || $po->status !== LocalPurchaseOrder::STATUS_OPEN) {
                    throw ValidationException::withMessages(['po_number' => __('local_procurement.validation.gr_open')]);
                }
                $qty = trim((string) $row['qty']);
                if (! LocalGoodsReceipt::validQuantity($qty)) {
                    throw ValidationException::withMessages(['qty' => __('local_procurement.validation.quantity_precision')]);
                }
                $uom = $this->uom($row['uom']);
                $existing = $existingMap->get(mb_strtolower($row['gr_number']));
                if ($existing) {
                    if ((int) $existing->local_purchase_order_id !== (int) $po->id
                        || $existing->gr_date->format('Y-m-d') !== $row['gr_date'] || bccomp((string) $existing->qty, $qty, 4) !== 0 || $existing->uom !== $uom) {
                        throw ValidationException::withMessages(['gr_number' => __('local_procurement.import.gr_existing_conflict', ['gr' => $row['gr_number']])]);
                    }
                    $counts['existingGr']++;

                    continue;
                }
                $inserts[] = ['gr_number' => $row['gr_number'], 'local_purchase_order_id' => $po->id, 'gr_date' => $row['gr_date'],
                    'qty' => $qty, 'uom' => $uom, 'description' => $row['description'] ?? null,
                    'notes' => "Imported via Infor ERP Goods Receipt ({$record->source_count} line rows)",
                    'status' => LocalGoodsReceipt::STATUS_AVAILABLE, 'source' => LocalPurchaseOrder::SOURCE_IMPORT,
                    'created_by' => $actor->id, 'updated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()];
            }
            if ($inserts) {
                LocalGoodsReceipt::insert($inserts);
                $created = LocalGoodsReceipt::whereIn('gr_number', array_column($inserts, 'gr_number'))->get();
                $this->audit->recordCreatedBatch($created, 'gr_created', $actor);
                $counts['newGr'] = count($inserts);
            }
        }

        return $counts;
    }

    public function createPurchaseOrder(User $actor, array $data, string $source = LocalPurchaseOrder::SOURCE_MANUAL): LocalPurchaseOrder
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $data, $source) {
            $poNumber = trim((string) ($data['po_number'] ?? ''));
            $poAmount = $this->money($data['total_amount'] ?? null, 'total_amount');
            if ($poNumber === '') {
                throw ValidationException::withMessages(['po_number' => __('local_procurement.validation.po_required')]);
            }
            if (LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [mb_strtolower($poNumber)])->exists()) {
                throw ValidationException::withMessages(['po_number' => __('local_procurement.validation.po_exists')]);
            }
            $supplier = User::localEligible()->whereKey($data['supplier_id'])->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => __('local_procurement.validation.supplier_required')]);
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
                throw ValidationException::withMessages(['status' => __('local_procurement.validation.cancelled_edit')]);
            }
            $hasDependencies = $po->goodsReceipts()->exists() || $po->invoices()->exists();
            $poNumber = trim((string) ($data['po_number'] ?? ''));
            $poAmount = $this->money($data['total_amount'] ?? null, 'total_amount');
            if ($poNumber === '') {
                throw ValidationException::withMessages(['po_number' => __('local_procurement.validation.po_required')]);
            }
            if ($hasDependencies && ((int) $data['supplier_id'] !== (int) $po->supplier_id || $poNumber !== $po->po_number)) {
                throw ValidationException::withMessages(['po_number' => __('local_procurement.validation.po_immutable')]);
            }
            if (LocalPurchaseOrder::whereRaw('LOWER(po_number) = ?', [mb_strtolower($poNumber)])->where('id', '!=', $po->id)->exists()) {
                throw ValidationException::withMessages(['po_number' => __('local_procurement.validation.po_exists')]);
            }
            $supplier = User::localEligible()->whereKey($data['supplier_id'])->first();
            if (! $supplier) {
                throw ValidationException::withMessages(['supplier_id' => __('local_procurement.validation.supplier_required')]);
            }
            $activeInvoiced = (string) $po->invoices()
                ->whereNotIn('status', [LocalInvoice::STATUS_REJECTED, LocalInvoice::STATUS_CANCELLED])
                ->sum('invoice_amount');
            if (bccomp($poAmount, $activeInvoiced, 2) < 0) {
                throw ValidationException::withMessages(['total_amount' => __('local_procurement.validation.ceiling_low')]);
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
                throw ValidationException::withMessages(['status' => __('local_procurement.validation.close_open')]);
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
                throw ValidationException::withMessages(['status' => __('local_procurement.validation.cancel_open')]);
            }
            $blocked = $po->goodsReceipts()->whereIn('status', [LocalGoodsReceipt::STATUS_RESERVED, LocalGoodsReceipt::STATUS_INVOICED])->exists()
                || LocalInvoiceGoodsReceipt::where('local_purchase_order_id', $po->id)->whereIn('state', [LocalInvoiceGoodsReceipt::STATE_RESERVED, LocalInvoiceGoodsReceipt::STATE_CONSUMED])->exists();
            if ($blocked) {
                throw ValidationException::withMessages(['status' => __('local_procurement.validation.reserved_cancel')]);
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
                throw ValidationException::withMessages(['local_purchase_order_id' => __('local_procurement.validation.gr_open')]);
            }
            $grNumber = trim((string) ($data['gr_number'] ?? ''));
            if ($grNumber === '') {
                throw ValidationException::withMessages(['gr_number' => __('local_procurement.validation.gr_required')]);
            }
            if (LocalGoodsReceipt::whereRaw('LOWER(gr_number) = ?', [mb_strtolower($grNumber)])->exists()) {
                throw ValidationException::withMessages(['gr_number' => __('local_procurement.validation.gr_exists')]);
            }
            $qty = trim((string) ($data['qty'] ?? ''));
            if (! LocalGoodsReceipt::validQuantity($qty)) {
                throw ValidationException::withMessages(['qty' => __('local_procurement.validation.quantity_precision')]);
            }
            $uom = $this->uom($data['uom'] ?? null);
            $gr = $po->goodsReceipts()->create([
                'gr_number' => $grNumber, 'gr_date' => $data['gr_date'],
                'qty' => $qty, 'uom' => $uom,
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
                throw ValidationException::withMessages(['status' => __('local_procurement.validation.gr_edit_available')]);
            }
            $grNumber = trim((string) ($data['gr_number'] ?? ''));
            if ($grNumber === '') {
                throw ValidationException::withMessages(['gr_number' => __('local_procurement.validation.gr_required')]);
            }
            if (LocalGoodsReceipt::whereRaw('LOWER(gr_number) = ?', [mb_strtolower($grNumber)])->where('id', '!=', $gr->id)->exists()) {
                throw ValidationException::withMessages(['gr_number' => __('local_procurement.validation.gr_exists')]);
            }
            $qty = trim((string) ($data['qty'] ?? ''));
            if (! LocalGoodsReceipt::validQuantity($qty)) {
                throw ValidationException::withMessages(['qty' => __('local_procurement.validation.quantity_precision')]);
            }
            $uom = $this->uom($data['uom'] ?? null);
            $before = $gr->toArray();
            $gr->update([
                'gr_number' => $grNumber, 'gr_date' => $data['gr_date'],
                'qty' => $qty, 'uom' => $uom,
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
                throw ValidationException::withMessages(['status' => __('local_procurement.validation.gr_cancel_available')]);
            }
            $gr->update(['status' => LocalGoodsReceipt::STATUS_CANCELLED, 'updated_by' => $actor->id]);
            $this->audit->record($gr, 'gr_cancelled', $actor, ['status' => LocalGoodsReceipt::STATUS_AVAILABLE], ['status' => LocalGoodsReceipt::STATUS_CANCELLED]);

            return $gr->fresh();
        });
    }

    private function uom(mixed $value): string
    {
        if (! LocalGoodsReceipt::isSupportedUom($value)) {
            throw ValidationException::withMessages(['uom' => __('local_procurement.validation.uom_required')]);
        }

        return LocalGoodsReceipt::normalizeUom($value);
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
            throw ValidationException::withMessages([$field => __('local_procurement.validation.amount_positive')]);
        }

        if (! str_contains($value, '.')) {
            return $value.'.00';
        }

        [$whole, $fraction] = explode('.', $value, 2);

        return $whole.'.'.str_pad($fraction, 2, '0');
    }
}
