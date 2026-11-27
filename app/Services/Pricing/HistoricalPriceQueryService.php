<?php

namespace App\Services\Pricing;

use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\ExchangeRate;
use App\Models\MaterialReferencePrice;
use App\Models\ReferencePriceRule;
use App\Support\HashidCodec;
use App\Support\Money;
use App\Support\Pricing\EvaluationContext;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class HistoricalPriceQueryService
{
    use PriceComparisonSharedTrait;

    public const HISTORICAL_TABLE_PER_PAGE = 50;

    public const HISTORICAL_KEY_OPTION_LIMIT = 100;

    public const MAX_MULTI_MATERIALS = 10;

    public static function encodeMaterialKey(string $name, ?string $shape): string
    {
        return $name.'|'.($shape ?? '');
    }

    /**
     * @return array{name: string, shape: ?string}
     */
    public static function parseMaterialKey(string $encoded): array
    {
        $encoded = trim($encoded);
        $pos = strrpos($encoded, '|');
        if ($pos === false) {
            return ['name' => $encoded, 'shape' => null];
        }

        $name = trim(substr($encoded, 0, $pos));
        $shape = trim(substr($encoded, $pos + 1));

        return [
            'name' => $name,
            'shape' => $shape !== '' ? $shape : null,
        ];
    }

    public function historicalMaterialsForSupplier($supplierId): Collection
    {
        $cases = collect(MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE)
            ->map(fn (string $field, string $shape) => "WHEN '{$shape}' THEN COALESCE(qi.available_{$field}, pi.{$field})")
            ->implode(' ');
        $keyValue = "CASE pi.shape {$cases} ELSE NULL END";

        $materials = app(PurchaseHistoryPriceService::class)
            ->eligibleLines(array_filter(['supplier_id' => $supplierId]))
            ->whereNotNull('pi.material_name')
            ->where('pi.material_name', '<>', '')
            ->select('pi.material_name', 'pi.shape')
            ->selectRaw("{$keyValue} as key_value")
            ->selectRaw('MAX(pi.material_master_id) as master_id')
            ->groupBy('pi.material_name', 'pi.shape')
            ->groupByRaw($keyValue)
            ->orderBy('pi.material_name')
            ->orderBy('pi.shape')
            ->get()
            ->groupBy(fn ($row) => $row->material_name."\u{0}".$row->shape)
            ->map(function (Collection $rows) {
                $first = $rows->first();
                $options = $rows->pluck('key_value')
                    ->reject(fn ($value) => $value === null)
                    ->map(fn ($value) => Money::normalize((string) $value, 4))
                    ->unique()
                    ->sort(fn (string $a, string $b) => bccomp($a, $b, 4))
                    ->take(self::HISTORICAL_KEY_OPTION_LIMIT)
                    ->values()
                    ->all();
                $master = $rows->pluck('master_id')->filter()->max();

                return [
                    'name' => $first->material_name,
                    'shape' => $first->shape,
                    'master_id' => $master === null ? null : (int) $master,
                    'key_options' => $options,
                ];
            })
            ->values();

        $nameCounts = $materials->countBy('name');

        return $materials->map(fn (array $material) => $material + [
            'key' => self::encodeMaterialKey($material['name'], $material['shape']),
            'label' => $nameCounts[$material['name']] > 1 && $material['shape'] !== null
                ? $material['name'].' ('.$material['shape'].')'
                : $material['name'],
        ]);
    }

    public function selectHistoricalMaterial(Collection $materials, ?string $name, ?string $shape): ?array
    {
        if ($name === null || $name === '') {
            return null;
        }
        $candidates = $materials->where('name', $name);

        return $candidates->first(fn (array $material) => (string) $material['shape'] === (string) $shape) ?? $candidates->first();
    }

    /**
     * Resolve one or more selected (material_name, shape) pairs from `materials[]`
     * with transparent fallback to legacy single-material `material_name` + `shape`.
     *
     * @return list<array<string, mixed>>
     */
    public function selectHistoricalMaterials(Collection $materials, Request $request, int $max = self::MAX_MULTI_MATERIALS): array
    {
        $rawList = $request->input('materials', []);
        if (is_string($rawList) && trim($rawList) !== '') {
            $rawList = [$rawList];
        }

        $selected = [];
        $seen = [];

        if (is_array($rawList) && $rawList !== []) {
            foreach ($rawList as $entry) {
                if (! is_scalar($entry)) {
                    continue;
                }
                $raw = trim((string) $entry);
                if ($raw === '') {
                    continue;
                }
                $parsed = self::parseMaterialKey($raw);
                $matched = $this->selectHistoricalMaterial($materials, $parsed['name'], $parsed['shape']);
                if ($matched === null) {
                    continue;
                }
                $key = $matched['key'] ?? self::encodeMaterialKey($matched['name'], $matched['shape']);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $selected[] = $matched;
                if (count($selected) >= $max) {
                    break;
                }
            }

            return $selected;
        }

        $legacy = $this->selectHistoricalMaterial($materials, $request->input('material_name'), $request->input('shape'));

        return $legacy !== null ? [$legacy] : [];
    }

    public static function historicalMaterialOption(array $material): array
    {
        return [
            'key' => $material['key'] ?? self::encodeMaterialKey($material['name'], $material['shape']),
            'name' => $material['name'],
            'shape' => $material['shape'],
            'label' => $material['label'],
            'key_options' => $material['key_options'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $selectedMaterials
     * @return array{field: ?string, min: ?string, max: ?string}
     */
    public function keyDimensionRangeForMaterials(Request $request, array $selectedMaterials): array
    {
        $fields = collect($selectedMaterials)
            ->map(fn (array $m) => MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$m['shape'] ?? ''] ?? null)
            ->filter()
            ->unique()
            ->values();

        if ($fields->isEmpty()) {
            return ['field' => null, 'min' => null, 'max' => null];
        }

        $singleField = $fields->count() === 1 ? $fields->first() : null;
        [$min, $max] = $this->keyDimensionRange($request, $singleField ?? 'thickness');

        return [
            'field' => $singleField ?? 'mixed',
            'min' => $min,
            'max' => $max,
        ];
    }

    public function keyDimensionRange(Request $request, ?string $field): array
    {
        if ($field === null) {
            return [null, null];
        }
        $read = function ($value): ?string {
            $value = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

            return $value !== '' && is_numeric($value) && (float) $value >= 0 ? Money::normalize($value, 4) : null;
        };
        $min = $read($request->input('key_min', $request->input($field)));
        $max = $read($request->input('key_max'));
        $min ??= $max;
        $max ??= $min;
        if ($min !== null && bccomp($min, $max, 4) > 0) {
            [$min, $max] = [$max, $min];
        }

        return [$min, $max];
    }

    /**
     * Active ReferencePriceRule categories for Supporting Data filtering.
     *
     * @return Collection<int, array{key: string, label: string, sublabel: string, dimension_min: int, dimension_max: int}>
     */
    public function activeCategoryOptions(): Collection
    {
        return ReferencePriceRule::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('dimension_min')
            ->get()
            ->map(function (ReferencePriceRule $rule): array {
                $rangeSpan = "{$rule->dimension_min}-{$rule->dimension_max} mm";
                $label = str_contains($rule->category_name, (string) $rule->dimension_min)
                    ? $rule->category_name
                    : "{$rule->category_name} ({$rangeSpan})";

                return [
                    'key' => (string) $rule->category_key,
                    'label' => $label,
                    'sublabel' => $rangeSpan,
                    'dimension_min' => (int) $rule->dimension_min,
                    'dimension_max' => (int) $rule->dimension_max,
                ];
            })
            ->values();
    }

    /**
     * Extract and normalize Supporting Data modal refinement filters from the request.
     *
     * @param  Collection<int, array{key: string, label: string, sublabel: string, dimension_min: int, dimension_max: int}>  $activeCategories
     * @return array{
     *     categories: list<string>,
     *     category_ranges: list<array{min: int, max: int}>,
     *     doc_search: string,
     *     source_currencies: list<string>,
     *     start_date: ?string,
     *     end_date: ?string,
     *     price_min: ?string,
     *     price_max: ?string,
     *     change_direction: ?string
     * }
     */
    public function extractSupportingFilters(Request $request, Collection $activeCategories): array
    {
        $categoryMap = $activeCategories->keyBy('key');
        $rawCategories = $request->input('categories', []);
        if (is_string($rawCategories)) {
            $rawCategories = trim($rawCategories) !== '' ? [trim($rawCategories)] : [];
        }
        $categories = [];
        $categoryRanges = [];
        if (is_array($rawCategories)) {
            foreach ($rawCategories as $rawKey) {
                if (! is_scalar($rawKey)) {
                    continue;
                }
                $key = trim((string) $rawKey);
                if ($key === '' || ! $categoryMap->has($key) || in_array($key, $categories, true)) {
                    continue;
                }
                $rule = $categoryMap->get($key);
                $categories[] = $key;
                $categoryRanges[] = [
                    'min' => (int) $rule['dimension_min'],
                    'max' => (int) $rule['dimension_max'],
                ];
            }
        }

        $rawDoc = $request->input('doc_search');
        $docSearch = is_scalar($rawDoc) ? mb_substr(trim((string) $rawDoc), 0, 100) : '';

        $rawCurrencies = $request->input('source_currencies', []);
        if (is_string($rawCurrencies)) {
            $rawCurrencies = trim($rawCurrencies) !== '' ? [trim($rawCurrencies)] : [];
        }
        $sourceCurrencies = [];
        if (is_array($rawCurrencies)) {
            foreach ($rawCurrencies as $rawCurrency) {
                if (! is_scalar($rawCurrency)) {
                    continue;
                }
                $code = strtoupper(trim((string) $rawCurrency));
                if ($code !== '' && in_array($code, ExchangeRate::CURRENCIES, true) && ! in_array($code, $sourceCurrencies, true)) {
                    $sourceCurrencies[] = $code;
                }
            }
        }

        $parseDate = static function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }
            $trimmed = trim($value);
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $trimmed)) {
                return null;
            }
            try {
                $parsed = Carbon::createFromFormat('Y-m-d', $trimmed);

                return $parsed && $parsed->format('Y-m-d') === $trimmed ? $trimmed : null;
            } catch (\Throwable) {
                return null;
            }
        };
        $startDate = $parseDate($request->input('start_date'));
        $endDate = $parseDate($request->input('end_date'));
        if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $parseMoney = static function (mixed $value): ?string {
            $clean = is_string($value) || is_numeric($value) ? trim((string) $value) : '';

            return $clean !== '' && is_numeric($clean) && (float) $clean >= 0
                ? Money::normalize($clean, 4)
                : null;
        };
        $priceMin = $parseMoney($request->input('price_min'));
        $priceMax = $parseMoney($request->input('price_max'));
        if ($priceMin !== null && $priceMax !== null && bccomp($priceMin, $priceMax, 4) > 0) {
            [$priceMin, $priceMax] = [$priceMax, $priceMin];
        }

        $changeDirection = PurchaseHistoryPriceService::normalizeChangeDirection($request->input('change_direction'));

        return [
            'categories' => $categories,
            'category_ranges' => $categoryRanges,
            'doc_search' => $docSearch,
            'source_currencies' => $sourceCurrencies,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'price_min' => $priceMin,
            'price_max' => $priceMax,
            'change_direction' => $changeDirection,
        ];
    }

    /**
     * Count distinct active filter fields (1 per rendered chip).
     */
    public static function countActiveSupportingFilters(array $supporting): int
    {
        $count = 0;
        if (! empty($supporting['categories'])) {
            $count++;
        }
        if (trim((string) ($supporting['doc_search'] ?? '')) !== '') {
            $count++;
        }
        if (! empty($supporting['source_currencies'])) {
            $count++;
        }
        if (($supporting['start_date'] ?? null) !== null || ($supporting['end_date'] ?? null) !== null) {
            $count++;
        }
        if (($supporting['price_min'] ?? null) !== null || ($supporting['price_max'] ?? null) !== null) {
            $count++;
        }
        if (($supporting['change_direction'] ?? null) !== null) {
            $count++;
        }

        return $count;
    }

    public function buildHistoricalData(array $filter, string $periodView, string $currency, EvaluationContext $context, int $page): array
    {
        $this->ensureRegionalFormatter();
        $service = app(PurchaseHistoryPriceService::class);
        $history = $service->summarize($filter, $currency, $context, $periodView === 'yearly' ? 'year' : 'month', true);
        $total = $history['total'];
        $empty = ['average_change_pct' => null, 'total_change_pct' => null];

        if ($total['line_count'] === 0 && $total['excluded']['lines'] === 0) {
            return [null, collect(), null, $empty];
        }

        $periodRows = $this->appendChangePercent(collect($history['periods'])->map(fn (array $period) => [
            'period' => $periodView === 'yearly'
                ? $period['period']
                : $this->regionalFormatter->monthYear(Carbon::createFromFormat('Y-m-d', $period['period'].'-01')),
            'period_sort' => $period['period'],
            'price_per_kg' => null,
            'currency' => $currency,
            'price_idr' => $period['weighted_average'] === null ? null : (float) $period['weighted_average'],
            'min_idr' => $period['best'] === null ? null : (float) $period['best'],
            'max_idr' => $period['highest'] === null ? null : (float) $period['highest'],
            'line_count' => $period['line_count'],
            'po_count' => $period['po_count'],
            'total_kg' => $period['total_kg'],
            'submitted_at' => null,
        ]));

        $chartData = [
            'type' => $periodView,
            'currency' => $currency,
            'labels' => $periodRows->pluck('period')->values(),
            'prices' => [],
            'pricesIdr' => $periodRows->pluck('price_idr')->values(),
            'minIdr' => $periodRows->pluck('min_idr')->values(),
            'maxIdr' => $periodRows->pluck('max_idr')->values(),
        ];
        $summary = [
            ...$this->buildHistoricalSummary($periodRows),
            'weighted_average' => $total['weighted_average'],
            'best' => $total['best'],
            'line_count' => $total['line_count'],
            'po_count' => $total['po_count'],
            'total_kg' => $total['total_kg'],
            'excluded' => $total['excluded'],
        ];

        if ($periodView === 'yearly') {
            return [$chartData, $periodRows, null, $summary];
        }

        $totalRows = $total['line_count'] + $total['excluded']['lines'];
        $lastPage = max(1, (int) ceil($totalRows / self::HISTORICAL_TABLE_PER_PAGE));
        $page = min(max(1, $page), $lastPage);
        $lines = $service->lines(
            array_merge($filter, ['sort_direction' => self::normalizeSortDirection($filter['sort_direction'] ?? null)]),
            $currency,
            $context,
            $page,
            self::HISTORICAL_TABLE_PER_PAGE,
            true,
        );

        $tableData = collect($lines['rows'])->map(function (array $line) use ($currency) {
            $purchaseAt = $line['po_created_at'] ? Carbon::parse($line['po_created_at'], 'UTC') : null;

            return [
                'period' => $purchaseAt ? $this->regionalFormatter->monthYear($purchaseAt) : __('purchasing.copy.unknown_period'),
                'period_sort' => $purchaseAt?->format('Y-m-d H:i:s'),
                'purchase_order_id' => $line['po_id'],
                'purchase_order_number' => $line['po_number'],
                'purchase_order_at' => $purchaseAt?->toIso8601String(),
                'purchase_order_at_display' => $purchaseAt ? $this->regionalFormatter->timestamp($purchaseAt, 'date') : '-',
                'pr_id' => $line['pr_id'],
                'pr_number' => $line['pr_number'] ?? '-',
                'pr_url' => $line['pr_id'] ? route('purchasing.requisitions.show', HashidCodec::encode($line['pr_id'])) : null,
                'material_name' => $line['material_name'] ?? '-',
                'shape' => $line['shape'] ?? null,
                'dimension_display' => $line['dimension_display'] ?? '-',
                'range_classification' => $line['range_classification'] ?? '-',
                'supplier' => $line['supplier'] ?? '-',
                'price_per_kg' => (float) $line['price_per_kg'],
                'currency' => $line['currency'],
                'kg' => (float) $line['kg'],
                'price_idr' => $line['converted_price_per_kg'] === null ? null : (float) $line['converted_price_per_kg'],
                'annual_price_display' => ($line['converted_price_per_kg'] === null ? __('purchasing.comparison_analytics.rate_unavailable') : $currency.' '.$this->formatPrice($line['converted_price_per_kg'], 4).'/kg').' · '.__('annual_rates.year').' '.$line['rate_year'],
                'total_idr' => $line['converted_total'] === null ? null : (float) $line['converted_total'],
                'min_idr' => null,
                'max_idr' => null,
                'submitted_at' => $purchaseAt?->toIso8601String(),
                'submitted_at_display' => $purchaseAt ? $this->regionalFormatter->timestamp($purchaseAt, 'date') : '-',
                'change_pct' => $line['change_pct'],
            ];
        });
        $firstRow = (($page - 1) * self::HISTORICAL_TABLE_PER_PAGE) + 1;

        return [$chartData, $tableData, [
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => self::HISTORICAL_TABLE_PER_PAGE,
            'total' => $totalRows,
            'from' => $firstRow,
            'to' => min($totalRows, $firstRow + self::HISTORICAL_TABLE_PER_PAGE - 1),
        ], $summary];
    }

    /**
     * Build multi-material (1..N) historical chart series, KPI summary, and supporting data.
     * Preserves single-material payload keys when count($selectedMaterials) === 1.
     *
     * @param  list<array<string, mixed>>  $selectedMaterials
     */
    public function buildMultiHistoricalData(
        array $selectedMaterials,
        array $baseFilter,
        string $periodView,
        string $currency,
        EvaluationContext $context,
        int $page
    ): array {
        $this->ensureRegionalFormatter();
        $service = app(PurchaseHistoryPriceService::class);
        $empty = [
            'average_change_pct' => null,
            'total_change_pct' => null,
            'weighted_average' => null,
            'best' => null,
            'highest' => null,
            'line_count' => 0,
            'po_count' => 0,
            'total_kg' => '0.0000',
            'excluded' => ['lines' => 0, 'kg' => '0.0000', 'currencies' => []],
            'is_multi' => count($selectedMaterials) > 1,
            'per_material' => [],
        ];

        if ($selectedMaterials === []) {
            return [null, collect(), null, $empty];
        }

        $periodGranularity = $periodView === 'yearly' ? 'year' : 'month';
        $allPeriodKeys = [];
        $materialResults = [];
        $hasAnyRecord = false;
        $totalLinesAcross = 0;
        $totalExcludedLinesAcross = 0;
        $totalExcludedKgAcross = '0';
        $excludedCurrenciesAcross = [];
        $totalKgAcross = '0';
        $totalPoCountAcross = 0;

        foreach ($selectedMaterials as $idx => $mat) {
            $singleFilter = array_merge($baseFilter, [
                'material_name' => $mat['name'],
                'material_master_id' => $mat['master_id'] ?? null,
                'shape' => $mat['shape'] ?? null,
                'shape_is_null' => ($mat['shape'] ?? null) === null,
            ]);

            $history = $service->summarize($singleFilter, $currency, $context, $periodGranularity, true);
            $total = $history['total'];
            if ($total['line_count'] > 0 || $total['excluded']['lines'] > 0) {
                $hasAnyRecord = true;
            }

            $totalLinesAcross += $total['line_count'];
            $totalExcludedLinesAcross += $total['excluded']['lines'];
            $totalExcludedKgAcross = bcadd($totalExcludedKgAcross, (string) ($total['excluded']['kg'] ?? '0'), 4);
            $excludedCurrenciesAcross = array_values(array_unique([...$excludedCurrenciesAcross, ...($total['excluded']['currencies'] ?? [])]));
            $totalKgAcross = bcadd($totalKgAcross, (string) ($total['total_kg'] ?? '0'), 4);
            $totalPoCountAcross += $total['po_count'];

            $periodRows = $this->appendChangePercent(collect($history['periods'])->map(fn (array $period) => [
                'period' => $periodView === 'yearly'
                    ? $period['period']
                    : $this->regionalFormatter->monthYear(Carbon::createFromFormat('Y-m-d', $period['period'].'-01')),
                'period_sort' => $period['period'],
                'series_index' => $idx,
                'material_key' => $mat['key'] ?? self::encodeMaterialKey($mat['name'], $mat['shape'] ?? null),
                'material_name' => $mat['name'],
                'material_label' => $mat['label'] ?? $mat['name'],
                'shape' => $mat['shape'] ?? null,
                'price_per_kg' => null,
                'currency' => $currency,
                'price_idr' => $period['weighted_average'] === null ? null : (float) $period['weighted_average'],
                'min_idr' => $period['best'] === null ? null : (float) $period['best'],
                'max_idr' => $period['highest'] === null ? null : (float) $period['highest'],
                'line_count' => $period['line_count'],
                'po_count' => $period['po_count'],
                'total_kg' => $period['total_kg'],
                'excluded_lines' => $period['excluded']['lines'] ?? 0,
                'submitted_at' => null,
            ]));

            foreach ($periodRows as $row) {
                $allPeriodKeys[$row['period_sort']] = $row['period'];
            }

            $changeStats = $this->buildHistoricalSummary($periodRows);
            $materialResults[] = [
                'index' => $idx,
                'material' => $mat,
                'periodRows' => $periodRows,
                'periodMap' => $periodRows->keyBy('period_sort'),
                'summary' => [
                    'index' => $idx,
                    'key' => $mat['key'] ?? self::encodeMaterialKey($mat['name'], $mat['shape'] ?? null),
                    'label' => $mat['label'] ?? $mat['name'],
                    'material_name' => $mat['name'],
                    'shape' => $mat['shape'] ?? null,
                    'average_change_pct' => $changeStats['average_change_pct'],
                    'total_change_pct' => $changeStats['total_change_pct'],
                    'weighted_average' => $total['weighted_average'],
                    'best' => $total['best'],
                    'highest' => $total['highest'],
                    'line_count' => $total['line_count'],
                    'po_count' => $total['po_count'],
                    'total_kg' => $total['total_kg'],
                    'excluded' => $total['excluded'],
                ],
            ];
        }

        if (! $hasAnyRecord) {
            return [null, collect(), null, $empty];
        }

        ksort($allPeriodKeys);
        $sortedPeriodKeys = array_keys($allPeriodKeys);
        $labels = array_values($allPeriodKeys);
        $isMulti = count($selectedMaterials) > 1;

        $series = [];
        foreach ($materialResults as $entry) {
            $periodMap = $entry['periodMap'];
            $pricesIdr = [];
            $minIdr = [];
            $maxIdr = [];
            $poCounts = [];
            $totalKgs = [];

            foreach ($sortedPeriodKeys as $pKey) {
                $row = $periodMap->get($pKey);
                $pricesIdr[] = $row ? $row['price_idr'] : null;
                $minIdr[] = $row ? $row['min_idr'] : null;
                $maxIdr[] = $row ? $row['max_idr'] : null;
                $poCounts[] = $row ? (int) $row['po_count'] : 0;
                $totalKgs[] = $row ? (float) $row['total_kg'] : 0.0;
            }

            $series[] = [
                'index' => $entry['index'],
                'key' => $entry['summary']['key'],
                'label' => $entry['summary']['label'],
                'material_name' => $entry['summary']['material_name'],
                'shape' => $entry['summary']['shape'],
                'pricesIdr' => $pricesIdr,
                'minIdr' => $minIdr,
                'maxIdr' => $maxIdr,
                'poCounts' => $poCounts,
                'totalKgs' => $totalKgs,
            ];
        }

        $firstSeries = $series[0];
        $chartData = [
            'type' => $periodView,
            'currency' => $currency,
            'labels' => $labels,
            'periodKeys' => $sortedPeriodKeys,
            'periodCount' => count($labels),
            'isMultiMaterial' => $isMulti,
            'series' => $series,
            'prices' => [],
            'pricesIdr' => $firstSeries['pricesIdr'],
            'minIdr' => $firstSeries['minIdr'],
            'maxIdr' => $firstSeries['maxIdr'],
        ];

        $perMaterialSummaries = array_column($materialResults, 'summary');
        if (! $isMulti) {
            $primary = $perMaterialSummaries[0];
            $summary = [
                'average_change_pct' => $primary['average_change_pct'],
                'total_change_pct' => $primary['total_change_pct'],
                'weighted_average' => $primary['weighted_average'],
                'best' => $primary['best'],
                'highest' => $primary['highest'],
                'line_count' => $primary['line_count'],
                'po_count' => $primary['po_count'],
                'total_kg' => $primary['total_kg'],
                'excluded' => $primary['excluded'],
                'is_multi' => false,
                'per_material' => $perMaterialSummaries,
            ];
        } else {
            $summary = [
                'average_change_pct' => null,
                'total_change_pct' => null,
                'weighted_average' => null,
                'best' => null,
                'highest' => null,
                'line_count' => $totalLinesAcross,
                'po_count' => $totalPoCountAcross,
                'total_kg' => Money::normalize($totalKgAcross, 4),
                'excluded' => [
                    'lines' => $totalExcludedLinesAcross,
                    'kg' => Money::normalize($totalExcludedKgAcross, 4),
                    'currencies' => $excludedCurrenciesAcross,
                ],
                'is_multi' => true,
                'per_material' => $perMaterialSummaries,
            ];
        }

        $changeDirection = PurchaseHistoryPriceService::normalizeChangeDirection($baseFilter['change_direction'] ?? null);

        if ($periodView === 'yearly') {
            $yearlyRows = collect();
            foreach ($sortedPeriodKeys as $pKey) {
                foreach ($materialResults as $entry) {
                    $row = $entry['periodMap']->get($pKey);
                    if ($row !== null && PurchaseHistoryPriceService::matchesChangeDirection($row['change_pct'] ?? null, $changeDirection)) {
                        $yearlyRows->push($row);
                    }
                }
            }

            return [$chartData, $yearlyRows->values(), null, $summary];
        }

        $multiLinesFilter = array_merge($baseFilter, [
            'materials' => array_map(fn (array $m) => [
                'name' => $m['name'],
                'master_id' => $m['master_id'] ?? null,
                'shape' => $m['shape'] ?? null,
            ], $selectedMaterials),
            'sort_direction' => self::normalizeSortDirection($baseFilter['sort_direction'] ?? null),
        ]);

        $page = max(1, $page);
        $lines = $service->lines($multiLinesFilter, $currency, $context, $page, self::HISTORICAL_TABLE_PER_PAGE, true);
        $totalRows = (int) $lines['total'];
        $lastPage = max(1, (int) ceil($totalRows / self::HISTORICAL_TABLE_PER_PAGE));
        if ($totalRows > 0 && $page > $lastPage) {
            $page = $lastPage;
            $lines = $service->lines($multiLinesFilter, $currency, $context, $page, self::HISTORICAL_TABLE_PER_PAGE, true);
        }

        $seriesIndexByKey = [];
        foreach ($materialResults as $entry) {
            $seriesIndexByKey[$entry['summary']['key']] = $entry['index'];
        }

        $tableData = collect($lines['rows'])->map(function (array $line) use ($currency, $seriesIndexByKey) {
            $purchaseAt = $line['po_created_at'] ? Carbon::parse($line['po_created_at'], 'UTC') : null;
            $matKey = self::encodeMaterialKey((string) ($line['material_name'] ?? ''), $line['shape'] ?? null);

            return [
                'period' => $purchaseAt ? $this->regionalFormatter->monthYear($purchaseAt) : __('purchasing.copy.unknown_period'),
                'period_sort' => $purchaseAt?->format('Y-m-d H:i:s'),
                'series_index' => $seriesIndexByKey[$matKey] ?? 0,
                'purchase_order_id' => $line['po_id'],
                'purchase_order_number' => $line['po_number'],
                'purchase_order_at' => $purchaseAt?->toIso8601String(),
                'purchase_order_at_display' => $purchaseAt ? $this->regionalFormatter->timestamp($purchaseAt, 'date') : '-',
                'pr_id' => $line['pr_id'],
                'pr_number' => $line['pr_number'] ?? '-',
                'pr_url' => $line['pr_id'] ? route('purchasing.requisitions.show', HashidCodec::encode($line['pr_id'])) : null,
                'material_name' => $line['material_name'] ?? '-',
                'shape' => $line['shape'] ?? null,
                'dimension_display' => $line['dimension_display'] ?? '-',
                'range_classification' => $line['range_classification'] ?? '-',
                'supplier' => $line['supplier'] ?? '-',
                'price_per_kg' => (float) $line['price_per_kg'],
                'currency' => $line['currency'],
                'kg' => (float) $line['kg'],
                'price_idr' => $line['converted_price_per_kg'] === null ? null : (float) $line['converted_price_per_kg'],
                'annual_price_display' => ($line['converted_price_per_kg'] === null ? __('purchasing.comparison_analytics.rate_unavailable') : $currency.' '.$this->formatPrice($line['converted_price_per_kg'], 4).'/kg').' · '.__('annual_rates.year').' '.$line['rate_year'],
                'total_idr' => $line['converted_total'] === null ? null : (float) $line['converted_total'],
                'min_idr' => null,
                'max_idr' => null,
                'submitted_at' => $purchaseAt?->toIso8601String(),
                'submitted_at_display' => $purchaseAt ? $this->regionalFormatter->timestamp($purchaseAt, 'date') : '-',
                'change_pct' => $line['change_pct'],
            ];
        });
        $firstRow = $totalRows > 0 ? (($page - 1) * self::HISTORICAL_TABLE_PER_PAGE) + 1 : 0;

        return [$chartData, $tableData, [
            'current_page' => $totalRows > 0 ? $page : 1,
            'last_page' => $lastPage,
            'per_page' => self::HISTORICAL_TABLE_PER_PAGE,
            'total' => $totalRows,
            'from' => $firstRow,
            'to' => $totalRows > 0 ? min($totalRows, $firstRow + self::HISTORICAL_TABLE_PER_PAGE - 1) : 0,
        ], $summary];
    }

    public static function normalizeSortDirection(mixed $value): string
    {
        return is_string($value) && strtolower(trim($value)) === 'asc' ? 'asc' : 'desc';
    }

    public function historicalPeriodLabel($name, $month, $year): string
    {
        if ($year === null) {
            return __('purchasing.copy.unknown_period');
        }

        return $month === null
            ? (string) $year
            : $name.' ('.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'/'.$year.')';
    }

    public function appendChangePercent($rows)
    {
        $previous = null;

        return collect($rows)->map(function ($row) use (&$previous) {
            $current = $row['price_idr'] ?? null;
            $row['change_pct'] = null;

            if ($previous !== null && $previous > 0 && $current !== null) {
                $row['change_pct'] = round((($current - $previous) / $previous) * 100, 2);
            }

            if ($current !== null) {
                $previous = $current;
            }

            return $row;
        })->values();
    }

    public function buildHistoricalSummary($rows): array
    {
        $rows = collect($rows);
        $changes = $rows->pluck('change_pct')->filter(fn ($change) => $change !== null);
        $prices = $rows->pluck('price_idr')
            ->filter(fn ($price) => $price !== null && $price > 0)
            ->values();

        return [
            'average_change_pct' => $changes->count() > 0 ? round($changes->avg(), 2) : null,
            'total_change_pct' => $prices->count() >= 2
                ? round((($prices->last() - $prices->first()) / $prices->first()) * 100, 2)
                : null,
        ];
    }

    public function historicalRangeOptions(string $periodView): array
    {
        if ($periodView === 'yearly') {
            return [
                '1y' => '1 Year',
                '2y' => '2 Years',
                '5y' => '5 Years',
                'all' => __('purchasing.copy.all_years'),
            ];
        }

        return [
            '3m' => '3 Months',
            '6m' => '6 Months',
            '12m' => '12 Months',
            '24m' => '24 Months',
            'all' => __('purchasing.copy.all_months'),
        ];
    }

    public function normalizeHistoricalRange(string $periodView, ?string $range): string
    {
        $range = $range ?: 'all';

        if ($periodView === 'monthly') {
            $range = match ($range) {
                '1y' => '12m',
                '2y' => '24m',
                default => $range,
            };
        } else {
            $range = match ($range) {
                '3m', '6m', '12m' => '1y',
                '24m' => '2y',
                default => $range,
            };
        }

        return array_key_exists($range, $this->historicalRangeOptions($periodView))
            ? $range
            : 'all';
    }

    public function dateFromRange(string $range)
    {
        return match ($range) {
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '12m', '1y' => now()->subYear(),
            '24m', '2y' => now()->subYears(2),
            '5y' => now()->subYears(5),
            default => null,
        };
    }
}
