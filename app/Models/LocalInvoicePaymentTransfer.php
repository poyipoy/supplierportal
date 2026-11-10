<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoicePaymentTransfer extends Model
{
    public const TYPE_PRIMARY = 'PRIMARY';

    public const TYPE_CORRECTION = 'CORRECTION';

    protected $fillable = [
        'local_invoice_payment_id',
        'sequence_no',
        'transfer_type',
        'primary_guard',
        'amount',
        'transfer_reference',
        'transfer_date',
        'correction_reason',
        'notes',
        'entered_by',
        'created_at',
        'updated_at',
    ];

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
