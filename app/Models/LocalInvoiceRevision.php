<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoiceRevision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['invoice_date' => 'date', 'invoice_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'requested_at' => 'datetime', 'resubmitted_at' => 'datetime'];
    }

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function documents()
    {
        return $this->hasMany(LocalInvoiceDocument::class);
    }
}
