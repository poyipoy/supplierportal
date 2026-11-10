<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalFinanceAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'auditable_type',
        'auditable_id',
        'action',
        'actor_id',
        'before_values',
        'after_values',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return ['before_values' => 'array', 'after_values' => 'array', 'metadata' => 'array', 'created_at' => 'datetime'];
    }
}
