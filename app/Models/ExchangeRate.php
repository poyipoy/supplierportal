<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    protected $fillable = [
        'currency',
        'rate_to_idr',
        'valid_from',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'rate_to_idr' => 'decimal:4',
            'valid_from' => 'date',
        ];
    }

    // ─── Relationships ───

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helpers ───

    /**
     * Get the latest rate for a given currency.
     */
    public static function latestRate(string $currency): ?self
    {
        return static::where('currency', $currency)
            ->orderBy('valid_from', 'desc')
            ->first();
    }
}
