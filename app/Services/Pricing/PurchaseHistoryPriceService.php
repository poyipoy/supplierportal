<?php

namespace App\Services\Pricing;

use App\Models\ExchangeRate;
use App\Models\MaterialReferencePrice;
use App\Models\ReferencePriceRule;
use App\Support\BusinessTime;
use App\Support\Money;
use App\Support\Pricing\EvaluationContext;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Purchase history analytics (plan section 12.3, decisions 15-19, Q4, Q9).
 *
 * Eligible lines are PO lines: an award linked to a PO, or, for a legacy PO
 * without awards, its quotation items. The PO is not soft-deleted and not
 * cancelled, the quotation is not soft-deleted, the line is available, and
 * both price per KG and ordered KG are positive. KG and price follow the
 * single ordered-line precedence (frozen, selected, legacy), mirrored in SQL.
 *
 * SQL returns per (period, currency) S = sum(price x kg), K = sum(kg),
 * N = lines, MIN/MAX price. Because the benchmark rate is constant per
 * currency at the single evaluation date, the weighted average in the target
 * currency is exactly sum(convert(S_c)) / sum(K_c) over currencies with a
 * rate. Currencies without a rate are excluded and reported, never 1 or 0.
 * BCMath throughout; rounding only on the final value.
 */
class PurchaseHistoryPriceService
{
    private const SCALE = 4;

    private const WORK = 16;

    /**
     * Ordered price per KG: the frozen price when the line was frozen,
     * otherwise the offer price (I-15 precedence).
     */
    public const PRICE = 'CASE WHEN a.ordered_amount IS NOT NULL THEN a.ordered_price_per_kg ELSE qi.price_per_kg END';

    /** Ordered KG, mirroring OrderedLine::frozen/selected/legacy. */
    public const KG = <<<'SQL'
        CASE
            WHEN a.ordered_amount IS NOT NULL THEN a.ordered_total_weight_kg
            WHEN a.ordered_qty IS NOT NULL THEN ROUND(a.ordered_qty * COALESCE(qi.offered_weight_per_unit, pi.weight_needed, 0), 4)
            WHEN qi.available_qty IS NOT NULL AND qi.offered_weight_per_unit IS NOT NULL THEN ROUND(qi.available_qty * qi.offered_weight_per_unit, 4)
            WHEN qi.available_qty IS NOT NULL THEN ROUND(qi.available_qty * COALESCE(pi.weight_needed, 0), 4)
            ELSE ROUND(COALESCE(pi.weight_needed, 0) * (CASE WHEN pi.quantity IS NULL OR pi.quantity < 1 THEN 1 ELSE pi.quantity END), 4)
        END
        SQL;

    public function __construct(private readonly ExchangeRateResolver $rates) {}

    /**
     * @param  array{material_master_id?: ?int, material_name?: ?string, shape?: ?string, shape_is_null?: bool, key_min?: string|float|null, key_max?: string|float|null, supplier_id?: ?int, date_from?: ?\DateTimeInterface}  $filter
     * @param  'none'|'month'|'year'  $period
     * @return array{currency: string, evaluation_date: string, periods: list<array<string, mixed>>, total: array<string, mixed>, rates: array<string, array{rate: ?string, valid_from: ?string}>}
     */
    public function summarize(array $filter, string $currency, EvaluationContext $context, string $period = 'none', bool $annualRates = false): array
    {
        $currency = $this->currency($currency);
        $periodExpression = $this->periodExpression($period);
        $rateYearExpression = $annualRates ? $this->periodExpression('year') : 'NULL';

        $rows = $this->eligibleLines($filter)
            ->selectRaw("{$periodExpression} as period_key")
            ->selectRaw('q.currency as currency')
            ->selectRaw($rateYearExpression.' as rate_year')
            ->selectRaw('SUM(('.self::PRICE.') * ('.self::KG.')) as s')
            ->selectRaw('SUM('.self::KG.') as k')
            ->selectRaw('COUNT(*) as n')
            ->selectRaw('COUNT(DISTINCT po.id) as pos')
            ->selectRaw('MIN('.self::PRICE.') as mn')
            ->selectRaw('MAX('.self::PRICE.') as mx')
            ->groupByRaw("{$periodExpression}, q.currency, {$rateYearExpression}")
            ->orderByRaw($periodExpression)
            ->get();

        $periods = [];
        foreach ($rows->groupBy('period_key') as $key => $group) {
            $periods[] = ['period' => (string) $key, ...$this->combine($group->all(), $currency, $context)];
        }

        return [
            'currency' => $currency,
            'evaluation_date' => $context->dateString(),
            'periods' => $periods,
            'total' => $this->combine($this->totals($rows->all()), $currency, $context),
            'rates' => $annualRates ? app(AnnualExchangeRateResolver::class)->metadata() : $this->ratesUsed($rows->pluck('currency')->unique()->push($currency)->all(), $context),
        ];
    }

