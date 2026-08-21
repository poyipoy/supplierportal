<?php

namespace App\Http\Controllers\Supplier;

use App\Exports\SupplierPriceHistoryExport;
use App\Http\Controllers\Controller;
use App\Models\ExportJob;
use App\Models\PrItem;
use App\Support\ExportDispatcher;
use App\Support\SupplierPriceHistoryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

class SupplierPriceHistoryController extends Controller
{
    public function __construct(private readonly SupplierPriceHistoryBuilder $historyBuilder) {}

    /**
     * Sub-view 1: display the supplier's material summary.
     */
    public function index(Request $request)
    {
        $supplierId = auth()->id();
        $stats = $this->getOverviewStats($supplierId);

        if ($request->ajax()) {
            return $this->getOverviewData($request, $supplierId);
        }

        return view('supplier.price-history.index', compact('stats'));
    }

    /**
     * Sub-view 2: price-history chart and table by material.
     */
    public function historical(Request $request)
    {
        $supplierId = auth()->id();
        $selectedMaterialName = $request->input('material_name');
        $periodView = $request->input('period_view', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $range = $this->historyBuilder->normalizeRange($periodView, $request->input('range'));
        $monthlyRangeOptions = $this->historyBuilder->rangeOptions('monthly');
        $yearlyRangeOptions = $this->historyBuilder->rangeOptions('yearly');
        $rangeOptions = $this->historyBuilder->rangeOptions($periodView);
        $dateFrom = $this->historyBuilder->dateFromRange($range);

        $materials = $this->getSupplierMaterials($supplierId);

        if ($selectedMaterialName && ! $materials->pluck('name')->contains($selectedMaterialName)) {
            $selectedMaterialName = null;
        }

        $chartData = null;
        $tableData = collect();
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];

        $dimensionFilters = $this->dimensionFilters($request);

        if ($selectedMaterialName) {
            [$chartData, $tableData] = $this->historyBuilder->build(
                (int) $supplierId,
                $selectedMaterialName,
                $periodView,
                $dateFrom,
                $dimensionFilters,
            );

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
            'payload',
        ));
    }

    public function materials()
    {
        return response()->json([
            'materials' => $this->getSupplierMaterials(auth()->id())->values(),
        ]);
    }

    public function export(Request $request)
    {
        $validated = $request->validate([
            'material_name' => ['required', 'string', 'max:255'],
            'period_view' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'range' => ['nullable', Rule::in(['3m', '6m', '12m', '24m', '1y', '2y', '3y', '5y', 'all'])],
            'thickness' => ['nullable', 'numeric'],
            'd_inner' => ['nullable', 'numeric'],
            'd_outer' => ['nullable', 'numeric'],
            'width' => ['nullable', 'numeric'],
            'length' => ['nullable', 'numeric'],
        ]);

        $supplierId = (int) $request->user()->getKey();
        $materialName = $validated['material_name'];
        $periodView = ($validated['period_view'] ?? 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $range = $this->historyBuilder->normalizeRange($periodView, $validated['range'] ?? null);
        $dateFrom = $this->historyBuilder->dateFromRange($range);
        $dimensionFilters = $this->dimensionFilters($request);
        $fileName = 'Price_History_'.str_replace([' ', '/'], '_', $materialName).'_'.now()->format('YmdHis').'.xlsx';

        $exportJob = ExportDispatcher::dispatch(
            'Supplier Price History',
            SupplierPriceHistoryExport::class,
            [
                $supplierId,
                $periodView,
                $materialName,
                $dateFrom?->toIso8601String(),
                $dimensionFilters,
            ],
            $fileName,
        );

        return $this->dispatchResponse($request, $exportJob);
    }

    private function getOverviewStats($supplierId): array
    {
        $baseQuery = DB::table('quotation_items')
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at');

        return [
            'total_materials' => (clone $baseQuery)->distinct('pr_items.material_name')->count('pr_items.material_name'),
            'total_quotations' => (clone $baseQuery)
                ->selectRaw('COUNT(DISTINCT CONCAT(purchase_orders.id, ":", quotation_items.id)) as aggregate')
                ->value('aggregate'),
        ];
    }

    private function getOverviewData(Request $request, $supplierId)
    {
        $priceIdr = '(quotation_items.price_per_kg * COALESCE(po_rates.rate_to_idr, quotation_rates.rate_to_idr, 1))';

        $rows = DB::table('quotation_items')
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->leftJoin('exchange_rates as po_rates', 'purchase_orders.exchange_rate_id', '=', 'po_rates.id')
            ->leftJoin('exchange_rates as quotation_rates', 'quotations.exchange_rate_id', '=', 'quotation_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at')
            ->select([
                'pr_items.material_name',
                DB::raw('COUNT(DISTINCT CONCAT(purchase_orders.id, ":", quotation_items.id)) as total_quotations'),
                DB::raw("MIN($priceIdr) as min_price_idr"),
                DB::raw("MAX($priceIdr) as max_price_idr"),
                DB::raw("CAST(SUBSTRING_INDEX(GROUP_CONCAT($priceIdr ORDER BY purchase_orders.created_at DESC SEPARATOR '|'), '|', 1) AS DECIMAL(20,4)) as latest_price_idr"),
                DB::raw("SUBSTRING_INDEX(GROUP_CONCAT(quotations.status ORDER BY purchase_orders.created_at DESC SEPARATOR '|'), '|', 1) as latest_status"),
                DB::raw('MAX(purchase_orders.created_at) as last_submitted_at'),
            ])
            ->groupBy('pr_items.material_name')
            ->get();

        $search = $request->input('search.value');
        if (! empty($search)) {
            $rows = $rows->filter(fn ($row) => stripos($row->material_name, $search) !== false)->values();
        }

        return DataTables::collection($rows)
            ->addColumn('action', function ($row) {
                $url = route('supplier.price-history.historical', ['material_name' => $row->material_name]);

                return '<a href="'.$url.'" class="btn btn-sm btn-outline-primary">View History</a>';
            })
            ->addColumn('price_info', function ($row) {
                $latest = $row->latest_price_idr ?? 0;
                $min = $row->min_price_idr ?? 0;
                $max = $row->max_price_idr ?? 0;

                return '<div class="fw-bold text-primary">Rp '.number_format($latest, 0, ',', '.').'</div>'
                    .'<div class="small text-muted">'
                    .'Min: Rp '.number_format($min, 0, ',', '.').' | '
                    .'Max: Rp '.number_format($max, 0, ',', '.')
                    .'</div>';
            })
            ->addColumn('latest_status_badge', fn ($row) => $this->historyBuilder->statusBadge($row->latest_status ?? ''))
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
            ->whereHas('quotationItems.quotation.purchaseOrders', function ($query) use ($supplierId) {
                $query->where('purchase_orders.supplier_id', $supplierId)
                    ->whereNull('purchase_orders.deleted_at');
            })
            ->orderBy('material_name')
            ->get()
            ->map(fn ($item) => [
                'name' => $item->material_name,
                'shape' => $item->shape,
            ]);
    }

    private function dimensionFilters(Request $request): array
    {
        $filters = [];

        foreach (['thickness', 'd_inner', 'd_outer', 'width', 'length'] as $field) {
            $value = $request->input($field);

            if ($value !== null && trim((string) $value) !== '') {
                $filters[$field] = trim((string) $value);
            }
        }

        return $filters;
    }

    private function buildHistorycalSummary($tableData): array
    {
        if ($tableData->isEmpty()) {
            return [
                'average_change_pct' => null,
                'total_change_pct' => null,
            ];
        }

        $changes = $tableData->pluck('change_pct')->filter(fn ($value) => $value !== null)->values();
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

    private function dispatchResponse(Request $request, ExportJob $exportJob)
    {
        $message = 'The export request was accepted. The file will download automatically when ready.';

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }
}
