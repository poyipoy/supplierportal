<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeRate extends Model
{
    public const CURRENCY_USD = 'USD';
    public const CURRENCY_JPY = 'JPY';
    public const CURRENCY_IDR = 'IDR';
    public const CURRENCY_CNY = 'CNY';

    public const CURRENCIES = [
        self::CURRENCY_USD,
        self::CURRENCY_JPY,
        self::CURRENCY_IDR,
        self::CURRENCY_CNY,
    ];

    public const CURRENCY_LABELS = [
        self::CURRENCY_USD => 'USD - US Dollar',
        self::CURRENCY_JPY => 'JPY - Japanese Yen',
        self::CURRENCY_IDR => 'IDR - Indonesian Rupiah',
        self::CURRENCY_CNY => 'CNY - Chinese Yuan',
    ];

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