    /**
     * Individual eligible lines (oldest PO first) with the price converted to
     * the target currency at the evaluation date (null when unavailable).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function lines(array $filter, string $currency, EvaluationContext $context, int $page = 1, int $perPage = 50, bool $annualRates = false): array
    {
        $currency = $this->currency($currency);
        $base = $this->eligibleLines($filter);
        $total = (clone $base)->count();
        // One extra preceding row lets the first row of a page report its
        // change against the previous line across the page boundary.
        $offset = (max(1, $page) - 1) * $perPage;
        $start = max(0, $offset - 1);

        $rows = $base
            ->leftJoin('users as supplier', 'supplier.id', '=', 'po.supplier_id')
            ->leftJoin('suppliers as supplier_profile', 'supplier_profile.user_id', '=', 'supplier.id')
            ->leftJoin('purchase_requisitions as pr', 'pr.id', '=', 'pi.pr_id')
            ->select([
                'qi.id as quotation_item_id', 'po.id as po_id', 'po.po_number', 'po.status as po_status', 'po.created_at as po_created_at',
                'q.currency', 'pr.id as pr_id', 'pr.pr_number', 'pi.material_name', 'pi.shape',
            ])
            ->selectRaw('COALESCE(qi.available_thickness, pi.thickness) as effective_thickness')
            ->selectRaw('COALESCE(qi.available_d_outer, pi.d_outer) as effective_d_outer')
            // PUR-03: company name, login name as fallback.
            ->selectRaw("COALESCE(NULLIF(TRIM(supplier_profile.company_name), ''), supplier.name) as supplier_name")
            ->selectRaw(self::PRICE.' as price_per_kg')
            ->selectRaw(self::KG.' as kg')
            ->orderBy('po.created_at')->orderBy('po.id')->orderBy('qi.id')
            ->offset($start)
            ->limit($perPage + ($offset - $start))
            ->get();

        $activeRules = ReferencePriceRule::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('dimension_min')
            ->get();

        $previous = null;
        $mapped = $rows->map(function ($row) use ($currency, $context, $annualRates, $activeRules, &$previous): array {
            $line = $this->mapLine($row, $currency, $context, $annualRates, $activeRules);
            $current = $line['converted_price_per_kg'];
            $line['change_pct'] = $previous !== null && $current !== null && bccomp($previous, '0', self::SCALE) > 0
                ? (float) Money::normalize(bcmul(bcdiv(bcsub($current, $previous, self::WORK), $previous, self::WORK), '100', self::WORK), 2)
                : null;
            if ($current !== null) {
                $previous = $current;
            }

            return $line;
        })->all();

        return [
            'total' => $total,
            'rows' => array_slice($mapped, $offset - $start),
        ];
    }

    /**
     * @param  iterable<int, ReferencePriceRule>|null  $activeRules
     * @return array<string, mixed>
     */
    private function mapLine(object $row, string $currency, EvaluationContext $context, bool $annualRates = false, ?iterable $activeRules = null): array
    {
        $price = Money::normalize((string) $row->price_per_kg, self::SCALE);
        $kg = Money::normalize((string) $row->kg, self::SCALE);
        $year = $annualRates ? app(AnnualExchangeRateResolver::class)->year($row->po_created_at) : null;
        $converted = $this->convert($price, $row->currency, $currency, $context, $year);
        $classification = ReferencePriceRule::resolveDimensionClassification(
            $row->shape ?? null,
            $row->effective_thickness ?? null,
            $row->effective_d_outer ?? null,
            $activeRules
        );

        return [
            'quotation_item_id' => (int) $row->quotation_item_id,
            'po_id' => (int) $row->po_id,
            'po_number' => $row->po_number,
            'po_status' => $row->po_status ?? null,
            'po_created_at' => $row->po_created_at,
            'currency' => $row->currency,
            'supplier' => $row->supplier_name,
            'pr_id' => $row->pr_id === null ? null : (int) $row->pr_id,
            'pr_number' => $row->pr_number,
            'material_name' => $row->material_name ?? '-',
            'shape' => $classification['shape'] ?? ($row->shape ?? null),
            'key_dimension' => $classification['key_dimension'],
            'dimension_display' => $classification['dimension_display'],
            'range_classification' => $classification['range_classification'],
            'category_key' => $classification['category_key'],
            'category_name' => $classification['category_name'],
            'price_per_kg' => $price,
            'kg' => $kg,
            'converted_price_per_kg' => $converted === null ? null : Money::normalize($converted, self::SCALE),
            'converted_total' => $converted === null ? null : Money::normalize(bcmul($converted, $kg, self::WORK), self::SCALE),
            ...($annualRates ? ['rate_year' => $year] : []),
        ];
    }

