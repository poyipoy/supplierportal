<?php

namespace App\Models;

use App\Support\BusinessTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

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

    public static function currencyLabel(string $currency): string
    {
        $term = match ($currency) {
            self::CURRENCY_USD => 'currency_usd',
            self::CURRENCY_JPY => 'currency_jpy',
            self::CURRENCY_IDR => 'currency_idr',
            self::CURRENCY_CNY => 'currency_cny',
            default => null,
        };

        return $term === null ? $currency : $currency.' - '.__('terms.'.$term);
    }

    public static function currencyOptions(): array
    {
        $options = [];

        foreach (self::CURRENCIES as $currency) {
            $options[$currency] = self::currencyLabel($currency);
        }

        return $options;
    }

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

    // ─── Boot ───

    protected static function booted(): void
    {
        static::creating(function (ExchangeRate $rate): void {
            $rate->currency = strtoupper($rate->currency);
            if ($rate->currency === self::CURRENCY_IDR) {
                $rate->rate_to_idr = '1.0000';
            }
        });
        // Otomatis invalidate cache saat kurs baru di-INSERT
        static::created(function (ExchangeRate $rate) {
            static::clearLatestRateCache($rate->currency);
        });
    }

    // ─── Relationships ───

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ─── Helpers ───

    /**
     * Get the latest rate for a given currency (cached for 60 minutes).
     */
    public static function latestRate(string $currency): ?self
    {
        $currency = strtoupper($currency);
        $businessDate = BusinessTime::today()->toDateString();
        $cacheKey = 'exchange_rate_latest_'.$currency.'_'.$businessDate;

        return Cache::remember($cacheKey, now()->addMinutes(60), function () use ($currency, $businessDate) {
            return static::where('currency', $currency)
                ->whereDate('valid_from', '<=', $businessDate)
                ->when($currency === self::CURRENCY_IDR, fn ($query) => $query->where('rate_to_idr', '1.0000'))
                ->orderBy('valid_from', 'desc')
                ->orderBy('id', 'desc')
                ->first();
        });
    }

    /**
     * Clear the cached latest rate for a specific currency.
     */
    public static function clearLatestRateCache(string $currency): void
    {
        Cache::forget('exchange_rate_latest_'.strtoupper($currency));
        Cache::forget('exchange_rate_latest_'.strtoupper($currency).'_'.BusinessTime::today()->toDateString());
    }
}
