<?php

namespace App\Support;

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
    ): array {
        $dimensionFilters = $this->validDimensionFilters($dimensionFilters);

        return $periodView === 'yearly'
            ? $this->buildYearlyData($supplierId, $materialName, $dateFrom, $dimensionFilters)
            : $this->buildMonthlyData($supplierId, $materialName, $dateFrom, $dimensionFilters);
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
        $class = match ($status) {
            'submitted' => 'bg-primary',
            'accepted' => 'bg-success',
            'rejected' => 'bg-danger',
            default => 'bg-secondary',
        };

        return '<span class="badge '.$class.'">'.$label.'</span>';
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, array<string, mixed>>} */
    private function buildMonthlyData(
        int $supplierId,
        string $materialName,
        ?Carbon $dateFrom,
        array $dimensionFilters,
    ): array {
        $items = $this->historicalItemsQuery($supplierId, $materialName, $dateFrom, $dimensionFilters)
            ->orderBy('purchase_orders.created_at')
            ->orderBy('quotation_items.id')
            ->get();

        $tableData = $items->map(function (QuotationItem $item): array {
            $period = optional(optional($item->quotation->purchaseRequisition)->period);
            $rate = $item->history_rate_to_idr;
            $priceIdr = $rate !== null ? round((float) $item->price_per_kg * (float) $rate, 0) : null;
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
                'price_per_kg' => (float) $item->price_per_kg,
                'currency' => $item->quotation->currency,
                'price_idr' => $priceIdr,
                'min_idr' => null,
                'max_idr' => null,
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

        $tableData = $this->appendChangePercent($tableData);

        return [[
            'type' => 'monthly',
            'labels' => $tableData->pluck('period')->values(),
            'prices' => $tableData->pluck('price_per_kg')->values(),
            'pricesIdr' => $tableData->pluck('price_idr')->map(fn ($price) => $price ?? 0)->values(),
        ], $tableData];
    }

    /** @return array{0: array<string, mixed>, 1: Collection<int, array<string, mixed>>} */
    private function buildYearlyData(
        int $supplierId,
        string $materialName,
        ?Carbon $dateFrom,
        array $dimensionFilters,
    ): array {
        $query = $this->historicalItemsQuery($supplierId, $materialName, $dateFrom, $dimensionFilters);
        $priceIdr = '(quotation_items.price_per_kg * COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr, 1))';

        $yearlyData = $query
            ->select([])
            ->selectRaw('YEAR(purchase_orders.created_at) as year')
            ->selectRaw("AVG({$priceIdr}) as avg_price_idr")
            ->selectRaw("MIN({$priceIdr}) as min_price_idr")
            ->selectRaw("MAX({$priceIdr}) as max_price_idr")
            ->groupByRaw('YEAR(purchase_orders.created_at)')
            ->orderBy('year', 'asc')
            ->get();

        $tableData = $yearlyData->map(function ($row): array {
            return [
                'period' => (string) $row->year,
                'price_idr' => round((float) $row->avg_price_idr, 0),
                'min_idr' => round((float) $row->min_price_idr, 0),
                'max_idr' => round((float) $row->max_price_idr, 0),
            ];
        })->values();

        $tableData = $this->appendChangePercent($tableData, 'price_idr');

        return [[
            'type' => 'yearly',
            'labels' => $tableData->pluck('period')->values(),
            'pricesIdr' => $tableData->pluck('price_idr')->values(),
        ], $tableData];
    }

    /**
     * Purchase history is established only by a live PO link. The selected
     * exchange-rate snapshot belongs to the PO; quotation rate is a legacy
     * fallback for older POs that did not store one.
     */
    private function historicalItemsQuery(
        int $supplierId,
        string $materialName,
        ?Carbon $dateFrom,
        array $dimensionFilters,
    ): Builder {
        $query = QuotationItem::query()
            ->select([
                'quotation_items.*',
                'purchase_orders.id as history_po_id',
                'purchase_orders.po_number as history_po_number',
                'purchase_orders.created_at as history_po_created_at',
            ])
            ->selectRaw('COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr) as history_rate_to_idr')
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->leftJoin('exchange_rates as history_po_rates', 'purchase_orders.exchange_rate_id', '=', 'history_po_rates.id')
            ->leftJoin('exchange_rates as history_quote_rates', 'quotations.exchange_rate_id', '=', 'history_quote_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at')
            ->where('pr_items.material_name', $materialName)
            ->with([
                'quotation.purchaseRequisition.period',
                'quotation.exchange_rate',
                'prItem.purchaseRequisition' => function ($query) {
                    $query->select('id', 'pr_number');
                },
            ]);

        foreach ($dimensionFilters as $field => $value) {
            $query->where("pr_items.{$field}", $value);
        }

        if ($dateFrom !== null) {
            $query->where('purchase_orders.created_at', '>=', $dateFrom);
        }

        return $query;
    }

    private function appendChangePercent($tableData, string $priceKey = 'price_idr')
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
