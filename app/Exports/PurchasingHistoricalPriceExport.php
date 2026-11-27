<?php

namespace App\Exports;

use App\Contracts\AcceptsExportOptions;
use App\Contracts\TracksExportProgress;
use App\Exports\Advanced\Concerns\UsesColumnCatalog;
use App\Exports\Concerns\InteractsWithExportProgress;
use App\Exports\Concerns\StylesOperationalWorkbook;
use App\Models\ReferencePriceRule;
use App\Services\Pricing\HistoricalPriceQueryService;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Support\BusinessTime;
use App\Support\Export\ExportDefinitions;
use App\Support\Export\ExportOptions;
use App\Support\Pricing\EvaluationContext;
use Carbon\Carbon;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;

class PurchasingHistoricalPriceExport implements AcceptsExportOptions, FromCollection, HasLocalePreference, TracksExportProgress, WithColumnWidths, WithCustomCsvSettings, WithHeadings, WithStyles, WithTitle
{
    use InteractsWithExportProgress;
    use StylesOperationalWorkbook;
    use UsesColumnCatalog;

    private ?Collection $cachedRows = null;

    public function __construct(
        private readonly array $filters = [],
    ) {}

    public function collection(): Collection
    {
        return $this->cachedRows ??= $this->buildRows()->map(fn (array $row) => $this->mapRow($row));
    }

    public function progressTotalRows(): int
    {
        $periodView = $this->periodView();
        if ($periodView === 'yearly') {
            return $this->buildYearlyRows()->count();
        }

        $queryFilter = $this->buildQueryFilter();
        $hasPostFilter = isset($queryFilter['price_min'])
            || isset($queryFilter['price_max'])
            || isset($queryFilter['change_direction']);

        if ($hasPostFilter) {
            return (int) app(PurchaseHistoryPriceService::class)
                ->lines($queryFilter, $this->currency(), EvaluationContext::current(), 1, 1, true)['total'];
        }

        return app(PurchaseHistoryPriceService::class)
            ->eligibleLines($queryFilter)
            ->count();
    }

    public function headings(): array
    {
        $this->ensureDefaultOptions();

        return $this->catalogHeadings();
    }

    public function columnWidths(): array
    {
        $this->ensureDefaultOptions();

        return $this->catalogWidths();
    }

    public function title(): string
    {
        return __('exports.history.purchasing_sheet_title');
    }

