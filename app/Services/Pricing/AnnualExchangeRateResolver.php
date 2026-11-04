<?php

namespace App\Services\Pricing;

use App\Models\AnnualExchangeRate;
use App\Models\ExchangeRate;
use App\Support\BusinessTime;
use App\Support\Money;
use DateTimeInterface;
use InvalidArgumentException;

/** Historical-only rates. One immutable catalog per resolver/request, never document snapshots. */
class AnnualExchangeRateResolver
{
    private ?array $catalog = null;

    private array $used = [];

    public function year(DateTimeInterface|string $timestamp): int
    {
        return (int) BusinessTime::toBusiness($timestamp instanceof DateTimeInterface
            ? $timestamp : new \DateTimeImmutable($timestamp, new \DateTimeZone('UTC')))->year;
    }

    public function rate(string $currency, int $year): ?string
    {
        $currency = $this->currency($currency);
        if ($currency === 'IDR') {
            $this->used[$year][$currency] = ['rate' => '1.0000', 'revision' => null];

            return '1.0000';
        }
        $this->catalog ??= AnnualExchangeRate::query()->latestRevisions()->get()
            ->groupBy('year')->map(fn ($rows) => $rows->keyBy('currency')->all())->all();
        $row = $this->catalog[$year][$currency] ?? null;
        $rate = $row?->rate_to_idr;
        $this->used[$year][$currency] = ['rate' => $rate, 'revision' => $row?->revision];

        return $rate;
    }

    public function convert(string $amount, string $from, string $to, int $year, int $scale = 4): ?string
    {
        $from = $this->currency($from);
        $to = $this->currency($to);
        if ($from === $to) {
            return Money::normalize($amount, $scale);
        }
        $source = $this->rate($from, $year);
        $target = $this->rate($to, $year);
        if ($source === null || $target === null) {
            return null;
        }

        $working = max(16, $scale + 8);

        return Money::normalize(bcdiv(bcmul($amount, $source, $working), $target, $working), $scale);
    }

    public function metadata(): array
    {
        ksort($this->used);

        return $this->used;
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (! in_array($currency, ExchangeRate::CURRENCIES, true)) {
            throw new InvalidArgumentException('Unsupported annual exchange-rate currency.');
        }

        return $currency;
    }
}
