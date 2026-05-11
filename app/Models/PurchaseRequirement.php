<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class PurchaseRequirement extends Model
{
    protected $fillable = [
        'period_id',
        'created_by',
        'pr_number',
        'notes',
        'status',
    ];

    // ─── Helpers ───

    public static function generatePrNumber(): string
    {
        $count = static::whereYear('created_at', now()->year)
            ->whereMonth('created_at', now()->month)
            ->count();

        return 'REQ/' . now()->format('m/Y') . '/' . str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    }

    // ─── Relationships ───

    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrItem::class, 'pr_id');
    }

    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class, 'pr_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable');
    }
}
