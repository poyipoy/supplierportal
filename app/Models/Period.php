<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Period extends Model
{
    protected $fillable = [
        'name',
        'month',
        'year',
        'status',
        'created_by',
    ];

    public function getIsAnnualAttribute(): bool
    {
        return $this->month === null;
    }

    public function getDisplayLabelAttribute(): string
    {
        return $this->is_annual
            ? (string) $this->year
            : "{$this->name} (".str_pad((string) $this->month, 2, '0', STR_PAD_LEFT)."/{$this->year})";
    }

    // ─── Relationships ───

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function purchaseRequisitions(): HasMany
    {
        return $this->hasMany(PurchaseRequisition::class, 'period_id');
    }
}
