<?php

namespace App\Http\Controllers\Supplier;

use App\Exports\SupplierPriceHistoryExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Export\AdvancedExportRequest;
use App\Models\ExchangeRate;
use App\Models\ExportJob;
use App\Models\PrItem;
use App\Support\ExportConfirmation;
use App\Support\ExportDispatcher;
use App\Support\NumberFormat;
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
        $currencyOptions = collect();
        $selectedCurrency = null;

        if ($selectedMaterialName) {
            $currencyOptions = $this->historyBuilder->availableCurrencies(
                (int) $supplierId,
                $selectedMaterialName,
                $dimensionFilters,
            );
            $requestedCurrency = strtoupper(trim((string) $request->input('currency')));
            $selectedCurrency = $currencyOptions->contains($requestedCurrency)
                ? $requestedCurrency
                : $currencyOptions->first();

            [$chartData, $tableData] = $this->historyBuilder->build(
                (int) $supplierId,
                $selectedMaterialName,
                $periodView,
                $dateFrom,
                $dimensionFilters,
                $selectedCurrency,
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
            'currency' => $selectedCurrency,
            'currencyOptions' => $currencyOptions->values(),
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
            'currencyOptions',
            'selectedCurrency',
            'payload',
        ));
    }

    public function materials()
    {
        return response()->json([
            'materials' => $this->getSupplierMaterials(auth()->id())->values(),
        ]);
    }

    public function export(AdvancedExportRequest $request)
    {
        $validated = $request->validate([
            'material_name' => ['required', 'string', 'max:255'],
            'period_view' => ['nullable', Rule::in(['monthly', 'yearly'])],
            'range' => ['nullable', Rule::in(['3m', '6m', '12m', '24m', '1y', '2y', '3y', '5y', 'all'])],
            'currency' => ['nullable', Rule::in(ExchangeRate::CURRENCIES)],
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
        $currency = $this->historyBuilder->resolveCurrency(
            $supplierId,
            $materialName,
            $dimensionFilters,
            $validated['currency'] ?? null,
        );
        $currencySuffix = $currency ? '_'.$currency : '';
        $fileName = 'Price_History_'.str_replace([' ', '/'], '_', $materialName).$currencySuffix.'_'.now()->format('YmdHis').'.xlsx'; // biz-time:ignore instant filename

        if ($request->isMethod('get')) {
            return ExportConfirmation::make($request, __('supplier.copy.supplier_price_history'));
        }

        $exportJob = ExportDispatcher::dispatch(
            __('supplier.copy.supplier_price_history'),
            SupplierPriceHistoryExport::class,
            [
                $supplierId,
                $periodView,
                $materialName,
                $dateFrom?->toIso8601String(),
                $dimensionFilters,
                $currency,
            ],
            $fileName,
            $request->exportOptions('supplier.price-history'),
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
            ->whereNull('quotations.deleted_at')
            ->where(function ($query) {
                $query->whereNull('quotation_items.is_available')
                    ->orWhere('quotation_items.is_available', true);
            });

        return [
            'total_materials' => (clone $baseQuery)->distinct('pr_items.material_name')->count('pr_items.material_name'),
            'total_quotations' => (clone $baseQuery)
                ->selectRaw('COUNT(DISTINCT CONCAT(purchase_orders.id, ":", quotation_items.id)) as aggregate')
                ->value('aggregate'),
        ];
    }

    private function getOverviewData(Request $request, $supplierId)
    {
        $baseQuery = DB::table('quotation_items')
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at')
            ->where(function ($query) {
                $query->whereNull('quotation_items.is_available')
                    ->orWhere('quotation_items.is_available', true);
            });

        $rankedLatest = (clone $baseQuery)
            ->select([
                'pr_items.material_name',
                'quotations.currency',
                DB::raw('CAST(quotation_items.price_per_kg AS DECIMAL(20,4)) as latest_price'),
                'quotations.status as latest_status',
                DB::raw('ROW_NUMBER() OVER (PARTITION BY pr_items.material_name, quotations.currency ORDER BY purchase_orders.created_at DESC, purchase_orders.id DESC, quotation_items.id DESC) as rn'),
            ]);

        $latestPerMaterial = DB::query()
            ->fromSub($rankedLatest, 'ranked_latest')
            ->where('rn', 1)
            ->select(['material_name', 'currency', 'latest_price', 'latest_status']);

        $aggregated = (clone $baseQuery)
            ->select([
                'pr_items.material_name',
                'quotations.currency',
                DB::raw('COUNT(DISTINCT CONCAT(purchase_orders.id, ":", quotation_items.id)) as total_quotations'),
                DB::raw('MIN(quotation_items.price_per_kg) as min_price'),
                DB::raw('MAX(quotation_items.price_per_kg) as max_price'),
                DB::raw('MAX(purchase_orders.created_at) as last_submitted_at'),
            ])
            ->groupBy('pr_items.material_name', 'quotations.currency');

        $overviewSub = DB::query()
            ->fromSub($aggregated, 'agg')
            ->joinSub($latestPerMaterial, 'latest', function ($join) {
                $join->on('latest.material_name', '=', 'agg.material_name')
                    ->on('latest.currency', '=', 'agg.currency');
            })
            ->select([
                'agg.material_name',
                'agg.currency',
                'agg.total_quotations',
                'agg.min_price',
                'agg.max_price',
                'latest.latest_price',
                'latest.latest_status',
                'agg.last_submitted_at',
            ]);

        $query = DB::query()->fromSub($overviewSub, 'overview_rows');

        return DataTables::query($query)
            ->filter(function ($query) use ($request) {
                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                    $query->where(function ($sub) use ($like) {
                        $sub->where('material_name', 'like', $like)
                            ->orWhere('currency', 'like', $like);
                    });
                }
            })
            ->addColumn('action', function ($row) {
                $url = route('supplier.price-history.historical', [
                    'material_name' => $row->material_name,
                    'currency' => $row->currency,
                ]);

                return '<a href="'.$url.'" class="ui-data-action ui-data-action--primary ui-focus-ring">'.e(__('supplier.copy.view_history')).'</a>';
            })
            ->addColumn('price_info', function ($row) {
                $latest = $row->latest_price ?? 0;
                $min = $row->min_price ?? 0;
                $max = $row->max_price ?? 0;

                return '<div class="fw-bold text-primary">'.NumberFormat::maxDecimals($latest).' '.e($row->currency).'/Kg</div>'
                    .'<div class="small text-muted">'
                    .'Min: '.NumberFormat::maxDecimals($min).' | '
                    .'Max: '.NumberFormat::maxDecimals($max)
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
            ->whereHas('quotationItems', function ($query) use ($supplierId) {
                $query->where(function ($query) {
                    $query->whereNull('quotation_items.is_available')
                        ->orWhere('quotation_items.is_available', true);
                })->whereHas('quotation.purchaseOrders', function ($query) use ($supplierId) {
                    $query->where('purchase_orders.supplier_id', $supplierId)
                        ->whereNull('purchase_orders.deleted_at');
                });
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
        $firstPrice = $tableData->first()['price_per_kg'] ?? null;
        $lastPrice = $tableData->last()['price_per_kg'] ?? null;
        $averageChangePct = $changes->isNotEmpty() ? $changes->average() : null;
        $totalChangePct = null;

        if ($firstPrice > 0 && $lastPrice !== null) {
            $totalChangePct = (($lastPrice - $firstPrice) / $firstPrice) * 100;
        }

        return [
            'average_change_pct' => $averageChangePct,
            'total_change_pct' => $totalChangePct,
        ];
    }

    private function dispatchResponse(Request $request, ExportJob $exportJob)
    {
        $message = __('supplier.copy.the_export_request_was_accepted_the_file_will_download_automatically_when_ready');

        if ($request->wantsJson()) {
            return response()->json([
                'message' => $message,
                'export_job_id' => $exportJob->getRouteKey(),
                'exports_url' => route('exports.index', absolute: false),
                'status_url' => route('exports.status', $exportJob, absolute: false),
                'cancel_url' => route('exports.cancel', $exportJob, absolute: false),
            ], 202);
        }

        return back()->with('info', $message);
    }
}
