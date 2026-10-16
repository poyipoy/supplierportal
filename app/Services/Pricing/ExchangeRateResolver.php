<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Pricing\RateResult;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Analytical exchange-rate layer (Q4): the benchmark rate effective on an
 * evaluation date. Used only for comparisons and reference deltas; document
 * snapshots (exchange_rate_id) and ExchangeRate::latestRate() are untouched.
 *
 * A missing foreign rate is null ("Exchange Rate Unavailable"), never 1 or 0
 * (I-8). Bound as a scoped instance; the memo lives for one request only.
 */
class ExchangeRateResolver
{
    private const RATE_SCALE = 4;

    private const WORKING_SCALE = 16;

    /** @var array<string, RateResult|null> */
    private array $memo = [];

    public function rateAsOf(string $currency, DateTimeInterface|string $date): ?RateResult
    {
        $currency = $this->currency($currency);

        if ($currency === ExchangeRate::CURRENCY_IDR) {
            return new RateResult('1.0000', null, null);
        }

        $day = $this->businessDate($date);
        $key = $currency.'|'.$day;

        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }

        // Same tie-break as ExchangeRate::latestRate(); future-dated and
        // non-positive rows are never selected.
        $row = ExchangeRate::query()
            ->where('currency', $currency)
            ->whereDate('valid_from', '<=', $day)
            ->where('rate_to_idr', '>', 0)
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first(['id', 'rate_to_idr', 'valid_from']);

        return $this->memo[$key] = $row === null ? null : new RateResult(
            Money::normalize((string) $row->rate_to_idr, self::RATE_SCALE),
            (int) $row->id,
            $row->valid_from->toDateString(),
        );
    }

    /**
     * amount x rate(from) / rate(to), exact in BCMath and rounded half-up to 4
     * decimals once at the end. Null when either rate is unavailable.
     */
    public function convert(string|int|float $amount, string $from, string $to, DateTimeInterface|string $date): ?string
    {
        // Same currency is the identity: no benchmark rate is needed.
        if ($this->currency($from) === $this->currency($to)) {
            return Money::normalize($amount, self::RATE_SCALE);
        }

        $fromRate = $this->rateAsOf($from, $date);
        $toRate = $this->rateAsOf($to, $date);

        if ($fromRate === null || $toRate === null) {
            return null;
        }

        $idr = bcmul(Money::normalize($amount, self::WORKING_SCALE), $fromRate->rate, self::WORKING_SCALE);

        return Money::normalize(bcdiv($idr, $toRate->rate, self::WORKING_SCALE), self::RATE_SCALE);
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));

        if (! in_array($currency, ExchangeRate::CURRENCIES, true)) {
            throw new InvalidArgumentException("Unsupported currency [{$currency}].");
        }

        return $currency;
    }

    /**
     * Instants are read on the business calendar; a Y-m-d string is a
     * calendar date already.
     */
    private function businessDate(DateTimeInterface|string $date): string
    {
        return $date instanceof DateTimeInterface
            ? BusinessTime::toBusiness($date)->toDateString()
            : BusinessTime::parseDate($date)->toDateString();
    }
}
