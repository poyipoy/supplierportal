<?php

namespace App\Support;

use App\Models\ExchangeRate;
use App\Models\QuotationItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class SupplierPriceHistoryBuilder
{
    /** @var list<string> */
    private const DIMENSION_FIELDS = ['thickness', 'd_inner', 'd_outer', 'width', 'length'];

    public function rangeOptions(string $periodView): array
    {
        if ($periodView === 'yearly') {
            return [
                'all' => 'All Years',
                '1y' => 'Last 1 Year',
                '2y' => 'Last 2 Years',
                '3y' => 'Last 3 Years',
                '5y' => 'Last 5 Years',
            ];
        }

        return [
            '3m' => 'Last 3 Months',
            '6m' => 'Last 6 Months',
            '12m' => 'Last 12 Months',
            '24m' => 'Last 24 Months',
            'all' => 'All Months',
        ];
    }

    public function normalizeRange(string $periodView, ?string $range): string
    {
        if ($periodView === 'yearly') {
            if (in_array($range, ['3m', '6m', '12m', '1y'], true)) {
                return '1y';
            }

            if (in_array($range, ['24m', '2y'], true)) {
                return '2y';
            }

            if ($range === '3y') {
                return '3y';
            }

            if ($range === '5y') {
                return '5y';
            }

            return 'all';
        }

        return $range !== null && array_key_exists($range, $this->rangeOptions('monthly'))
            ? $range
            : '6m';
    }

    public function dateFromRange(string $range): ?Carbon
    {
        return match ($range) {
            '3m' => now()->subMonths(3)->startOfMonth(),
            '6m' => now()->subMonths(6)->startOfMonth(),
            '12m' => now()->subMonths(12)->startOfMonth(),
            '24m' => now()->subMonths(24)->startOfMonth(),
            '1y' => now()->subYears(1)->startOfYear(),
            '2y' => now()->subYears(2)->startOfYear(),
            '3y' => now()->subYears(3)->startOfYear(),
            '5y' => now()->subYears(5)->startOfYear(),
            default => null,
        };
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, array<string, mixed>>} */
    public function build(
        int $supplierId,
        string $materialName,
        string $periodView,
        ?Carbon $dateFrom,
        array $dimensionFilters = [],
        ?string $currency = null,
    ): array {
        $dimensionFilters = $this->validDimensionFilters($dimensionFilters);
        $currency = $this->resolveCurrency($supplierId, $materialName, $dimensionFilters, $currency);

        if ($currency === null) {
            return [[
                'type' => $periodView === 'yearly' ? 'yearly' : 'monthly',
                'currency' => null,
                'labels' => collect(),
                'prices' => collect(),
            ], collect()];
        }

        return $periodView === 'yearly'
            ? $this->buildYearlyData($supplierId, $materialName, $dateFrom, $dimensionFilters, $currency)
            : $this->buildMonthlyData($supplierId, $materialName, $dateFrom, $dimensionFilters, $currency);
    }

    /** @return Collection<int, string> */
    public function availableCurrencies(
        int $supplierId,
        string $materialName,
        array $dimensionFilters = [],
    ): Collection {
        return $this->historicalItemsQuery(
            $supplierId,
            $materialName,
            null,
            $this->validDimensionFilters($dimensionFilters),
            null,
        )
            ->select([])
            ->selectRaw('quotations.currency as currency')
            ->selectRaw('MAX(purchase_orders.created_at) as latest_purchase_at')
            ->groupBy('quotations.currency')
            ->orderByDesc('latest_purchase_at')
            ->pluck('currency')
            ->filter(fn ($currency) => in_array($currency, ExchangeRate::CURRENCIES, true))
            ->values();
    }

    public function resolveCurrency(
        int $supplierId,
        string $materialName,
        array $dimensionFilters = [],
        ?string $requestedCurrency = null,
    ): ?string {
        $currencies = $this->availableCurrencies($supplierId, $materialName, $dimensionFilters);
        $requestedCurrency = strtoupper(trim((string) $requestedCurrency));

        return $currencies->contains($requestedCurrency)
            ? $requestedCurrency
            : $currencies->first();
    }

    public function statusLabel(string $status): string
    {
        return match ($status) {
            'submitted' => 'Submitted',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            default => ucfirst($status),
        };
    }

    public function statusBadge(string $status): string
    {
        $label = $this->statusLabel($status);
        $tone = match ($status) {
            'submitted' => 'info',
            'accepted' => 'success',
            'rejected' => 'error',
            default => 'neutral',
        };

        return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'.e($label).'</span>';
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, array<string, mixed>>} */
    private function buildMonthlyData(
        int $supplierId,
        string $materialName,
        ?Carbon $dateFrom,
        array $dimensionFilters,
        string $currency,
    ): array {
        $items = $this->historicalItemsQuery($supplierId, $materialName, $dateFrom, $dimensionFilters, $currency)
            ->orderBy('purchase_orders.created_at')
            ->orderBy('quotation_items.id')
            ->get();

        $tableData = $items->map(function (QuotationItem $item): array {
            $period = optional(optional($item->quotation->purchaseRequisition)->period);
            $purchaseRequisition = $item->prItem?->purchaseRequisition;
            $purchaseAt = $item->history_po_created_at
                ? Carbon::parse($item->history_po_created_at)
                : null;
            $submittedAt = $item->quotation->submitted_at;
            $periodSort = $purchaseAt
                ? $purchaseAt->format('Y-m-d H:i:s').'-'.str_pad((string) $item->id, 10, '0', STR_PAD_LEFT)
                : sprintf('9999-99-99 99:99:99-%010d', (int) $item->id);
            $periodLabel = $purchaseAt?->format('M Y') ?? ($period->display_label ?? $period->name ?? 'Unknown');

            return [
                'period' => $periodLabel,
                'period_sort' => $periodSort,
                'purchase_order_id' => (int) $item->history_po_id,
                'purchase_order_number' => $item->history_po_number,
                'purchase_order_at' => $purchaseAt?->toIso8601String(),
                'purchase_order_at_display' => $purchaseAt?->format('d M Y'),
                'pr_id' => $purchaseRequisition?->id,
                'pr_number' => $purchaseRequisition?->pr_number ?? '-',
                'pr_url' => route('supplier.quotations.show', $item->quotation),
                'price_per_kg' => $item->price_per_kg === null ? null : (float) $item->price_per_kg,
                'currency' => $item->quotation->currency,
                // Keep the legacy keys for existing consumers, but make
                // their date explicitly represent the purchase event.
                'submitted_at' => $purchaseAt?->toIso8601String(),
                'submitted_at_display' => $purchaseAt?->format('d M Y'),
                'quotation_submitted_at' => $submittedAt?->toIso8601String(),
                'status' => $item->quotation->status,
                'status_label' => $this->statusLabel($item->quotation->status),
                'status_badge' => $this->statusBadge($item->quotation->status),
            ];
        })->sortBy('period_sort', SORT_NATURAL)->values();

        $tableData = $this->appendChangePercent($tableData, 'price_per_kg');

        return [[
            'type' => 'monthly',
            'currency' => $currency,
            'labels' => $tableData->pluck('period')->values(),
            'prices' => $tableData->pluck('price_per_kg')->values(),
        ], $tableData];
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, array<string, mixed>>} */
    private function buildYearlyData(
        int $supplierId,
        string $materialName,
        ?Carbon $dateFrom,
        array $dimensionFilters,
        string $currency,
    ): array {
        $query = $this->historicalItemsQuery($supplierId, $materialName, $dateFrom, $dimensionFilters, $currency);

        $yearlyData = $query
            ->select([])
            ->selectRaw('YEAR(purchase_orders.created_at) as year')
            ->selectRaw('AVG(quotation_items.price_per_kg) as avg_price')
            ->selectRaw('MIN(quotation_items.price_per_kg) as min_price')
            ->selectRaw('MAX(quotation_items.price_per_kg) as max_price')
            ->groupByRaw('YEAR(purchase_orders.created_at)')
            ->orderBy('year', 'asc')
            ->get();

        $tableData = $yearlyData->map(function ($row) use ($currency): array {
            return [
                'period' => (string) $row->year,
                'price_per_kg' => round((float) $row->avg_price, 4),
                'min_price' => round((float) $row->min_price, 4),
                'max_price' => round((float) $row->max_price, 4),
                'currency' => $currency,
            ];
        })->values();

        $tableData = $this->appendChangePercent($tableData, 'price_per_kg');

        return [[
            'type' => 'yearly',
            'currency' => $currency,
            'labels' => $tableData->pluck('period')->values(),
            'prices' => $tableData->pluck('price_per_kg')->values(),
            'minPrices' => $tableData->pluck('min_price')->values(),
            'maxPrices' => $tableData->pluck('max_price')->values(),
        ], $tableData];
    }

    /**
     * Purchase history is established only by a live PO link. Currency is
     * filtered per quotation so raw prices are never compared across units.
     */
    private function historicalItemsQuery(
        int $supplierId,
        string $materialName,
        ?Carbon $dateFrom,
        array $dimensionFilters,
        ?string $currency,
    ): Builder {
        $query = QuotationItem::query()
            ->select([
                'quotation_items.*',
                'purchase_orders.id as history_po_id',
                'purchase_orders.po_number as history_po_number',
                'purchase_orders.created_at as history_po_created_at',
            ])
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at')
            ->where('pr_items.material_name', $materialName)
            ->where(function ($query) {
                $query->whereNull('quotation_items.is_available')
                    ->orWhere('quotation_items.is_available', true);
            })
            ->with([
                'quotation.purchaseRequisition.period',
                'prItem.purchaseRequisition' => function ($query) {
                    $query->select('id', 'pr_number');
                },
            ]);

        foreach ($dimensionFilters as $field => $value) {
            $query->where("pr_items.{$field}", $value);
        }

        if ($currency !== null) {
            $query->where('quotations.currency', $currency);
        }

        if ($dateFrom !== null) {
            $query->where('purchase_orders.created_at', '>=', $dateFrom);
        }

        return $query;
    }

    private function appendChangePercent($tableData, string $priceKey = 'price_per_kg')
    {
        $tableData = $tableData->values();
        $previousPrice = null;

        return $tableData->map(function (array $row) use (&$previousPrice, $priceKey): array {
            $currentPrice = $row[$priceKey];
            $row['change_pct'] = null;

            if ($previousPrice !== null && $previousPrice > 0 && $currentPrice !== null) {
                $row['change_pct'] = (($currentPrice - $previousPrice) / $previousPrice) * 100;
            }

            if ($currentPrice !== null) {
                $previousPrice = $currentPrice;
            }

            return $row;
        });
    }

    private function validDimensionFilters(array $filters): array
    {
        return array_filter(
            $filters,
            fn (mixed $value, mixed $field): bool => is_string($field)
                && in_array($field, self::DIMENSION_FIELDS, true)
                && is_numeric($value),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
