<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoiceReceipt extends Model
{
    protected $fillable = [
        'local_invoice_id',
        'receipt_number',
        'issued_at',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }
}
