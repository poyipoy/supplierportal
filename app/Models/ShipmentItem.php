<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'shipment_id',
        'purchase_order_id',
        'quotation_item_id',
        'pr_item_award_id',
        'shipped_quantity',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'shipped_quantity' => 'decimal:4',
        ];
    }

    // ─── Relationships ───

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function quotationItem(): BelongsTo
    {
        return $this->belongsTo(QuotationItem::class, 'quotation_item_id');
    }

    public function award(): BelongsTo
    {
        return $this->belongsTo(PrItemAward::class, 'pr_item_award_id');
    }

    public function qcItems(): HasMany
    {
        return $this->hasMany(QcItem::class, 'shipment_item_id');
    }
}