    /**
     * Keep the in-process row cache out of serialized queue-chain jobs.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return array_values(array_diff(array_keys(get_object_vars($this)), ['cachedRows']));
    }

    protected function workbookPresentationEnabled(): bool
    {
        return $this->catalogStylesEnabled();
    }

    protected function workbookWrappedColumns(): array
    {
        $this->ensureDefaultOptions();

        return $this->catalogWrappedColumns();
    }

    protected function workbookNumberFormats(): array
    {
        $this->ensureDefaultOptions();

        return $this->catalogNumberFormats();
    }

    private function ensureDefaultOptions(): void
    {
        if (! $this->hasExportOptions()) {
            $definition = ExportDefinitions::get('purchasing.price-history');
            $this->applyOptions(new ExportOptions(ExportDefinitions::defaultKeys($definition), 'xlsx', 'purchasing'));
        }
    }

    private function mapRow(array $row): array
    {
        $this->ensureDefaultOptions();

        return $this->catalogMap($row);
    }

    private function periodView(): string
    {
        return ($this->filters['period_view'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
    }

    private function currency(): string
    {
        return strtoupper((string) ($this->filters['currency'] ?? 'IDR'));
    }

    private function buildQueryFilter(): array
    {
        $queryService = app(HistoricalPriceQueryService::class);
        $periodView = $this->periodView();
        $filter = [];

        if (! empty($this->filters['supplier_id'])) {
            $filter['supplier_id'] = (int) $this->filters['supplier_id'];
        }

        if (! empty($this->filters['materials']) && is_array($this->filters['materials'])) {
            $specs = [];
            foreach ($this->filters['materials'] as $rawKey) {
                $parsed = HistoricalPriceQueryService::parseMaterialKey((string) $rawKey);
                if ($parsed['name'] !== '') {
                    $specs[] = [
                        'name' => $parsed['name'],
                        'shape' => $parsed['shape'],
                    ];
                }
            }
            if ($specs !== []) {
                $filter['materials'] = $specs;
            }
        }

        if (isset($this->filters['key_min']) && $this->filters['key_min'] !== null && $this->filters['key_min'] !== '') {
            $filter['key_min'] = (string) $this->filters['key_min'];
        }
        if (isset($this->filters['key_max']) && $this->filters['key_max'] !== null && $this->filters['key_max'] !== '') {
            $filter['key_max'] = (string) $this->filters['key_max'];
        }

        if (! empty($this->filters['categories']) && is_array($this->filters['categories'])) {
            $ranges = ReferencePriceRule::query()
                ->active()
                ->whereIn('category_key', $this->filters['categories'])
                ->get(['dimension_min', 'dimension_max'])
                ->map(static fn (ReferencePriceRule $rule): array => [
                    'min' => (int) $rule->dimension_min,
                    'max' => (int) $rule->dimension_max,
                ])
                ->all();
            if ($ranges !== []) {
                $filter['category_ranges'] = $ranges;
            }
        }

        if (! empty($this->filters['source_currencies']) && is_array($this->filters['source_currencies'])) {
            $filter['source_currencies'] = array_values($this->filters['source_currencies']);
        }

        if (! empty($this->filters['doc_search'])) {
            $filter['document_search'] = trim((string) $this->filters['doc_search']);
        }

        if (isset($this->filters['price_min']) && $this->filters['price_min'] !== null && $this->filters['price_min'] !== '') {
            $filter['price_min'] = (string) $this->filters['price_min'];
        }
        if (isset($this->filters['price_max']) && $this->filters['price_max'] !== null && $this->filters['price_max'] !== '') {
            $filter['price_max'] = (string) $this->filters['price_max'];
        }
        if (! empty($this->filters['change_direction'])) {
            $filter['change_direction'] = (string) $this->filters['change_direction'];
        }

        if (! empty($this->filters['start_date']) || ! empty($this->filters['end_date'])) {
            if (! empty($this->filters['start_date'])) {
                $filter['date_from'] = BusinessTime::parseDate((string) $this->filters['start_date'])->startOfDay();
            }
            if (! empty($this->filters['end_date'])) {
                $filter['date_to'] = BusinessTime::parseDate((string) $this->filters['end_date'])->endOfDay();
            }
        } else {
            $range = $queryService->normalizeHistoricalRange($periodView, $this->filters['range'] ?? 'all');
            $dateFrom = $queryService->dateFromRange($range);
            if ($dateFrom !== null) {
                $filter['date_from'] = $dateFrom;
            }
        }

        return $filter;
    }

    private function buildRows(): Collection
    {
        if ($this->periodView() === 'yearly') {
            return $this->buildYearlyRows();
        }

        return $this->buildMonthlyRows();
    }

    private function buildMonthlyRows(): Collection
    {
        $service = app(PurchaseHistoryPriceService::class);
        $currency = $this->currency();
        $context = EvaluationContext::current();
        $maxRows = max(1, (int) config('exports.max_rows', 50000));
        $result = $service->lines(
            array_merge($this->buildQueryFilter(), [
                'sort_direction' => HistoricalPriceQueryService::normalizeSortDirection($this->filters['sort_dir'] ?? null),
            ]),
            $currency,
            $context,
            1,
            $maxRows,
            true,
        );

        return collect($result['rows'])->map(function (array $line) use ($currency): array {
            $purchaseAt = ! empty($line['po_created_at']) ? Carbon::parse($line['po_created_at'], 'UTC') : null;
            $localDate = $purchaseAt ? BusinessTime::toBusiness($purchaseAt) : null;

            return [
                'material_name' => $line['material_name'] ?? '-',
                'shape' => $line['shape'] ?? '-',
                'period' => $localDate ? $localDate->format('Y-m') : '-',
                'po_date' => $localDate ? $localDate->format('Y-m-d') : '-',
                'pr_number' => $line['pr_number'] ?? '-',
                'po_number' => $line['po_number'] ?? '-',
                'dimensions' => $line['dimension_display'] ?? '-',
                'category_name' => $line['range_classification'] ?? '-',
                'supplier' => $line['supplier'] ?? '-',
                'po_count' => 1,
                'ordered_weight' => isset($line['kg']) ? (float) $line['kg'] : null,
                'source_currency' => $line['currency'] ?? '-',
                'source_price_per_kg' => isset($line['price_per_kg']) ? (float) $line['price_per_kg'] : null,
                'annual_rate_year' => isset($line['rate_year']) ? (int) $line['rate_year'] : null,
                'target_currency' => $currency,
                'converted_price_per_kg' => $line['converted_price_per_kg'] !== null ? (float) $line['converted_price_per_kg'] : null,
                'lowest_price_kg' => null,
                'highest_price_kg' => null,
                'converted_total' => $line['converted_total'] !== null ? (float) $line['converted_total'] : null,
                'period_change_pct' => $line['change_pct'] !== null ? (float) $line['change_pct'] : null,
            ];
        })->values();
    }

    private function buildYearlyRows(): Collection
    {
        $service = app(PurchaseHistoryPriceService::class);
        $queryService = app(HistoricalPriceQueryService::class);
        $currency = $this->currency();
        $context = EvaluationContext::current();
        $baseFilter = $this->buildQueryFilter();

        $materialSpecs = $baseFilter['materials'] ?? [];
        unset($baseFilter['materials']);

        if ($materialSpecs === []) {
            $materialSpecs = $service->eligibleLines($baseFilter)
                ->whereNotNull('pi.material_name')
                ->where('pi.material_name', '<>', '')
                ->select('pi.material_name', 'pi.shape')
                ->distinct()
                ->orderBy('pi.material_name')
                ->orderBy('pi.shape')
                ->get()
                ->map(fn ($row) => [
                    'name' => (string) $row->material_name,
                    'shape' => $row->shape !== null ? (string) $row->shape : null,
                ])
                ->all();
        }

        if ($materialSpecs === []) {
            return collect();
        }

        $selectedMaterials = array_map(fn (array $spec) => [
            'key' => HistoricalPriceQueryService::encodeMaterialKey($spec['name'], $spec['shape']),
            'name' => $spec['name'],
            'shape' => $spec['shape'],
            'master_id' => null,
            'label' => $spec['shape'] ? $spec['name'].' ('.$spec['shape'].')' : $spec['name'],
        ], $materialSpecs);

        [, $yearlyTable] = $queryService->buildMultiHistoricalData(
            $selectedMaterials,
            $baseFilter,
            'yearly',
            $currency,
            $context,
            1
        );

        return $yearlyTable->map(fn (array $row): array => [
            'material_name' => $row['material_name'] ?? '-',
            'shape' => $row['shape'] ?? '-',
            'period' => (string) ($row['period'] ?? '-'),
            'po_date' => '-',
            'pr_number' => '-',
            'po_number' => '-',
            'dimensions' => '-',
            'category_name' => '-',
            'supplier' => '-',
            'po_count' => isset($row['po_count']) ? (int) $row['po_count'] : 0,
            'ordered_weight' => isset($row['total_kg']) ? (float) $row['total_kg'] : null,
            'source_currency' => '-',
            'source_price_per_kg' => null,
            'annual_rate_year' => is_numeric($row['period'] ?? null) ? (int) $row['period'] : null,
            'target_currency' => $currency,
            'converted_price_per_kg' => $row['price_idr'] !== null ? (float) $row['price_idr'] : null,
            'lowest_price_kg' => $row['min_idr'] !== null ? (float) $row['min_idr'] : null,
            'highest_price_kg' => $row['max_idr'] !== null ? (float) $row['max_idr'] : null,
            'converted_total' => null,
            'period_change_pct' => $row['change_pct'] !== null ? (float) $row['change_pct'] : null,
        ])->values();
    }
}
