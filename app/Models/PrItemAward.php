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

    protected function casts(): array
    {
        return [
            'awarded_at' => 'datetime',
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
