<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Reference price captured once at PO finalization (I-19, AC-28). Insert-only;
 * a PO without rows was finalized before the feature and stays "not captured".
 */
class AwardReferencePriceSnapshot extends Model
{
    public const UPDATED_AT = null;

    public const STATUS_MATCHED = 'matched';

    public const STATUS_NOT_AVAILABLE = 'not_available';

    protected $fillable = [
        'pr_item_award_id',
        'purchase_order_id',
        'material_reference_price_id',
        'status',
        'reason_code',
        'evaluation_date',
        'dimension_field',
        'dimension_value',
        'reference_currency',
        'reference_price_per_kg',
        'po_currency',
        'reference_rate_id',
        'po_rate_id',
        'converted_reference_price_per_kg',
        'offer_price_per_kg',
        'diff_amount_per_kg',
        'diff_percent',
    ];

    protected function casts(): array
    {
        return [
            'evaluation_date' => 'date',
            'dimension_value' => 'decimal:4',
            'reference_price_per_kg' => 'decimal:4',
            'converted_reference_price_per_kg' => 'decimal:4',
            'offer_price_per_kg' => 'decimal:4',
            'diff_amount_per_kg' => 'decimal:4',
            'diff_percent' => 'decimal:4',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reference price snapshots are immutable.'));
        static::deleting(fn () => throw new LogicException('Reference price snapshots are immutable.'));
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(PrItemAward::class, 'pr_item_award_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function referencePrice(): BelongsTo
    {
        return $this->belongsTo(MaterialReferencePrice::class, 'material_reference_price_id');
    }
}
