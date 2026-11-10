<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoicePhysicalVerification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'local_invoice_id',
        'revision_number',
        'status',
        'verified_by',
        'verified_at',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
