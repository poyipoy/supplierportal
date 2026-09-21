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

    /**
     * Commercial lines are award-scoped; quotation-wide lines are legacy only.
     * Keep the source quotation attached for its PR and exchange-rate snapshot.
     */
    public function commercialQuotationItems(): Collection
    {
        $this->loadMissing(['awards', 'quotations.items.prItem']);
        $awardedIds = $this->awards->pluck('quotation_item_id');

        return $this->quotations->sortBy('id')->flatMap(function (Quotation $quotation) use ($awardedIds) {
            return $quotation->items->sortBy('id')
                ->filter(fn (QuotationItem $item) => $this->awards->isEmpty() || $awardedIds->contains($item->id))
                ->map(function (QuotationItem $item) use ($quotation) {
                    return (clone $item)->setRelation('quotation', $quotation);
                });
        })->values();
    }

    /** Group output without changing the original quotation's complete item relation. */
    public function commercialQuotations(): Collection
    {
        return $this->commercialQuotationItems()->groupBy('quotation_id')->map(function (Collection $items) {
            return (clone $items->first()->quotation)->setRelation('items', $items);
        })->values();
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
                ->where(function ($lines) {
                    $lines->whereExists(function ($awards) {
                        $awards->selectRaw('1')->from('pr_item_awards as line_awards')
                            ->whereColumn('line_awards.purchase_order_id', 'purchase_orders.id')
                            ->whereColumn('line_awards.quotation_item_id', 'qi.id');
                    })->orWhereNotExists(function ($awards) {
                        $awards->selectRaw('1')->from('pr_item_awards as po_awards')
                            ->whereColumn('po_awards.purchase_order_id', 'purchase_orders.id');
                    });
                })
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
     */
    public function shipments(): Collection
    {
        return $this->shipmentItems->map(fn ($item) => $item->shipment)->filter()->unique('id')->values();
    }

    /**
     * Aggregate customs documentation status combining PO documents and linked shipment documents.
     *
     * @return array<string, array{
     *     doc_type: string,
     *     label: string,
     *     po_doc: ?PoDocument,
     *     status: string,
     *     shipment_documents: array<int, array{
     *         shipment: Shipment,
     *         shipment_id: int,
     *         shipment_hash: string,
     *         shipment_number: string,
     *         status: string,
     *         document_number: ?string,
     *         attachment: ?Attachment
     *     }>
     * }>
     */
    public function customsDocumentationSummary(): array
    {
        $this->loadMissing([
            'documents',
            'shipmentItems.shipment.documents.latestAttachment',
        ]);

        $docLabels = [
            'invoice' => 'Commercial Invoice',
            'bl' => 'Bill of Lading (B/L)',
            'packing_list' => 'Packing List',
            'form_e' => 'Form-E Certificate',
        ];

        $poDocsByType = $this->documents->keyBy('doc_type');

        $shipments = $this->shipments();

        $summary = [];

        foreach (['invoice', 'bl', 'packing_list', 'form_e'] as $docType) {
            $poDoc = $poDocsByType->get($docType);

            $shipmentDocs = [];
            $highestRank = -1;
            $highestShipmentStatus = null;

            foreach ($shipments as $shipment) {
                $sDoc = $shipment->documents->firstWhere('doc_type', $docType);
                if ($sDoc) {
                    $attachment = $sDoc->relationLoaded('latestAttachment')
                        ? $sDoc->latestAttachment
                        : ($sDoc->attachments()->latest('id')->first() ?? $sDoc->latestAttachment);

                    $docStatus = $sDoc->status;
                    if ($attachment && $docStatus === ShipmentDocument::STATUS_PENDING) {
                        $docStatus = ShipmentDocument::STATUS_RECEIVED;
                    }

                    $rank = PoDocument::STATUS_RANKS[$docStatus] ?? 0;
                    if ($rank > $highestRank) {
                        $highestRank = $rank;
                        $highestShipmentStatus = $docStatus;
                    }

                    $shipmentDocs[] = [
                        'shipment' => $shipment,
                        'shipment_id' => $shipment->id,
                        'shipment_hash' => $shipment->hash,
                        'shipment_number' => $shipment->shipment_number,
                        'status' => $docStatus,
                        'document_number' => $sDoc->document_number,
                        'attachment' => $attachment,
                    ];
                }
            }

            $currentPoRank = PoDocument::STATUS_RANKS[$poDoc?->status ?? 'pending'] ?? 0;
            $effectiveStatus = $poDoc?->status ?? 'pending';

            if ($highestRank > $currentPoRank && $highestShipmentStatus !== null) {
                // Reporting only. Persisting the healed status is the job of
                // reconcileCustomsDocumentationStatus(); a read must not write.
                $effectiveStatus = $highestShipmentStatus;
            }

            $summary[$docType] = [
                'doc_type' => $docType,
                'label' => $docLabels[$docType] ?? ucfirst(str_replace('_', ' ', $docType)),
                'po_doc' => $poDoc,
                'status' => $effectiveStatus,
                'shipment_documents' => $shipmentDocs,
            ];
        }

        return $summary;
    }

    /**
     * Persist any PO document status that its linked shipment documents have
     * already advanced past.
     *
     * This is the write half of customsDocumentationSummary(), split out so the
     * summary itself is a pure read. Call it explicitly from a state-changing
     * path; it is idempotent and returns the number of rows updated.
     */
    public function reconcileCustomsDocumentationStatus(): int
    {
        $summary = $this->customsDocumentationSummary();
        $poDocsByType = $this->documents->keyBy('doc_type');
        $updated = 0;

        foreach ($summary as $docType => $entry) {
            $poDoc = $poDocsByType->get($docType);
            if (! $poDoc || $poDoc->status === $entry['status']) {
                continue;
            }

            $currentRank = PoDocument::STATUS_RANKS[$poDoc->status] ?? 0;
            $effectiveRank = PoDocument::STATUS_RANKS[$entry['status']] ?? 0;

            // Only ever move forward. Reconciliation must not demote a status
            // that purchasing advanced on the PO itself.
            if ($effectiveRank <= $currentRank) {
                continue;
            }

            $poDoc->update(['status' => $entry['status']]);
            $updated++;
        }

        return $updated;
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

    /**
     * Total ordered quantity in pcs for this PO across awards or quotation items.
     * Awarded available_qty takes precedence, with fallback to PR requested quantity.
     */
    public function getTotalOrderedQuantityAttribute(): int
    {
        return $this->awards()->exists()
            ? (int) $this->awards->sum(fn ($a) => $a->quotationItem?->fulfillment_quantity ?? $a->prItem?->quantity_value ?? 0)
            : (int) $this->allQuotationItems()->sum(fn ($qi) => $qi->fulfillment_quantity);
    }

    public function getDeliveryProgressAttribute(): string
    {
        // Legacy POs without any shipment records
        if ($this->shipmentItems()->doesntExist()) {
            return $this->actual_arrival ? 'received' : 'not_shipped';
        }

        $totalOrdered = $this->total_ordered_quantity;

        $arrivedAllocations = (int) $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->where('status', Shipment::STATUS_ARRIVED))
            ->sum('shipped_qty');

        if ($totalOrdered > 0 && $arrivedAllocations >= $totalOrdered) {
            return 'received';
        }

        $activeAllocations = (int) $this->shipmentItems()
            ->whereHas('shipment', fn ($q) => $q->whereIn('status', [Shipment::STATUS_SUBMITTED, Shipment::STATUS_ARRIVED]))
            ->sum('shipped_qty');

        if ($activeAllocations <= 0) {
            return 'not_shipped';
        }

        if ($totalOrdered > 0 && $activeAllocations >= $totalOrdered) {
            return 'fully_shipped';
        }

        return 'partially_shipped';
    }

    /**
     * @deprecated Obsolete ten-thousandth scaling helper retained for backward compatibility.
     * Discrete integer quantities are native; 1 piece equals 1 unit. Do not use in new code.
     */
    public static function quantityToUnits(mixed $value): int
    {
        if (is_int($value)) {
            $normalized = (string) $value;
        } elseif (is_float($value)) {
            $normalized = number_format($value, 4, '.', '');
        } elseif (is_string($value)) {
            $normalized = trim($value);
        } else {
            throw new \InvalidArgumentException('Quantity must be an integer, float, or numeric string.');
        }

        if ($normalized === '') {
            throw new \InvalidArgumentException('Quantity cannot be empty.');
        }

        if (! is_numeric($normalized)) {
            throw new \InvalidArgumentException('Quantity must be numeric.');
        }

        if (str_contains($normalized, '-') || (float) $normalized < 0) {
            throw new \InvalidArgumentException('Quantity must be a positive plain decimal number.');
        }

        if (stripos($normalized, 'e') !== false) {
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

    /**
     * @deprecated Obsolete ten-thousandth scaling helper retained for backward compatibility.
     * Discrete integer quantities are native; 1 piece equals 1 unit. Do not use in new code.
     */
    public static function quantityUnitsToDecimal(int $units): string
    {
        return number_format($units / 10000, 4, '.', '');
    }

    /**
     * Authoritative per-line commercial fulfillment projection.
     *
     * Resolved NG lines are retained as physical history but released from
     * commercial reservation so replacement delivery can be allocated.
     * All fulfillment logic operates on integer quantity (pcs).
     *
     * @return array<string, int|float|bool>
     */
    public function itemFulfillmentStatus(int $quotationItemId, ?int $excludeShipmentId = null): array
    {
        $quotationItem = QuotationItem::with('prItem')->findOrFail($quotationItemId);
        $orderedQty = $quotationItem->fulfillment_quantity;

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

        $physicalShippedQty = 0;
        $physicalArrivedQty = 0;
        $acceptedQty = 0;
        $ngQty = 0;
        $replacementEligibleQty = 0;
        $reservedQty = 0;

        foreach ($query->get() as $shipmentItem) {
            $qty = (int) $shipmentItem->shipped_qty;
            $physicalShippedQty += $qty;

            if ($shipmentItem->shipment?->status !== Shipment::STATUS_ARRIVED) {
                $reservedQty += $qty;

                continue;
            }

            $physicalArrivedQty += $qty;
            $qcItems = $shipmentItem->qcItems->filter(function (QcItem $qcItem) use ($shipmentItem) {
                return $qcItem->inspection
                    && (int) $qcItem->inspection->po_id === (int) $this->id
                    && (int) $qcItem->inspection->shipment_id === (int) $shipmentItem->shipment_id;
            });

            if ($qcItems->isEmpty()) {
                $reservedQty += $qty;

                continue;
            }

            if ($qcItems->contains(fn (QcItem $qcItem) => $qcItem->status === 'ng')) {
                $ngQty += $qty;
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
                    $replacementEligibleQty += $qty;
                } else {
                    $reservedQty += $qty;
                }

                continue;
            }

            if ($qcItems->contains(fn (QcItem $qcItem) => $qcItem->status === 'ok')) {
                $acceptedQty += $qty;
            } else {
                $reservedQty += $qty;
            }
        }

        $allocatedQty = $acceptedQty + $reservedQty;
        $remainingQty = max(0, $orderedQty - $allocatedQty);

        return [
            'ordered_qty' => $orderedQty,
            'physical_shipped_qty' => $physicalShippedQty,
            'physical_arrived_qty' => $physicalArrivedQty,
            'accepted_qty' => $acceptedQty,
            'ng_qty' => $ngQty,
            'replacement_eligible_qty' => $replacementEligibleQty,
            'reserved_qty' => $reservedQty,
            'allocated_qty' => $allocatedQty,
            'remaining_qty' => $remainingQty,

            // Backward compatibility keys
            'ordered_units' => $orderedQty,
            'physical_shipped_units' => $physicalShippedQty,
            'physical_arrived_units' => $physicalArrivedQty,
            'accepted_units' => $acceptedQty,
            'ng_units' => $ngQty,
            'replacement_eligible_units' => $replacementEligibleQty,
            'reserved_units' => $reservedQty,
            'allocated_units' => $allocatedQty,
            'remaining_units' => $remainingQty,
            'ordered' => (float) $orderedQty,
            'physical_shipped' => (float) $physicalShippedQty,
            'physical_arrived' => (float) $physicalArrivedQty,
            'accepted' => (float) $acceptedQty,
            'ng' => (float) $ngQty,
            'replacement_eligible' => (float) $replacementEligibleQty,
            'reserved' => (float) $reservedQty,
            'allocated' => (float) $allocatedQty,
            'remaining' => (float) $remainingQty,

            'is_fully_allocated' => $remainingQty === 0,
            'is_fully_accepted' => $acceptedQty >= $orderedQty,
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

            return $status['accepted_qty'] >= $status['ordered_qty'];
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
        if (in_array($this->status, ['cancelled', 'completed'], true)) {
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
