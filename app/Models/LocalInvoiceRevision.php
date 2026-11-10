<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoiceRevision extends Model
{
    protected $fillable = [
        'local_invoice_id',
        'revision_number',
        'invoice_number',
        'invoice_date',
        'po_source',
        'po_number',
        'internal_po_reference',
        'manual_po_number',
        'internal_gr_reference',
        'manual_gr_reference',
        'invoice_amount',
        'tax_amount',
        'tax_invoice_number',
        'ppn_scheme',
        'submitted_ppn_amount',
        'has_po_discrepancy',
        'reason',
        'requested_by',
        'requested_at',
        'resubmitted_at',
        'created_at',
        'updated_at',
    ];

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
