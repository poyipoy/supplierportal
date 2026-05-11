<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationItem extends Model
{
    protected $fillable = [
        'quotation_id',
        'pr_item_id',
        'price_per_kg',
        'amount',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'price_per_kg' => 'decimal:4',
            'amount' => 'decimal:4',
        ];
    }

    // ─── Relationships ───

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function prItem(): BelongsTo
    {
        return $this->belongsTo(PrItem::class, 'pr_item_id');
    }
}
