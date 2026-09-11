<?php

namespace App\Models;

use App\Traits\HasHashids;
use Illuminate\Database\Eloquent\Model;

class LocalInvoiceDocument extends Model
{
    use HasHashids;

    protected $guarded = ['id'];

    public function invoice()
    {
        return $this->belongsTo(LocalInvoice::class, 'local_invoice_id');
    }

    public function revision()
    {
        return $this->belongsTo(LocalInvoiceRevision::class, 'local_invoice_revision_id');
    }
}
