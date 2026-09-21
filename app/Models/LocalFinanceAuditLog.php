<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalFinanceAuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }
}
