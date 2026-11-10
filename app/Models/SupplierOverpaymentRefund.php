<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class SupplierOverpaymentRefund extends Model
{
    use HasHashids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_SETTLED = 'SETTLED';

    protected $fillable = [
        'local_invoice_payment_id',
        'local_invoice_id',
        'supplier_id',
        'overpayment_amount',
        'status',
        'refund_amount',
        'refund_date',
        'notes',
        'created_by',
        'settled_by',
        'settled_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return ['overpayment_amount' => 'decimal:2', 'refund_amount' => 'decimal:2', 'refund_date' => 'date', 'settled_at' => 'datetime'];
    }

    public function payment()
    {
        return $this->belongsTo(LocalInvoicePayment::class, 'local_invoice_payment_id');
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function supplier()
    {
        return $this->belongsTo(User::class, 'supplier_id');
    }

    public function attachments()
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
