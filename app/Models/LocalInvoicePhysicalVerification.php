<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoicePhysicalVerification extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['verified_at' => 'datetime', 'created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