    /**
     * amount (in $from) expressed in $to at the evaluation date, unrounded at
     * working scale; null when either benchmark rate is unavailable.
     */
    public function convert(string $amount, string $from, string $to, EvaluationContext $context, ?int $annualYear = null): ?string
    {
        if ($annualYear !== null) {
            return app(AnnualExchangeRateResolver::class)->convert($amount, $from, $to, $annualYear, self::WORK);
        }
        // Same currency is the identity: no benchmark rate is needed.
        if (strtoupper($from) === strtoupper($to)) {
            return Money::normalize($amount, self::WORK);
        }

        $fromRate = $this->rates->rateAsOf($from, $context->dateString());
        $toRate = $this->rates->rateAsOf($to, $context->dateString());
        if ($fromRate === null || $toRate === null) {
            return null;
        }

        return bcdiv(bcmul(Money::normalize($amount, self::WORK), $fromRate->rate, self::WORK), $toRate->rate, self::WORK);
    }

    public function eligibleLines(array $filter): Builder
    {
        $query = DB::table('po_quotations as pq')
            ->join('purchase_orders as po', 'po.id', '=', 'pq.po_id')
            ->join('quotations as q', 'q.id', '=', 'pq.quotation_id')
            ->join('quotation_items as qi', 'qi.quotation_id', '=', 'q.id')
            ->join('pr_items as pi', 'pi.id', '=', 'qi.pr_item_id')
            ->leftJoin('pr_item_awards as a', function ($join): void {
                $join->on('a.quotation_item_id', '=', 'qi.id')->on('a.purchase_order_id', '=', 'po.id');
            })
            ->whereNull('po.deleted_at')
            ->where('po.status', '<>', 'cancelled')
            ->whereNull('q.deleted_at')
            ->where(fn ($available) => $available->whereNull('qi.is_available')->orWhere('qi.is_available', true))
            // The award line of this PO, or every line of a legacy PO without awards.
            ->where(fn ($line) => $line->whereNotNull('a.id')->orWhereNotExists(fn ($awards) => $awards
                ->selectRaw('1')->from('pr_item_awards as po_awards')->whereColumn('po_awards.purchase_order_id', 'po.id')))
            ->whereRaw('('.self::PRICE.') > 0')
            ->whereRaw('('.self::KG.') > 0');

        if (! empty($filter['material_master_id']) || ! empty($filter['material_name'])) {
            $query->where(function ($material) use ($filter): void {
                if (! empty($filter['material_master_id'])) {
                    $material->orWhere('pi.material_master_id', (int) $filter['material_master_id']);
                }
                if (! empty($filter['material_name'])) {
                    $material->orWhere('pi.material_name', (string) $filter['material_name']);
                }
            });
        }

        if (! empty($filter['shape'])) {
            $query->where('pi.shape', $filter['shape']);
            $field = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$filter['shape']] ?? null;
            $min = $filter['key_min'] ?? null;
            $max = $filter['key_max'] ?? null;
            if ($field !== null && ($min !== null || $max !== null)) {
                // Decision 18: one key dimension per shape (Hollow ignores the
                // inner diameter). Legacy rows without the offered value use the PR value.
                $spec = "COALESCE(qi.available_{$field}, pi.{$field})";
                if ($min !== null) {
                    $query->whereRaw("{$spec} >= ?", [(string) $min]);
                }
                if ($max !== null) {
                    $query->whereRaw("{$spec} <= ?", [(string) $max]);
                }
            }
        }

