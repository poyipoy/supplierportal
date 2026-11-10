<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoiceGoodsReceipt extends Model
{
    public const STATE_RESERVED = 'RESERVED';

    public const STATE_CONSUMED = 'CONSUMED';

    public const STATE_RELEASED = 'RELEASED';

    protected $fillable = [
        'local_invoice_id',
        'local_purchase_order_id',
        'local_goods_receipt_id',
        'state',
        'gr_number_snapshot',
        'gr_qty_snapshot',
        'gr_uom_snapshot',
        'reserved_at',
        'consumed_at',
        'released_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'gr_qty_snapshot' => 'decimal:4',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(LocalPurchaseOrder::class, 'local_purchase_order_id');
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(LocalGoodsReceipt::class, 'local_goods_receipt_id');
    }
}
