<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QcItem extends Model
{
    protected $fillable = [
        'inspection_id',
        'pr_item_id',
        'actual_thickness',
        'actual_d_inner',
        'actual_d_outer',
        'actual_width',
        'actual_length',
        'actual_weight',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'actual_thickness' => 'decimal:4',
            'actual_d_inner' => 'decimal:4',
            'actual_d_outer' => 'decimal:4',
            'actual_width' => 'decimal:4',
            'actual_length' => 'decimal:4',
            'actual_weight' => 'decimal:4',
        ];
    }

    // ─── Relationships ───

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(QcInspection::class, 'inspection_id');
    }

    public function prItem(): BelongsTo
    {
        return $this->belongsTo(PrItem::class, 'pr_item_id');
    }
}
