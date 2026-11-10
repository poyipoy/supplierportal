<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRecordModule extends Model
{
    protected $fillable = [
        'maintenance_record_id',
        'module_key',
        'deactivated_at',
    ];

    protected function casts(): array
    {
        return [
            'deactivated_at' => 'datetime',
        ];
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRecord::class, 'maintenance_record_id')->withTrashed();
    }

    public function isActive(): bool
    {
        return $this->deactivated_at === null;
    }
}
