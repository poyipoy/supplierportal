<?php

namespace App\Http\Controllers\Supplier;

use App\Http\Controllers\Controller;
use App\Models\PrItem;
use App\Models\QuotationItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Yajra\DataTables\Facades\DataTables;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\SupplierPriceHistoryExport;

class SupplierPriceHistoryController extends Controller
{
    /**
     * Sub-View 1: Overview - Menampilkan ringkasan all material
     */
    public function index(Request $request)
    {
        $supplierId = auth()->id();

        // Get Summary Stats
        $stats = $this->getOverviewStats($supplierId);

        if ($request->ajax()) {
            return $this->getOverviewData($request, $supplierId);
        }

        return view('supplier.price-history.index', compact('stats'));
    }

    /**
     * Sub-View 2: Historical - Grafik garis tren price per material
     */
    public function historical(Request $request)
    {
        $supplierId = auth()->id();
        $selectedMaterialName = $request->input('material_name');
        $periodView = $request->input('period_view', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        
        $range = $this->normalizeHistorycalRange($periodView, $request->input('range'));
        $monthlyRangeOptions = $this->historicalRangeOptions('monthly');
        $yearlyRangeOptions = $this->historicalRangeOptions('yearly');
        $rangeOptions = $this->historicalRangeOptions($periodView);
        $dateFrom = $this->dateFromRange($range);

        $materials = $this->getSupplierMaterials($supplierId);

        if ($selectedMaterialName && !$materials->pluck('name')->contains($selectedMaterialName)) {
            $selectedMaterialName = null;
        }

        $chartData = null;
        $tableData = collect();
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];
        
        // Filter Dimensi
        $dimensionFilters = [];
        foreach (['thickness', 'd_inner', 'd_outer', 'width', 'length'] as $field) {
            $val = $request->input($field);
            if ($val !== null && trim((string)$val) !== '') {
                $dimensionFilters[$field] = trim((string)$val);
            }
        }

        if ($selectedMaterialName) {
            [$chartData, $tableData] = $periodView === 'yearly'
                ? $this->buildYearlyHistorycalData($supplierId, $selectedMaterialName, $dateFrom, $dimensionFilters)
                : $this->buildMonthlyHistorycalData($supplierId, $selectedMaterialName, $dateFrom, $dimensionFilters);

            if ($tableData->isEmpty()) {
                $chartData = null;
            } else {
                $summary = $this->buildHistorycalSummary($tableData);
            }
        }

        $payload = [
            'chartData' => $chartData,
            'tableData' => $tableData->values(),
            'summary' => $summary,
            'periodView' => $periodView,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'materialName' => $selectedMaterialName,
        ];

        if ($request->ajax() && ($request->wantsJson() || $request->input('view') === 'json')) {
            return response()->json($payload);
        }

        return view('supplier.price-history.historical', compact(
            'materials',
            'chartData',
            'tableData',
            'summary',
            'selectedMaterialName',
            'periodView',
            'range',
            'rangeOptions',
            'monthlyRangeOptions',
            'yearlyRangeOptions',
            'payload'
        ));
    }

    /**
     * API Endpoint: Get supplier materials for dropdown
     */
    public function materials()
    {
        return response()->json([
            'materials' => $this->getSupplierMaterials(auth()->id())->values(),
        ]);
    }

