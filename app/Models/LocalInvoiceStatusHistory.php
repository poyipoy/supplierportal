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

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (! $model->created_at) {
                $model->created_at = now();
            }
        });
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