        // Opt-in (Price Comparison history): a material recorded without a shape is its own
        // choice. Without this flag an empty shape keeps meaning "any shape".
        if (empty($filter['shape']) && ! empty($filter['shape_is_null'])) {
            $query->whereNull('pi.shape');
        }

        if (! empty($filter['supplier_id'])) {
            $query->where('po.supplier_id', (int) $filter['supplier_id']);
        }
        if (! empty($filter['po_status']) && $filter['po_status'] !== 'all') {
            $query->where('po.status', (string) $filter['po_status']);
        }
        if (! empty($filter['search'])) {
            $term = '%'.trim((string) $filter['search']).'%';
            $query->where(function ($searchQuery) use ($term): void {
                $searchQuery->where('pi.material_name', 'like', $term)
                    ->orWhere('po.po_number', 'like', $term);
            });
        }
        if (! empty($filter['date_from'])) {
            $query->where('po.created_at', '>=', BusinessTime::toStorage($filter['date_from']));
        }
        $month = (! empty($filter['month']) && is_numeric($filter['month']) && (int) $filter['month'] >= 1 && (int) $filter['month'] <= 12)
            ? (int) $filter['month']
            : null;
        if (! empty($filter['year']) && is_numeric($filter['year'])) {
            $year = (int) $filter['year'];
            $tz = BusinessTime::tz();
            $start = $month !== null
                ? Carbon::create($year, $month, 1, 0, 0, 0, $tz)->startOfMonth()
                : Carbon::create($year, 1, 1, 0, 0, 0, $tz)->startOfYear();
            $end = $month !== null
                ? Carbon::create($year, $month, 1, 0, 0, 0, $tz)->endOfMonth()
                : Carbon::create($year, 12, 31, 23, 59, 59, $tz)->endOfYear();

            $query->whereBetween('po.created_at', [
                BusinessTime::toStorage($start),
                BusinessTime::toStorage($end),
            ]);
        } elseif ($month !== null) {
            $offset = (int) BusinessTime::now()->getOffset();
            $query->whereRaw("MONTH(DATE_ADD(po.created_at, INTERVAL {$offset} SECOND)) = ?", [$month]);
        }

