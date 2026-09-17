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

    public function getFormattedFileSizeAttribute(): string
    {
        if (!$this->file_size) {
            return '';
        }
        if ($this->file_size >= 1048576) {
            return number_format($this->file_size / 1048576, 1) . ' MB';
        }
        return number_format($this->file_size / 1024, 1) . ' KB';
    }
}
