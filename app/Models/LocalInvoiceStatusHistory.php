<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalInvoiceStatusHistory extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
