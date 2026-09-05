<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property Carbon|null $estimated_arrival
 * @property Carbon|null $actual_arrival
 */
class PurchaseOrder extends Model
{
    use HasHashids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    protected $fillable = [
        'supplier_id',
        'currency',
        'exchange_rate_id',
        'po_number',
        'status',
        'created_by',
        'estimated_arrival',
        'actual_arrival',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'estimated_arrival' => 'date',
            'actual_arrival' => 'date',
        ];
    }

    public function getIsOverdueAttribute(): bool
    {
        return $this->status === 'overdue'
            || (
                $this->status === 'active'
                && $this->estimated_arrival
                && $this->estimated_arrival->isBefore(today())
                && ! $this->actual_arrival
            );
    }

    // ─── Relationships ───

    /**
     * Supplier pemilik PO (langsung, bukan lewat quotation).
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    /**
     * Kurs snapshot saat PO dibuat (opsional fallback).
     */
    public function exchangeRate(): BelongsTo
    {
        return $this->belongsTo(ExchangeRate::class, 'exchange_rate_id');
    }

    /**
     * Semua quotation yang termasuk dalam PO ini (Many-to-Many via po_quotations).
     */
    public function quotations(): BelongsToMany
    {
        return $this->belongsToMany(Quotation::class, 'po_quotations', 'po_id', 'quotation_id')
            ->withTimestamps();
    }

    /**
     * Ambil quotation pertama dari relasi many-to-many.
     */
    public function getFirstQuotationAttribute(): ?Quotation
    {
        return $this->quotations->first();
    }

    public function getPrReferenceAttribute(): string
    {
        $reference = $this->purchaseRequisitions()
            ->pluck('pr_number')
            ->filter(fn ($number) => is_string($number) && trim($number) !== '')
            ->map(fn ($number) => trim($number))
            ->unique()
            ->implode(', ');

        return $reference !== '' ? $reference : '-';
    }

    public function scopeWherePrReferenceContains(Builder $query, string $term): Builder
    {
        $term = trim($term);

        if ($term === '') {
            return $query;
        }

        return $query->whereHas('quotations.purchaseRequisition', function (Builder $requisitionQuery) use ($term) {
            $requisitionQuery->where('pr_number', 'like', '%'.$term.'%');
        });
    }

    /**
     * Project the list total while preserving QuotationItem::resolved_amount semantics.
     */
    public function scopeWithResolvedTotalIdr(Builder $query): Builder
    {
        $quantityExpression = <<<'SQL'
            CASE
                WHEN pi.quantity IS NULL OR pi.quantity < 1 THEN 1
                ELSE pi.quantity
            END
            SQL;
        $totalWeightExpression = "ROUND(COALESCE(pi.weight_needed, 0) * ({$quantityExpression}), 4)";
        $resolvedAmountExpression = <<<SQL
            CASE
                WHEN COALESCE(qi.is_available, 1) = 0 THEN 0
                WHEN COALESCE(qi.amount, 0) > 0 THEN qi.amount
                WHEN COALESCE(qi.price_per_kg, 0) > 0
                    AND pi.id IS NOT NULL
                    AND {$totalWeightExpression} > 0
                    THEN ROUND(qi.price_per_kg * {$totalWeightExpression}, 4)
                ELSE COALESCE(qi.amount, 0)
            END
            SQL;

        return $query->selectSub(
            DB::table('po_quotations as links')
                ->join('quotations as q', 'q.id', '=', 'links.quotation_id')
                ->join('quotation_items as qi', 'qi.quotation_id', '=', 'q.id')
                ->leftJoin('pr_items as pi', 'pi.id', '=', 'qi.pr_item_id')
                ->leftJoin('exchange_rates as er', 'er.id', '=', 'q.exchange_rate_id')
                ->whereColumn('links.po_id', 'purchase_orders.id')
                ->whereNull('q.deleted_at')
                ->selectRaw("COALESCE(SUM(({$resolvedAmountExpression}) * COALESCE(er.rate_to_idr, 1)), 0)"),
            'resolved_total_idr',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(PoDocument::class, 'po_id');
    }

    public function qcInspections(): HasMany
    {
        return $this->hasMany(QcInspection::class, 'po_id');
    }

    public function materialClaims(): HasMany
    {
        return $this->hasMany(MaterialClaim::class, 'po_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function awards(): HasMany
    {
        return $this->hasMany(PrItemAward::class, 'purchase_order_id');
    }

    public function shipmentItems(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'purchase_order_id');
    }

    /**
     * Determine whether this PO is still eligible for the historical
     * PO-level arrival workflow.
     *
     * New award-based and shipment-aware POs must be received through a
     * Shipment, even before the first ShipmentItem exists.
     */
    public function isLegacyArrivalEligible(): bool
    {
        return ! $this->awards()->exists()
            && ! $this->shipmentItems()->exists();
    }

    /**
     * Determine whether this PO was received through the legacy PO-level path
     * without an arrived Shipment establishing shipment-based receiving.
     */
    public function hasLegacyOnlyArrivalState(): bool
    {
        if ($this->awards()->exists() || ! $this->actual_arrival) {
            return false;
        }

        return ! $this->shipmentItems()
            ->whereHas('shipment', fn ($query) => $query
                ->withTrashed()
                ->where('status', Shipment::STATUS_ARRIVED))
            ->exists();
    }

    /**
     * Unique shipments associated with this PO.
     *
     * @return Collection<int, Shipment>
     */
    public function shipments(): Collection
    {
        return $this->shipmentItems->map(fn ($item) => $item->shipment)->filter()->unique('id')->values();
    }

    /**
     * Total ordered weight in kg for this PO across awards or quotation items.
     */
    public function getTotalOrderedWeightAttribute(): float
    {
        return $this->awards()->exists()
            ? (float) $this->awards->sum(fn ($a) => (float) ($a->quotationItem?->offered_total_weight ?? $a->prItem?->total_weight ?? 0))
            : (float) $this->allQuotationItems()->sum(fn ($qi) => (float) ($qi->offered_total_weight ?? $qi->prItem?->total_weight ?? 0));
    }

    public function getDeliveryProgressAttribute(): string
    {
        // Legacy POs without any shipment records
        if ($this->shipmentItems()->doesntExist()) {
            return $this->actual_arrival ? 'received' : 'not_shipped';
        }

        $totalOrdered = $this->total_ordered_weight;

        $arrivedAllocations = (float) $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_ARRIVED))
            ->sum('shipped_quantity');

        if ($totalOrdered > 0 && round($arrivedAllocations, 4) >= round($totalOrdered, 4)) {
            return 'received';
        }

        $activeAllocations = (float) $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
            ->sum('shipped_quantity');

        if ($activeAllocations <= 0) {
            return 'not_shipped';
        }

        if ($totalOrdered > 0 && round($activeAllocations, 4) >= round($totalOrdered, 4)) {
            return 'fully_shipped';
        }

        return 'partially_shipped';
    }

    /**
     * Convert a non-negative decimal quantity to exact ten-thousandths.
     */
    public static function quantityToUnits(mixed $value): int
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_float($value)) {
            $normalized = rtrim(rtrim(number_format($value, 10, '.', ''), '0'), '.');
        } elseif (is_string($value)) {
            $normalized = trim($value);
        } else {
            throw new \InvalidArgumentException('Quantity must be a plain decimal number.');
        }

        if (! preg_match('/^\d+(?:\.(\d+))?$/D', $normalized, $matches)) {
            throw new \InvalidArgumentException('Quantity must be a non-negative plain decimal number.');
        }

        $fraction = $matches[1] ?? '';
        if (strlen($fraction) > 4) {
            throw new \InvalidArgumentException('Quantity may have a maximum of 4 decimal places.');
        }

        [$whole] = explode('.', $normalized, 2);
        if (strlen(ltrim($whole, '0')) > 8) {
            throw new \InvalidArgumentException('Quantity exceeds the DECIMAL(12,4) storage limit.');
        }
        $fraction = str_pad($fraction, 4, '0');

        return ((int) $whole * 10000) + (int) $fraction;
    }

    public static function quantityUnitsToDecimal(int $units): string
    {
        return number_format($units / 10000, 4, '.', '');
    }

    /**
     * Authoritative per-line commercial fulfillment projection.
     *
     * Resolved NG lines are retained as physical history but released from
     * commercial reservation so replacement delivery can be allocated.
     *
     * @return array<string, int|float|bool>
     */
    public function itemFulfillmentStatus(int $quotationItemId, ?int $excludeShipmentId = null): array
    {
        $quotationItem = QuotationItem::with('prItem')->findOrFail($quotationItemId);
        $orderedUnits = self::quantityToUnits(
            $quotationItem->offered_total_weight ?? $quotationItem->prItem?->total_weight ?? 0
        );

        $query = $this->shipmentItems()
            ->where('quotation_item_id', $quotationItemId)
            ->whereHas('shipment', fn ($shipmentQuery) => $shipmentQuery
                ->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
            ->with([
                'shipment',
                'qcItems.inspection.materialClaims',
            ])
            ->orderBy('id');

        if ($excludeShipmentId !== null) {
            $query->where('shipment_id', '!=', $excludeShipmentId);
        }

        $physicalShippedUnits = 0;
        $physicalArrivedUnits = 0;
        $acceptedUnits = 0;
        $ngUnits = 0;
        $replacementEligibleUnits = 0;
        $reservedUnits = 0;

        foreach ($query->get() as $shipmentItem) {
            $units = self::quantityToUnits($shipmentItem->shipped_quantity);
            $physicalShippedUnits += $units;

            if ($shipmentItem->shipment?->status !== Shipment::STATUS_ARRIVED) {
                $reservedUnits += $units;

                continue;
            }

            $physicalArrivedUnits += $units;
            $qcItems = $shipmentItem->qcItems->filter(function (QcItem $qcItem) use ($shipmentItem) {
                return $qcItem->inspection
                    && (int) $qcItem->inspection->po_id === (int) $this->id
                    && (int) $qcItem->inspection->shipment_id === (int) $shipmentItem->shipment_id;
            });

            if ($qcItems->isEmpty()) {
                $reservedUnits += $units;

                continue;
            }

            if ($qcItems->contains(fn (QcItem $qcItem) => $qcItem->status === 'ng')) {
                $ngUnits += $units;
                $claims = $qcItems
                    ->map(fn (QcItem $qcItem) => $qcItem->inspection?->materialClaims)
                    ->filter()
                    ->flatten();
                $hasActiveClaim = $claims->contains(fn (MaterialClaim $claim) => in_array(
                    $claim->status,
                    ['pending', 'responded', 'escalated'],
                    true
                ));
                $hasResolvedClaim = $claims->contains(fn (MaterialClaim $claim) => $claim->status === 'resolved');

                if ($hasResolvedClaim && ! $hasActiveClaim) {
                    $replacementEligibleUnits += $units;
                } else {
                    $reservedUnits += $units;
                }

                continue;
            }

            if ($qcItems->contains(fn (QcItem $qcItem) => $qcItem->status === 'ok')) {
                $acceptedUnits += $units;
            } else {
                $reservedUnits += $units;
            }
        }

        $allocatedUnits = $acceptedUnits + $reservedUnits;
        $remainingUnits = max(0, $orderedUnits - $allocatedUnits);

        return [
            'ordered_units' => $orderedUnits,
            'physical_shipped_units' => $physicalShippedUnits,
            'physical_arrived_units' => $physicalArrivedUnits,
            'accepted_units' => $acceptedUnits,
            'ng_units' => $ngUnits,
            'replacement_eligible_units' => $replacementEligibleUnits,
            'reserved_units' => $reservedUnits,
            'allocated_units' => $allocatedUnits,
            'remaining_units' => $remainingUnits,
            'ordered' => (float) self::quantityUnitsToDecimal($orderedUnits),
            'physical_shipped' => (float) self::quantityUnitsToDecimal($physicalShippedUnits),
            'physical_arrived' => (float) self::quantityUnitsToDecimal($physicalArrivedUnits),
            'accepted' => (float) self::quantityUnitsToDecimal($acceptedUnits),
            'ng' => (float) self::quantityUnitsToDecimal($ngUnits),
            'replacement_eligible' => (float) self::quantityUnitsToDecimal($replacementEligibleUnits),
            'reserved' => (float) self::quantityUnitsToDecimal($reservedUnits),
            'allocated' => (float) self::quantityUnitsToDecimal($allocatedUnits),
            'remaining' => (float) self::quantityUnitsToDecimal($remainingUnits),
            'is_fully_allocated' => $remainingUnits === 0,
            'is_fully_accepted' => $acceptedUnits >= $orderedUnits,
        ];
    }

    /**
     * Check if the PO is fully fulfilled with delivered goods that passed QC.
     */
    public function isFullyFulfilledAndInspected(?Shipment $currentOkShipment = null): bool
    {
        // Legacy POs without shipment records
        if ($this->shipmentItems()->doesntExist()) {
            $hasNg = QcInspection::where('po_id', $this->id)->where('status', 'ng')->exists();
            $hasOk = QcInspection::where('po_id', $this->id)->where('status', 'ok')->exists();

            return ! $hasNg && ($hasOk || (bool) $this->actual_arrival);
        }

        $quotationItems = $this->awards()->exists()
            ? $this->awards()->with('quotationItem.prItem')->get()->pluck('quotationItem')->filter()
            : $this->allQuotationItems();

        if ($quotationItems->isEmpty()) {
            return true;
        }

        return $quotationItems->every(function (QuotationItem $quotationItem) {
            $status = $this->itemFulfillmentStatus($quotationItem->id);

            return $status['accepted_units'] >= $status['ordered_units'];
        });
    }

    /**
     * Check if this PO has any arrived shipments that have not yet been inspected.
     */
    public function hasArrivedShipmentsAwaitingQc(?int $excludeShipmentId = null): bool
    {
        $inspectedShipmentIds = QcInspection::where('po_id', $this->id)
            ->whereNotNull('shipment_id')
            ->pluck('shipment_id')
            ->all();

        $query = DB::table('shipment_items')
            ->join('shipments', 'shipments.id', '=', 'shipment_items.shipment_id')
            ->where('shipment_items.purchase_order_id', $this->id)
            ->where('shipments.status', Shipment::STATUS_ARRIVED)
            ->whereNull('shipments.deleted_at');

        if (! empty($inspectedShipmentIds)) {
            $query->whereNotIn('shipments.id', $inspectedShipmentIds);
        }

        if ($excludeShipmentId) {
            $query->where('shipments.id', '!=', $excludeShipmentId);
        }

        return $query->exists();
    }

    /**
     * Reconcile the operational PO state from claims, pending QC, and accepted fulfillment.
     */
    public function reconcileOperationalStatus(): string
    {
        if ($this->status === 'cancelled') {
            return $this->status;
        }

        $hasActiveClaim = $this->materialClaims()
            ->whereIn('status', ['pending', 'responded', 'escalated'])
            ->exists();

        $hasUnresolvedNg = $this->qcInspections()
            ->where('status', 'ng')
            ->where(function ($query) {
                $query->whereDoesntHave('materialClaims', fn ($claimQuery) => $claimQuery
                    ->where('status', 'resolved'))
                    ->orWhereHas('materialClaims', fn ($claimQuery) => $claimQuery
                        ->whereIn('status', ['pending', 'responded', 'escalated']));
            })
            ->exists();

        if ($hasActiveClaim || $hasUnresolvedNg) {
            $target = 'claim_needed';
        } elseif ($this->hasArrivedShipmentsAwaitingQc()) {
            $target = 'waiting_qc';
        } elseif ($this->isFullyFulfilledAndInspected()) {
            $target = 'completed';
        } else {
            $target = $this->status === 'overdue' ? 'overdue' : 'active';
        }

        if ($this->status !== $target) {
            $this->update(['status' => $target]);
        }

        return $target;
    }

    // ─── Helpers ───

    /**
     * Ambil semua quotation items dari semua quotation di PO ini.
     */
    public function allQuotationItems(): Collection
    {
        return $this->quotations->flatMap(function ($quotation) {
            return $quotation->items;
        });
    }

    /**
     * Ambil semua PR terkait PO ini.
     */
    public function purchaseRequisitions(): Collection
    {
        return $this->quotations->map(function ($q) {
            return $q->purchaseRequisition;
        })->filter()->unique('id');
    }

    /**
     * Generate the next PO number for the current month.
     */
    public static function generatePoNumber(): string
    {
        return DB::transaction(function () {
            $year = (int) now()->year;
            $month = (int) now()->month;

            $seq = DB::table('document_sequences')
                ->where('type', 'PO')
                ->where('year', $year)
                ->where('month', $month)
                ->lockForUpdate()
                ->first();

            if ($seq) {
                $next = $seq->last_number + 1;
                DB::table('document_sequences')
                    ->where('id', $seq->id)
                    ->update(['last_number' => $next, 'updated_at' => now()]);
            } else {
                $next = 1;
                DB::table('document_sequences')->insert([
                    'type' => 'PO',
                    'year' => $year,
                    'month' => $month,
                    'last_number' => $next,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return 'PO/'.now()->format('m/Y').'/'.str_pad($next, 3, '0', STR_PAD_LEFT);
        });
    }
}
