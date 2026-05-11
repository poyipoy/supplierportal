<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrItem extends Model
{
    protected $fillable = [
        'pr_id',
        'hs_code',
        'material_name',
        'shape',
        'thickness',
        'd_inner',
        'd_outer',
        'width',
        'length',
        'weight_needed',
    ];

    protected function casts(): array
    {
        return [
            'thickness' => 'decimal:4',
            'd_inner' => 'decimal:4',
            'd_outer' => 'decimal:4',
            'width' => 'decimal:4',
            'length' => 'decimal:4',
            'weight_needed' => 'decimal:4',
        ];
    }

    // ─── Relationships ───

    public function purchaseRequirement(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequirement::class, 'pr_id');
    }

    public function quotationItems(): HasMany
    {
        return $this->hasMany(QuotationItem::class, 'pr_item_id');
    }

    public function qcItems(): HasMany
    {
        return $this->hasMany(QcItem::class, 'pr_item_id');
    }
}
