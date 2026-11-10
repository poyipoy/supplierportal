<?php

namespace App\Services\Pricing;

use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\MaterialReferencePrice;
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

    public static function historicalMaterialOption(array $material): array
    {
        return [
            'name' => $material['name'],
            'shape' => $material['shape'],
            'label' => $material['label'],
            'key_options' => $material['key_options'],
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
        $lines = $service->lines($filter, $currency, $context, $page, self::HISTORICAL_TABLE_PER_PAGE, true);

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
