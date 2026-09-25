<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoicePaymentTransfer extends Model
{
    public const TYPE_PRIMARY = 'PRIMARY';

    public const TYPE_CORRECTION = 'CORRECTION';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transfer_date' => 'date'];
    }

    public function payment()
    {
        return $this->belongsTo(LocalInvoicePayment::class, 'local_invoice_payment_id');
    }

    public function enteredBy()
    {
        return $this->belongsTo(User::class, 'entered_by');
    }
}
