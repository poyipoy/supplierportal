<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocalGoodsReceipt extends Model
{
    use HasFactory, HasHashids;

    public const STATUS_AVAILABLE = 'AVAILABLE';

    public const STATUS_RESERVED = 'RESERVED';

    public const STATUS_INVOICED = 'INVOICED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'gr_number',
        'local_purchase_order_id',
        'gr_date',
        'qty',
        'description',
        'notes',
        'status',
        'current_invoice_id',
        'source',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'gr_date' => 'date',
        'qty' => 'decimal:4',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(LocalPurchaseOrder::class, 'local_purchase_order_id');
    }

    public function currentInvoice(): BelongsTo
    {
        return $this->belongsTo(LocalInvoice::class, 'current_invoice_id');
    }

    public function getReceivedDateAttribute()
    {
        return $this->gr_date;
    }
}
