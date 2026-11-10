<?php

namespace App\Http\Controllers\Purchasing\Concerns;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Pricing\AnnualExchangeRateResolver;
use App\Services\Pricing\ExchangeRateResolver;
use App\Services\RegionalDisplayFormatter;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\Pricing\EvaluationContext;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

trait PriceComparisonSharedTrait
{
    protected ?RegionalDisplayFormatter $regionalFormatter = null;

    protected function ensureRegionalFormatter(): RegionalDisplayFormatter
    {
        return $this->regionalFormatter ??= app(RegionalDisplayFormatter::class);
    }

    /** Analysis currency (decision 20): IDR, USD, JPY or CNY; IDR by default. */
    protected function analysisCurrency(Request $request): string
    {
        $currency = strtoupper(trim((string) $request->input('currency', 'IDR')));

        return in_array($currency, ExchangeRate::CURRENCIES, true) ? $currency : ExchangeRate::CURRENCY_IDR;
    }

    /**
     * Evaluation date (Q9) and the benchmark rate effective on it for every
     * supported currency (memoized; at most four queries per request).
     */
    protected function evaluationMeta(string $currency, EvaluationContext $context, bool $annualRates = false): array
    {
        $this->ensureRegionalFormatter();
        $resolver = app(ExchangeRateResolver::class);
        $rates = [];
        foreach ($annualRates ? [] : ExchangeRate::CURRENCIES as $code) {
            $rate = $resolver->rateAsOf($code, $context->dateString());
            $rates[$code] = [
                'available' => $rate !== null,
                'rate' => $rate?->rate,
                'valid_from' => $rate?->validFrom,
                'valid_from_display' => $rate?->validFrom ? $this->regionalFormatter->date(Carbon::parse($rate->validFrom), 'human') : null,
            ];
        }

        return [
            'date' => $context->dateString(),
            'date_display' => $this->regionalFormatter->date(Carbon::parse($context->dateString()), 'human'),
            'timezone' => config('app.business_timezone', 'Asia/Jakarta'),
            'currency' => $currency,
            'currencies' => ExchangeRate::CURRENCIES,
            'rates' => $rates,
            'unavailable' => collect($rates)->reject(fn (array $rate) => $rate['available'])->keys()->values()->all(),
            ...($annualRates ? ['rate_basis' => 'annual', 'annual_rates' => app(AnnualExchangeRateResolver::class)->metadata(),
                'annual_labels' => $this->annualRateLabels()] : []),
        ];
    }

    protected function annualRateLabels(): array
    {
        $labels = [];
        foreach (app(AnnualExchangeRateResolver::class)->metadata() as $year => $rates) {
            foreach ($rates as $code => $rate) {
                if ($code === 'IDR') {
                    continue;
                }
                $labels[] = ['currency' => $code, 'available' => $rate['rate'] !== null,
                    'label' => $rate['rate'] === null
                        ? __('annual_rates.unavailable', ['currency' => $code, 'year' => $year])
                        : __('annual_rates.conversion', ['currency' => $code, 'year' => $year, 'rate' => $this->formatPrice($rate['rate'], 4), 'revision' => $rate['revision']]),
                ];
            }
        }

        return $labels;
    }

    protected function annualFxDisplay(array $fx): string
    {
        if ($fx['identity']) {
            return __('annual_rates.year').': '.$fx['year'];
        }
        if ($fx['rate'] === null || $fx['target_rate'] === null) {
            return __('annual_rates.unavailable', ['currency' => $fx['rate'] === null ? $fx['currency'] : $fx['target'], 'year' => $fx['year']]);
        }

        return __('annual_rates.conversion', ['currency' => $fx['currency'], 'year' => $fx['year'],
            'rate' => $this->formatPrice($fx['rate'], 4), 'revision' => $fx['revision'] ?? '-'])
            .($fx['target'] === 'IDR' ? '' : ' · '.$fx['target'].' '.$this->formatPrice($fx['target_rate'], 4));
    }

    /**
     * SQL factor converting an amount in the row's currency into $target at the
     * evaluation date: rate(row) / rate(target), NULL when either is missing.
     * Rates are decimal literals from exchange_rates normalized to [0-9.] only.
     */
    protected function benchmarkFactorSql(string $currencyColumn, string $target, EvaluationContext $context): string
    {
        $resolver = app(ExchangeRateResolver::class);
        $targetRate = $resolver->rateAsOf($target, $context->dateString())?->rate;
        $literal = static function (?string $value): string {
            $normalized = Money::normalize((string) $value, 8);
            if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
                throw new \LogicException('Invalid benchmark rate literal.');
            }

            return $normalized;
        };
        $cases = [];
        foreach (ExchangeRate::CURRENCIES as $code) {
            // Same currency is the identity; otherwise both rates are required.
            $rate = $resolver->rateAsOf($code, $context->dateString())?->rate;
            $cases[] = "WHEN '{$code}' THEN ".match (true) {
                $code === $target => '1',
                $rate === null || $targetRate === null => 'NULL',
                default => '('.$literal($rate).' / '.$literal($targetRate).')',
            };
        }

