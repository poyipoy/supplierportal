<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PrItemAward extends Model
{
    use HasHashids;

    protected $fillable = [
        'pr_id',
        'pr_item_id',
        'quotation_id',
        'quotation_item_id',
        'supplier_id',
        'purchase_order_id',
        'awarded_by',
        'awarded_at',
    ];

    // The ordered_* and spec_* columns are server-authoritative and are
    // deliberately not fillable: services write them explicitly (forceFill)
    // under the PO generation lock order, never from request arrays.

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
            'ordered_qty' => 'integer',
            'ordered_weight_per_unit' => 'decimal:4',
            'ordered_total_weight_kg' => 'decimal:4',
            'ordered_price_per_kg' => 'decimal:4',
            'ordered_amount' => 'decimal:4',
            'spec_accepted_at' => 'datetime',
            'spec_acceptance_snapshot' => 'array',
        ];
    }

    // ─── Relationships ───

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'pr_id');
    }

    public function prItem(): BelongsTo
    {
        return $this->belongsTo(PrItem::class, 'pr_item_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class, 'quotation_id');
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class, 'quotation_item_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function awardedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'awarded_by');
    }

    public function progressUpdates(): HasMany
    {
        return $this->hasMany(PoItemProgressUpdate::class, 'pr_item_award_id');
    }

    public function latestProgressUpdate(): HasOne
    {
        return $this->hasOne(PoItemProgressUpdate::class, 'pr_item_award_id')->latestOfMany();
    }

    public function specAcceptedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'spec_accepted_by');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PrItemAwardEvent::class, 'pr_item_award_id');
    }

    public function referencePriceSnapshot(): HasOne
    {
        return $this->hasOne(AwardReferencePriceSnapshot::class, 'pr_item_award_id');
    }

    // ─── Ordered quantity / specification acceptance ───

    /**
     * Values frozen at PO finalization exist (ordered_amount is the marker the
     * reconcile metrics use). Frozen values are never recomputed (I-17).
     */
    public function hasFrozenOrderedValues(): bool
    {
        return $this->ordered_amount !== null;
    }

    /**
     * Any Purchasing selection (ordered quantity or specification acceptance).
     * Mission 04-R guard (b) refuses such awards while the write path is off (I-21).
     */
    public function hasPurchasingSelection(): bool
    {
        return $this->ordered_qty !== null || $this->spec_accepted_at !== null;
    }

    /**
     * SHA-256 of a canonical JSON form: associative keys sorted recursively, so
     * the hash depends on the accepted values, not on array key order.
     */
    public static function specAcceptanceHash(array $snapshot): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        ));
    }

    /**
     * Whether the stored acceptance still covers the current requested/offered
     * difference. A changed offer invalidates the acceptance (AC-04).
     */
    public function isSpecAcceptanceCurrent(array $currentSnapshot): bool
    {
        return $this->spec_accepted_at !== null
            && is_string($this->spec_acceptance_hash)
            && hash_equals($this->spec_acceptance_hash, self::specAcceptanceHash($currentSnapshot));
    }

    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map(fn ($item) => is_array($item) ? self::canonicalize($item) : $item, $value);
    }

    // ─── Scopes ───

    public function scopeUnassignedToPo(Builder $query): Builder
    {
        return $query->whereNull('purchase_order_id');
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }
}
