<?php

namespace App\Support\Procurement;

use App\Models\ExchangeRate;
use App\Models\Quotation;

/**
 * Transactional IDR rate of a document snapshot (Q4C). IDR is the identity
 * rate; a foreign document without a positive snapshot rate is unavailable
 * (null) and must render as blank / "Exchange Rate Unavailable", never 1 or 0.
 * This is the snapshot layer, not the analytical ExchangeRateResolver.
 */
final class SnapshotRate
{
    public static function forQuotation(?Quotation $quotation, ?string $fallbackCurrency = null): ?float
    {
        $currency = strtoupper((string) ($quotation?->currency ?: $fallbackCurrency));
        if ($currency === ExchangeRate::CURRENCY_IDR) {
            return 1.0;
        }

        $rate = $quotation?->exchange_rate?->rate_to_idr;

        return $rate !== null && (float) $rate > 0 ? (float) $rate : null;
    }

    public static function toIdr(float|int|string|null $amount, ?float $rate): ?float
    {
        return $amount === null || $rate === null ? null : (float) $amount * $rate;
    }
}