        return "(CASE {$currencyColumn} ".implode(' ', $cases).' ELSE NULL END)';
    }

    /** @param class-string<Model> $modelClass */
    protected function resolveHashedQueryModel(string $modelClass, mixed $value): Model
    {
        abort_unless(is_string($value) && $value !== '' && ! ctype_digit($value), 404);

        try {
            $model = (new $modelClass)->resolveRouteBinding($value);
        } catch (\Throwable) {
            $model = null;
        }

        abort_unless($model instanceof Model, 404);

        return $model;
    }

    /** PUR-03: company name with the login name as fallback (supplier?->company_name ?: name). */
    protected static function supplierDisplayName(?User $user): ?string
    {
        $company = trim((string) $user?->supplier?->company_name);

        return $company !== '' ? $company : $user?->name;
    }

    /** SQL twin of supplierDisplayName() for a users alias and its suppliers alias. */
    protected static function supplierNameSql(string $users, string $profiles): string
    {
        return "COALESCE(NULLIF(TRIM({$profiles}.company_name), ''), {$users}.name)";
    }

    protected function resolveSupplierQuery(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }

        $supplier = $this->resolveHashedQueryModel(User::class, $value);
        abort_unless($supplier instanceof User && $supplier->role === 'supplier', 404);

        return $supplier;
    }

    protected function routeWithReturn(string $routeName, mixed $parameters, string $returnUrl): string
    {
        $parameters = is_array($parameters) ? $parameters : [$parameters];
        $parameters[PurchasingNavigation::RETURN_URL_KEY] = $returnUrl;

        return route($routeName, $parameters);
    }

    protected function priceCompetitivenessStatus(?float $diffPercent, float $competitiveThreshold): array
    {
        if ($diffPercent === null) {
            return [
                'label' => __('status.meta.not_applicable'),
                'class' => 'bg-secondary',
                'icon' => 'circle-minus',
                'recommendation' => __('status.meta.safe'),
            ];
        }

        if ($diffPercent <= 0) {
            return [
                'label' => __('purchasing.copy.best_price'),
                'class' => 'bg-success',
                'icon' => 'check-circle',
                'recommendation' => __('status.meta.safe'),
            ];
        }

        if ($diffPercent <= $competitiveThreshold) {
            return [
                'label' => __('purchasing.copy.competitive'),
                'class' => 'bg-primary',
                'icon' => 'shield-check',
                'recommendation' => __('status.meta.safe'),
            ];
        }

        return [
            'label' => __('purchasing.copy.above_history'),
            'class' => 'bg-warning text-dark',
            'icon' => 'info',
            'recommendation' => __('purchasing.copy.safe_check_context'),
        ];
    }

    protected function formatAnalyticAmount($value, string $currency, bool $signed = false): string
    {
        if ($currency === ExchangeRate::CURRENCY_IDR) {
            return $signed ? $this->formatSignedRupiah($value) : $this->formatRupiah($value);
        }
        if ($value === null) {
            return '-';
        }
        $value = (float) $value;
        $prefix = $signed ? ($value > 0 ? '+' : ($value < 0 ? '-' : '')) : '';

        return $prefix.$currency.' '.$this->formatPrice($signed ? abs($value) : $value, 4);
    }

    /** PUR-06: grouped price in the module's native (Indonesian) presentation, like formatRupiah(). */
    protected function formatPrice($value, int $decimals = 2): string
    {
        $this->ensureRegionalFormatter();

        return $value === null ? '-' : $this->regionalFormatter->groupedDecimal(is_float($value) ? $value : (string) $value, $decimals, 'indonesian');
    }

    protected function formatRupiah($value): string
    {
        if ($value === null) {
            return '-';
        }

        $this->ensureRegionalFormatter();
        $formatted = number_format((float) $value, 0, ',', '.');

        return 'Rp '.$this->regionalFormatter->number($formatted, 'indonesian');
    }

    protected function formatSignedRupiah($value): string
    {
        if ($value === null) {
            return '-';
        }

        $this->ensureRegionalFormatter();
        $value = (float) $value;
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');
        $formatted = number_format(abs($value), 0, ',', '.');

        return $prefix.'Rp '.$this->regionalFormatter->number($formatted, 'indonesian');
    }

    protected function formatNumber($value, int $decimals = 2): string
    {
        if ($value === null) {
            return '-';
        }

        $this->ensureRegionalFormatter();
        $formatted = NumberFormat::maxDecimals($value, $decimals);

        return $this->regionalFormatter->number($formatted, 'international');
    }

    protected function formatPercent($value): string
    {
        if ($value === null) {
            return '-';
        }

        $this->ensureRegionalFormatter();
        $value = (float) $value;
        $prefix = $value > 0 ? '+' : '';
        $formatted = NumberFormat::maxDecimals($value);

        return $prefix.$this->regionalFormatter->number($formatted, 'international').'%';
    }

    protected function formatDate($value): ?string
    {
        if (! $value) {
            return null;
        }

        $this->ensureRegionalFormatter();
        $carbon = $value instanceof \DateTimeInterface
            ? $value
            : \Illuminate\Support\Carbon::parse($value);

        return $this->regionalFormatter->timestamp($carbon, 'date');
    }

    protected function formatUtcDate(?string $timestamp): string
    {
        return $timestamp === null ? '-' : ($this->formatDate(Carbon::parse($timestamp, 'UTC')) ?? '-');
    }

    protected function formatPlainDate(?string $date): string
    {
        $this->ensureRegionalFormatter();

        return $date === null ? '-' : $this->regionalFormatter->date(Carbon::parse($date), 'human');
    }

    protected function signedAmount(?string $value, string $currency): ?string
    {
        if ($value === null) {
            return null;
        }
        $sign = bccomp($value, '0', 4);
        $absolute = ltrim($value, '-');

        return ($sign > 0 ? '+' : ($sign < 0 ? '-' : '')).$currency.' '.$this->formatPrice($absolute, 4);
    }
}
