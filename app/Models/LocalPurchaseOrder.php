<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class LocalPurchaseOrder extends Model
{
    use HasFactory, HasHashids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const SOURCE_MANUAL = 'MANUAL';

    public const SOURCE_IMPORT = 'IMPORT';

    protected $fillable = [
        'po_number',
        'supplier_id',
        'total_amount',
        'currency',
        'status',
        'description',
        'po_date',
        'source',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'po_date' => 'date',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(LocalGoodsReceipt::class, 'local_purchase_order_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(LocalInvoice::class, 'local_purchase_order_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }

    public function latestPoDocument(): ?Attachment
    {
        return $this->attachments()->latest('id')->first();
    }

    public function getOrderDateAttribute()
    {
        return $this->po_date;
    }

    public function setOrderDateAttribute($value): void
    {
        $this->attributes['po_date'] = $value;
    }
}
