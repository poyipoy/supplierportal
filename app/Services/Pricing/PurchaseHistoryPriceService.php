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
        [$priceMin, $priceMax] = $this->priceBounds($filter);

        if ($priceMin !== null || $priceMax !== null) {
            return $this->summarizeWithPriceBounds($filter, $currency, $context, $period, $annualRates, $priceMin, $priceMax);
        }

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
     * Exact BCMath line-level aggregation when an analysis-currency price/kg bound is active.
     *
     * @return array{currency: string, evaluation_date: string, periods: list<array<string, mixed>>, total: array<string, mixed>, rates: array<string, array{rate: ?string, valid_from: ?string}>}
     */
    private function summarizeWithPriceBounds(
        array $filter,
        string $currency,
        EvaluationContext $context,
        string $period,
        bool $annualRates,
        ?string $priceMin,
        ?string $priceMax
    ): array {
        $periodExpression = $this->periodExpression($period);
        $rateYearExpression = $annualRates ? $this->periodExpression('year') : 'NULL';

        $rows = $this->eligibleLines($filter)
            ->selectRaw("{$periodExpression} as period_key")
            ->selectRaw('q.currency as currency')
            ->selectRaw($rateYearExpression.' as rate_year')
            ->selectRaw('po.id as po_id')
            ->selectRaw(self::PRICE.' as price_per_kg')
            ->selectRaw(self::KG.' as kg')
            ->orderByRaw($periodExpression)
            ->get();

        $newBucket = static fn (): array => [
            'sum' => '0',
            'kg' => '0',
            'lines' => 0,
            'po_ids' => [],
            'best' => null,
            'highest' => null,
            'excluded' => ['lines' => 0, 'kg' => '0.0000', 'currencies' => []],
        ];
        $finalizeBucket = static function (array $bucket): array {
            $hasHistory = bccomp($bucket['kg'], '0', self::WORK) > 0;

            return [
                'weighted_average' => $hasHistory ? Money::normalize(bcdiv($bucket['sum'], $bucket['kg'], self::WORK), self::SCALE) : null,
                'best' => $bucket['best'] === null ? null : Money::normalize($bucket['best'], self::SCALE),
                'highest' => $bucket['highest'] === null ? null : Money::normalize($bucket['highest'], self::SCALE),
                'line_count' => $bucket['lines'],
                'po_count' => count($bucket['po_ids']),
                'total_kg' => Money::normalize($bucket['kg'], self::SCALE),
                'excluded' => [
                    'lines' => $bucket['excluded']['lines'],
                    'kg' => Money::normalize($bucket['excluded']['kg'], self::SCALE),
                    'currencies' => array_values(array_unique($bucket['excluded']['currencies'])),
                ],
            ];
        };

        $periodBuckets = [];
        $totalBucket = $newBucket();
        $seenCurrencies = [];

        foreach ($rows as $row) {
            $pKey = (string) $row->period_key;
            $seenCurrencies[] = (string) $row->currency;
            $year = ($row->rate_year ?? 0) ? (int) $row->rate_year : null;
            $price = Money::normalize((string) $row->price_per_kg, self::SCALE);
            $kg = Money::normalize((string) $row->kg, self::SCALE);
            $converted = $this->convert($price, (string) $row->currency, $currency, $context, $year);

            if ($converted === null) {
                $periodBuckets[$pKey] ??= $newBucket();
                $periodBuckets[$pKey]['excluded']['lines']++;
                $periodBuckets[$pKey]['excluded']['kg'] = bcadd($periodBuckets[$pKey]['excluded']['kg'], $kg, self::WORK);
                $periodBuckets[$pKey]['excluded']['currencies'][] = (string) $row->currency;

                $totalBucket['excluded']['lines']++;
                $totalBucket['excluded']['kg'] = bcadd($totalBucket['excluded']['kg'], $kg, self::WORK);
                $totalBucket['excluded']['currencies'][] = (string) $row->currency;

                continue;
            }

            if (! $this->convertedPriceMatchesBounds($converted, $priceMin, $priceMax)) {
                continue;
            }

            $lineSum = bcmul($converted, $kg, self::WORK);
            $poId = (int) $row->po_id;

            $periodBuckets[$pKey] ??= $newBucket();
            foreach ([&$periodBuckets[$pKey], &$totalBucket] as &$bucket) {
                $bucket['sum'] = bcadd($bucket['sum'], $lineSum, self::WORK);
                $bucket['kg'] = bcadd($bucket['kg'], $kg, self::WORK);
                $bucket['lines']++;
                $bucket['po_ids'][$poId] = true;
                $bucket['best'] = $bucket['best'] === null || bccomp($converted, $bucket['best'], self::WORK) < 0 ? $converted : $bucket['best'];
                $bucket['highest'] = $bucket['highest'] === null || bccomp($converted, $bucket['highest'], self::WORK) > 0 ? $converted : $bucket['highest'];
            }
            unset($bucket);
        }

        ksort($periodBuckets);
        $periods = [];
        foreach ($periodBuckets as $pKey => $bucket) {
            if ($bucket['lines'] === 0 && $bucket['excluded']['lines'] === 0) {
                continue;
            }
            $periods[] = ['period' => (string) $pKey, ...$finalizeBucket($bucket)];
        }

        return [
            'currency' => $currency,
            'evaluation_date' => $context->dateString(),
            'periods' => $periods,
            'total' => $finalizeBucket($totalBucket),
            'rates' => $annualRates ? app(AnnualExchangeRateResolver::class)->metadata() : $this->ratesUsed(array_merge($seenCurrencies, [$currency]), $context),
        ];
    }

    /**
     * Individual eligible lines (oldest PO first by default, or newest PO first
     * when $filter['sort_direction'] === 'desc') with the price converted to
     * the target currency at the evaluation date (null when unavailable).
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function lines(array $filter, string $currency, EvaluationContext $context, int $page = 1, int $perPage = 50, bool $annualRates = false): array
    {
        $currency = $this->currency($currency);
        [$priceMin, $priceMax] = $this->priceBounds($filter);
        $direction = self::normalizeChangeDirection($filter['change_direction'] ?? null);
        $sortDirection = strtolower(trim((string) ($filter['sort_direction'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';
        $requiresFullScan = $priceMin !== null || $priceMax !== null || $direction !== null || $sortDirection === 'desc';

        $base = $this->eligibleLines($filter);
        $offset = (max(1, $page) - 1) * $perPage;

        $query = $base
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
            ->orderBy('po.created_at')->orderBy('po.id')->orderBy('qi.id');

        $activeRules = ReferencePriceRule::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('dimension_min')
            ->get();

        if ($requiresFullScan) {
            $rows = $query->get();
            $previousByMaterial = [];
            $mapped = [];

            foreach ($rows as $row) {
                $line = $this->mapLine($row, $currency, $context, $annualRates, $activeRules);
                if (! $this->convertedPriceMatchesBounds($line['converted_price_per_kg'], $priceMin, $priceMax)) {
                    continue;
                }
                $materialKey = ($line['material_name'] ?? '').'|'.($line['shape'] ?? '');
                $previous = $previousByMaterial[$materialKey] ?? null;
                $current = $line['converted_price_per_kg'];
                $line['change_pct'] = $previous !== null && $current !== null && bccomp($previous, '0', self::SCALE) > 0
                    ? (float) Money::normalize(bcmul(bcdiv(bcsub($current, $previous, self::WORK), $previous, self::WORK), '100', self::WORK), 2)
                    : null;
                if ($current !== null) {
                    $previousByMaterial[$materialKey] = $current;
                }

                if (self::matchesChangeDirection($line['change_pct'], $direction)) {
                    $mapped[] = $line;
                }
            }

            if ($sortDirection === 'desc') {
                $mapped = array_reverse($mapped);
            }

            return [
                'total' => count($mapped),
                'rows' => array_slice($mapped, $offset, $perPage),
            ];
        }

        $total = (clone $base)->count();
        // One extra preceding row lets the first row of a page report its
        // change against the previous line across the page boundary.
        $start = max(0, $offset - 1);
        $rows = $query
            ->offset($start)
            ->limit($perPage + ($offset - $start))
            ->get();

        $previousByMaterial = [];
        $mapped = $rows->map(function ($row) use ($currency, $context, $annualRates, $activeRules, &$previousByMaterial): array {
            $line = $this->mapLine($row, $currency, $context, $annualRates, $activeRules);
            $materialKey = ($line['material_name'] ?? '').'|'.($line['shape'] ?? '');
            $previous = $previousByMaterial[$materialKey] ?? null;
            $current = $line['converted_price_per_kg'];
            $line['change_pct'] = $previous !== null && $current !== null && bccomp($previous, '0', self::SCALE) > 0
                ? (float) Money::normalize(bcmul(bcdiv(bcsub($current, $previous, self::WORK), $previous, self::WORK), '100', self::WORK), 2)
                : null;
            if ($current !== null) {
                $previousByMaterial[$materialKey] = $current;
            }

            return $line;
        })->all();

        return [
            'total' => $total,
            'rows' => array_slice($mapped, $offset - $start),
        ];
    }

    public static function normalizeChangeDirection(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $clean = strtolower(trim($value));

        return in_array($clean, ['increased', 'decreased', 'unchanged'], true) ? $clean : null;
    }

    public static function matchesChangeDirection(?float $changePct, ?string $direction): bool
    {
        return match ($direction) {
            'increased' => $changePct !== null && $changePct > 0.0,
            'decreased' => $changePct !== null && $changePct < 0.0,
            'unchanged' => $changePct !== null && abs($changePct) < 0.000001,
            default => true,
        };
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function priceBounds(array $filter): array
    {
        $min = isset($filter['price_min']) && $filter['price_min'] !== '' && $filter['price_min'] !== null && is_numeric($filter['price_min']) && (float) $filter['price_min'] >= 0
            ? Money::normalize((string) $filter['price_min'], self::SCALE)
            : null;
        $max = isset($filter['price_max']) && $filter['price_max'] !== '' && $filter['price_max'] !== null && is_numeric($filter['price_max']) && (float) $filter['price_max'] >= 0
            ? Money::normalize((string) $filter['price_max'], self::SCALE)
            : null;
        if ($min !== null && $max !== null && bccomp($min, $max, self::SCALE) > 0) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    private function convertedPriceMatchesBounds(?string $converted, ?string $min, ?string $max): bool
    {
        if ($min === null && $max === null) {
            return true;
        }
        if ($converted === null) {
            return false;
        }
        $normalized = Money::normalize($converted, self::SCALE);
        if ($min !== null && bccomp($normalized, $min, self::SCALE) < 0) {
            return false;
        }
        if ($max !== null && bccomp($normalized, $max, self::SCALE) > 0) {
            return false;
        }

        return true;
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

        if (! empty($filter['materials']) && is_array($filter['materials'])) {
            $min = $filter['key_min'] ?? null;
            $max = $filter['key_max'] ?? null;
            $query->where(function ($outer) use ($filter, $min, $max): void {
                foreach ($filter['materials'] as $spec) {
                    $outer->orWhere(function ($branch) use ($spec, $min, $max): void {
                        $branch->where(function ($material) use ($spec): void {
                            if (! empty($spec['master_id'])) {
                                $material->orWhere('pi.material_master_id', (int) $spec['master_id']);
                            }
                            if (! empty($spec['name'])) {
                                $material->orWhere('pi.material_name', (string) $spec['name']);
                            }
                        });

                        $shape = $spec['shape'] ?? null;
                        if ($shape !== null && $shape !== '') {
                            $branch->where('pi.shape', $shape);
                            $field = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$shape] ?? null;
                            if ($field !== null && ($min !== null || $max !== null)) {
                                $specSql = "COALESCE(qi.available_{$field}, pi.{$field})";
                                if ($min !== null) {
                                    $branch->whereRaw("{$specSql} >= ?", [(string) $min]);
                                }
                                if ($max !== null) {
                                    $branch->whereRaw("{$specSql} <= ?", [(string) $max]);
                                }
                            }
                        } elseif (array_key_exists('shape', $spec) && $spec['shape'] === null) {
                            $branch->whereNull('pi.shape');
                        }
                    });
                }
            });
        } else {
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
        }

        if (! empty($filter['category_ranges']) && is_array($filter['category_ranges'])) {
            $ranges = array_values(array_filter(
                $filter['category_ranges'],
                static fn ($r): bool => is_array($r) && isset($r['min'], $r['max'])
            ));
            if ($ranges !== []) {
                $query->where(function ($catQuery) use ($ranges): void {
                    $catQuery->where(function ($flat) use ($ranges): void {
                        $flat->where('pi.shape', 'Flat')
                            ->whereRaw('COALESCE(qi.available_thickness, pi.thickness) > 0')
                            ->where(function ($bounds) use ($ranges): void {
                                foreach ($ranges as $range) {
                                    $bounds->orWhereRaw(
                                        'CEIL(COALESCE(qi.available_thickness, pi.thickness)) BETWEEN ? AND ?',
                                        [(int) $range['min'], (int) $range['max']]
                                    );
                                }
                            });
                    })->orWhere(function ($cyl) use ($ranges): void {
                        $cyl->whereIn('pi.shape', ['Round', 'Hollow'])
                            ->whereRaw('COALESCE(qi.available_d_outer, pi.d_outer) > 0')
                            ->where(function ($bounds) use ($ranges): void {
                                foreach ($ranges as $range) {
                                    $bounds->orWhereRaw(
                                        'CEIL(COALESCE(qi.available_d_outer, pi.d_outer)) BETWEEN ? AND ?',
                                        [(int) $range['min'], (int) $range['max']]
                                    );
                                }
                            });
                    });
                });
            }
        }

        if (! empty($filter['source_currencies']) && is_array($filter['source_currencies'])) {
            $currencies = array_values(array_filter(array_map(
                static fn ($c): string => strtoupper(trim((string) $c)),
                $filter['source_currencies']
            )));
            if ($currencies !== []) {
                $query->whereIn('q.currency', $currencies);
            }
        }

        if (! empty($filter['document_search'])) {
            $rawDoc = trim((string) $filter['document_search']);
            if ($rawDoc !== '') {
                $like = '%'.addcslashes($rawDoc, '\\%_').'%';
                $query->where(function ($docQuery) use ($like): void {
                    $docQuery->where('po.po_number', 'like', $like)
                        ->orWhereExists(function ($prExists) use ($like): void {
                            $prExists->selectRaw('1')
                                ->from('purchase_requisitions as pr_doc_search')
                                ->whereColumn('pr_doc_search.id', 'pi.pr_id')
                                ->where('pr_doc_search.pr_number', 'like', $like);
                        });
                });
            }
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
        if (! empty($filter['date_to'])) {
            $query->where('po.created_at', '<=', BusinessTime::toStorage($filter['date_to']));
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