        return $query;
    }

    /**
     * @param  list<object>  $rows  per-currency aggregates (s, k, n, pos, mn, mx, currency)
     * @return array<string, mixed>
     */
    private function combine(array $rows, string $target, EvaluationContext $context): array
    {
        $sum = '0';
        $kg = '0';
        $lines = 0;
        $pos = 0;
        $best = null;
        $highest = null;
        $excluded = ['lines' => 0, 'kg' => '0.0000', 'currencies' => []];

        foreach ($rows as $row) {
            $year = ($row->rate_year ?? 0) ? (int) $row->rate_year : null;
            $convertedSum = $this->convert((string) $row->s, $row->currency, $target, $context, $year);
            if ($convertedSum === null) {
                $excluded['lines'] += (int) $row->n;
                $excluded['kg'] = Money::normalize(bcadd($excluded['kg'], (string) $row->k, self::WORK), self::SCALE);
                $excluded['currencies'][] = $row->currency;

                continue;
            }
            $sum = bcadd($sum, $convertedSum, self::WORK);
            $kg = bcadd($kg, (string) $row->k, self::WORK);
            $lines += (int) $row->n;
            $pos += (int) $row->pos;
            $low = $this->convert((string) $row->mn, $row->currency, $target, $context, $year);
            $high = $this->convert((string) $row->mx, $row->currency, $target, $context, $year);
            $best = $best === null || bccomp($low, $best, self::WORK) < 0 ? $low : $best;
            $highest = $highest === null || bccomp($high, $highest, self::WORK) > 0 ? $high : $highest;
        }

        $hasHistory = bccomp($kg, '0', self::WORK) > 0;

        return [
            'weighted_average' => $hasHistory ? Money::normalize(bcdiv($sum, $kg, self::WORK), self::SCALE) : null,
            'best' => $best === null ? null : Money::normalize($best, self::SCALE),
            'highest' => $highest === null ? null : Money::normalize($highest, self::SCALE),
            'line_count' => $lines,
            'po_count' => $pos,
            'total_kg' => Money::normalize($kg, self::SCALE),
            'excluded' => $excluded,
        ];
    }

    /**
     * Collapse period rows into one row per currency.
     *
     * @param  list<object>  $rows
     * @return list<object>
     */
    private function totals(array $rows): array
    {
        $byCurrency = [];
        foreach ($rows as $row) {
            $groupKey = $row->currency.'|'.($row->rate_year ?? 0);
            $current = $byCurrency[$groupKey] ?? (object) ['currency' => $row->currency, 'rate_year' => $row->rate_year ?? 0, 's' => '0', 'k' => '0', 'n' => 0, 'pos' => 0, 'mn' => null, 'mx' => null];
            $current->s = bcadd($current->s, (string) $row->s, self::WORK);
            $current->k = bcadd($current->k, (string) $row->k, self::WORK);
            $current->n += (int) $row->n;
            $current->pos += (int) $row->pos;
            $current->mn = $current->mn === null || bccomp((string) $row->mn, $current->mn, self::WORK) < 0 ? (string) $row->mn : $current->mn;
            $current->mx = $current->mx === null || bccomp((string) $row->mx, $current->mx, self::WORK) > 0 ? (string) $row->mx : $current->mx;
            $byCurrency[$groupKey] = $current;
        }

        return array_values($byCurrency);
    }

    /**
     * @param  list<string>  $currencies
     * @return array<string, array{rate: ?string, valid_from: ?string}>
     */
    private function ratesUsed(array $currencies, EvaluationContext $context): array
    {
        $rates = [];
        foreach (array_unique($currencies) as $currency) {
            $rate = $this->rates->rateAsOf($currency, $context->dateString());
            $rates[$currency] = ['rate' => $rate?->rate, 'valid_from' => $rate?->validFrom];
        }

        return $rates;
    }

    /**
     * Business-calendar period of the PO creation instant (UTC + business offset).
     */
    private function periodExpression(string $period): string
    {
        $offset = (int) BusinessTime::now()->getOffset();
        $local = "DATE_ADD(po.created_at, INTERVAL {$offset} SECOND)";

        return match ($period) {
            'month' => "DATE_FORMAT({$local}, '%Y-%m')",
            'year' => "DATE_FORMAT({$local}, '%Y')",
            'none' => "'all'",
            default => throw new InvalidArgumentException("Unknown period [{$period}]."),
        };
    }

    private function currency(string $currency): string
    {
        $currency = strtoupper(trim($currency));
        if (! in_array($currency, ExchangeRate::CURRENCIES, true)) {
            throw new InvalidArgumentException("Unsupported currency [{$currency}].");
        }

        return $currency;
    }
}