    /**
     * Export Data Historical per Material
     */
    public function export(Request $request)
    {
        $supplierId = auth()->id();
        $materialName = $request->input('material_name');
        
        if (!$materialName) {
            return redirect()->back()->with('error', 'Please select a material before exporting.');
        }

        $periodView = $request->input('period_view', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $range = $this->normalizeHistorycalRange($periodView, $request->input('range'));
        $dateFrom = $this->dateFromRange($range);

        $dimensionFilters = [];
        foreach (['thickness', 'd_inner', 'd_outer', 'width', 'length'] as $field) {
            $val = $request->input($field);
            if ($val !== null && trim((string)$val) !== '') {
                $dimensionFilters[$field] = trim((string)$val);
            }
        }

        [$chartData, $tableData] = $periodView === 'yearly'
            ? $this->buildYearlyHistorycalData($supplierId, $materialName, $dateFrom, $dimensionFilters)
            : $this->buildMonthlyHistorycalData($supplierId, $materialName, $dateFrom, $dimensionFilters);

        $fileName = 'Price_History_' . str_replace([' ', '/'], '_', $materialName) . '_' . date('YmdHis') . '.xlsx';
        
        // Return Excel file
        return Excel::download(new SupplierPriceHistoryExport($tableData, $periodView, $materialName), $fileName);
    }

    // ─── Private Helper Methods ───

    private function getOverviewStats($supplierId)
    {
        $baseQuery = DB::table('quotation_items')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->whereNull('quotations.deleted_at');

        $totalMaterials = (clone $baseQuery)->distinct('pr_items.material_name')->count('pr_items.material_name');
        $totalQuotations = (clone $baseQuery)->count();
        
        return [
            'total_materials' => $totalMaterials,
            'total_quotations' => $totalQuotations,
            // Additional stats could be calculated here
        ];
    }

    private function getOverviewData(Request $request, $supplierId)
    {
        $priceIdr = '(quotation_items.price_per_kg * COALESCE(exchange_rates.rate_to_idr, 1))';

        $rows = DB::table('quotation_items')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->leftJoin('exchange_rates', 'quotations.exchange_rate_id', '=', 'exchange_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->whereNull('quotations.deleted_at')
            ->select([
                'pr_items.material_name',
                DB::raw('COUNT(*) as total_quotations'),
                DB::raw("MIN($priceIdr) as min_price_idr"),
                DB::raw("MAX($priceIdr) as max_price_idr"),
                DB::raw("CAST(SUBSTRING_INDEX(GROUP_CONCAT($priceIdr ORDER BY quotations.submitted_at DESC SEPARATOR '|'), '|', 1) AS DECIMAL(20,4)) as latest_price_idr"),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(quotations.status ORDER BY quotations.submitted_at DESC SEPARATOR '|'), '|', 1) as latest_status"),
                DB::raw('MAX(quotations.submitted_at) as last_submitted_at'),
            ])
            ->groupBy('pr_items.material_name')
            ->get();

        // Apply search filter manually
        $search = $request->input('search.value');
        if (!empty($search)) {
            $rows = $rows->filter(function ($row) use ($search) {
                return stripos($row->material_name, $search) !== false;
            })->values();
        }

        return DataTables::collection($rows)
            ->addColumn('action', function ($row) {
                $url = route('supplier.price-history.historical', ['material_name' => $row->material_name]);
                return '<a href="' . $url . '" class="btn btn-sm btn-outline-primary"><i class="bi bi-graph-up me-1"></i>View History</a>';
            })
            ->addColumn('price_info', function ($row) {
                $latest = $row->latest_price_idr ?? 0;
                $min    = $row->min_price_idr ?? 0;
                $max    = $row->max_price_idr ?? 0;

                return '<div class="fw-bold text-primary">Rp ' . number_format($latest, 0, ',', '.') . '</div>'
                    . '<div class="small text-muted">'
                    . 'Min: Rp ' . number_format($min, 0, ',', '.') . ' | '
                    . 'Max: Rp ' . number_format($max, 0, ',', '.')
                    . '</div>';
            })
            ->addColumn('latest_status_badge', function ($row) {
                return $this->getStatusBadge($row->latest_status ?? '');
            })
            ->rawColumns(['action', 'price_info', 'latest_status_badge'])
            ->make(true);
    }

    private function getSupplierMaterials($supplierId)
    {
        return PrItem::query()
            ->select('material_name', 'shape')
            ->distinct()
            ->whereNotNull('material_name')
            ->where('material_name', '<>', '')
            ->whereHas('quotationItems.quotation', function ($q) use ($supplierId) {
                $q->where('supplier_id', $supplierId)
                  ->whereIn('status', ['submitted', 'accepted', 'rejected']);
            })
            ->orderBy('material_name')
            ->get()
            ->map(function ($item) {
                return [
                    'name' => $item->material_name,
                    'shape' => $item->shape,
                ];
            });
    }

    private function buildMonthlyHistorycalData($supplierId, string $materialName, $dateFrom, array $dimensionFilters = []): array
    {
        $items = QuotationItem::query()
            ->select('quotation_items.*')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->whereHas('prItem', function ($q) use ($materialName, $dimensionFilters) {
                $q->where('material_name', $materialName);
                foreach ($dimensionFilters as $field => $val) {
                    $q->where($field, $val);
                }
            })
            ->with([
                'quotation.purchaseRequisition.period',
                'quotation.exchange_rate',
                'prItem.purchaseRequisition' => function ($q) {
                    $q->select('id', 'pr_number');
                },
            ]);

        if ($dateFrom) {
            $items->where('quotations.submitted_at', '>=', $dateFrom);
        }

        $items = $items
            ->orderByRaw('YEAR(quotations.submitted_at) ASC')
            ->orderByRaw('MONTH(quotations.submitted_at) ASC')
            ->orderBy('quotations.submitted_at', 'asc')
            ->orderBy('quotation_items.id', 'asc')
            ->get();

        $tableData = $items->map(function ($item) {
            $period = optional(optional($item->quotation->purchaseRequisition)->period);
            $rate = $item->quotation->exchange_rate;
            $priceIdr = $rate ? round((float) $item->price_per_kg * (float) $rate->rate_to_idr, 0) : null;
            $purchaseRequisition = $item->prItem?->purchaseRequisition;
            $submittedAt = $item->quotation->submitted_at;
            $periodYear = (int) ($period->year ?? 0);
            $periodMonth = (int) ($period->month ?? 0);
            $periodSort = $submittedAt
                ? $submittedAt->format('Y-m-d H:i:s') . '-' . str_pad((string) $item->id, 10, '0', STR_PAD_LEFT)
                : (
                    $periodYear > 0 && $periodMonth > 0
                        ? sprintf('%04d-%02d-00 00:00:00-%010d', $periodYear, $periodMonth, (int) $item->id)
                        : sprintf('9999-99-99 99:99:99-%010d', (int) $item->id)
                );
            $periodLabel = $submittedAt
                ? $submittedAt->format('M Y')
                : ($period->name ?? 'Unknown');

            return [
                'period' => $periodLabel,
                'period_sort' => $periodSort,
                'pr_id' => $purchaseRequisition?->id,
                'pr_number' => $purchaseRequisition?->pr_number ?? '-',
                'pr_url' => route('supplier.quotations.show', $item->quotation_id),
                'price_per_kg' => (float) $item->price_per_kg,
                'currency' => $item->quotation->currency,
                'price_idr' => $priceIdr,
                'min_idr' => null,
                'max_idr' => null,
                'submitted_at' => $submittedAt?->toIso8601String(),
                'submitted_at_display' => $submittedAt?->format('d M Y'),
                'status' => $item->quotation->status,
                'status_label' => $this->getStatusLabel($item->quotation->status),
                'status_badge' => $this->getStatusBadge($item->quotation->status),
            ];
        })->sortBy('period_sort', SORT_NATURAL)->values();

        $tableData = $this->appendChangePercent($tableData);

        return [[
            'type' => 'monthly',
            'labels' => $tableData->pluck('period')->values(),
            'prices' => $tableData->pluck('price_per_kg')->values(),
            'pricesIdr' => $tableData->pluck('price_idr')->map(fn($price) => $price ?? 0)->values(),
        ], $tableData];
    }

    private function buildYearlyHistorycalData($supplierId, string $materialName, $dateFrom, array $dimensionFilters = []): array
    {
        $query = QuotationItem::query()
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->leftJoin('exchange_rates', 'quotations.exchange_rate_id', '=', 'exchange_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('quotations.supplier_id', $supplierId)
            ->whereIn('quotations.status', ['submitted', 'accepted', 'rejected'])
            ->where('pr_items.material_name', $materialName);

        foreach ($dimensionFilters as $field => $val) {
            $query->where("pr_items.{$field}", $val);
        }

        if ($dateFrom) {
            $query->where('quotations.submitted_at', '>=', $dateFrom);
        }

        $priceIdr = '(quotation_items.price_per_kg * COALESCE(exchange_rates.rate_to_idr, 1))';

        $yearlyData = $query
            ->selectRaw('YEAR(quotations.submitted_at) as year')
            ->selectRaw("AVG({$priceIdr}) as avg_price_idr")
            ->selectRaw("MIN({$priceIdr}) as min_price_idr")
            ->selectRaw("MAX({$priceIdr}) as max_price_idr")
            ->groupByRaw('YEAR(quotations.submitted_at)')
            ->orderBy('year', 'asc')
            ->get();

        $tableData = $yearlyData->map(function ($row) {
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

    private function appendChangePercent($tableData, $priceKey = 'price_idr')
    {
        $tableData = $tableData->values();
        $previousPrice = null;

        return $tableData->map(function ($row) use (&$previousPrice, $priceKey) {
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

    private function buildHistorycalSummary($tableData): array
    {
        if ($tableData->isEmpty()) {
            return [
                'average_change_pct' => null,
                'total_change_pct' => null,
            ];
        }

        $changes = $tableData->pluck('change_pct')->filter(fn($val) => $val !== null)->values();
        $firstPriceIdr = $tableData->first()['price_idr'] ?? null;
        $lastPriceIdr = $tableData->last()['price_idr'] ?? null;

        $averageChangePct = $changes->isNotEmpty() ? $changes->average() : null;
        $totalChangePct = null;

        if ($firstPriceIdr > 0 && $lastPriceIdr !== null) {
            $totalChangePct = (($lastPriceIdr - $firstPriceIdr) / $firstPriceIdr) * 100;
        }

        return [
            'average_change_pct' => $averageChangePct,
            'total_change_pct' => $totalChangePct,
        ];
    }

    private function historicalRangeOptions(string $periodView): array
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

    private function normalizeHistorycalRange(string $periodView, ?string $range): string
    {
        if ($periodView === 'yearly') {
            if (in_array($range, ['3m', '6m', '12m', '1y'])) return '1y';
            if (in_array($range, ['24m', '2y'])) return '2y';
            if (in_array($range, ['3y'])) return '3y';
            if (in_array($range, ['5y'])) return '5y';
            return 'all';
        }

        $options = $this->historicalRangeOptions('monthly');
        if ($range && array_key_exists($range, $options)) {
            return $range;
        }

        return '6m';
    }

    private function dateFromRange(string $range): ?Carbon
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

    private function getStatusLabel($status)
    {
        return match ($status) {
            'submitted' => 'Submitted',
            'accepted' => 'Accepted',
            'rejected' => 'Rejected',
            default => ucfirst($status),
        };
    }

    private function getStatusBadge($status)
    {
        $label = $this->getStatusLabel($status);
        $class = match ($status) {
            'submitted' => 'bg-primary',
            'accepted' => 'bg-success',
            'rejected' => 'bg-danger',
            default => 'bg-secondary',
        };

        return '<span class="badge ' . $class . '">' . $label . '</span>';
    }
}
