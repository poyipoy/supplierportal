<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class LocalInvoicePayment extends Model
{
    use HasHashids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_CORRECTION_REQUIRED = 'CORRECTION_REQUIRED';

    public const STATUS_FINALIZED = 'FINALIZED';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['expected_amount' => 'decimal:2', 'actual_paid_total' => 'decimal:2', 'finalized_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function voucher()
    {
        return $this->belongsTo(LocalInvoiceVoucher::class, 'local_invoice_voucher_id');
    }

    public function item()
    {
        return $this->belongsTo(PaymentItem::class, 'payment_item_id');
    }

    public function transfers()
    {
        return $this->hasMany(LocalInvoicePaymentTransfer::class);
    }

    public function overpayment()
    {
        return $this->hasOne(SupplierOverpaymentRefund::class);
    }
}
