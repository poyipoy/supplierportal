<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Vinkla\Hashids\Facades\Hashids;
use Yajra\DataTables\Facades\DataTables;

class PriceComparisonController extends Controller
{
    private const HISTORICAL_TABLE_PER_PAGE = 50;

    /**
     * View 1: supplier comparison across all quotation items.
     * within a single PR, shown side by side.
     *
     * @return View|JsonResponse
     */
    public function interSupplier(Request $request)
    {
        $eligiblePrs = PurchaseRequisition::query()
            ->select([
                'purchase_requisitions.id',
                'purchase_requisitions.period_id',
                'purchase_requisitions.pr_number',
                'purchase_requisitions.created_at',
            ])
            ->with([
                'period:id,name,month,year',
                'items:id,pr_id,material_name',
            ])
            ->withCount([
                'quotations as eligible_quotation_count' => fn ($query) => $query
                    ->whereIn('status', ['submitted', 'accepted', 'rejected']),
            ])
            ->whereHas('quotations', function ($q) {
                $q->whereIn('status', ['submitted', 'accepted', 'rejected']);
            }, '>=', 2)
            ->where('created_at', '>=', now()->subYears(3)) // Limit the view to the latest three years.
            ->orderByDesc('created_at')
            ->get();

        $comparison = null;
        $chartData = null;
        $chartMaterialIds = [];
        $materialOptions = collect();
        $selectedPr = null;
        $selectedPrOption = null;

        $eligiblePrOptions = $eligiblePrs->map(function ($pr) {
            $quotationCount = (int) $pr->eligible_quotation_count;
            $itemCount = $pr->items->count();
            $label = ($pr->pr_number ?? 'DRAFT')
                .' - '
                .($pr->period->display_label ?? $pr->period->name ?? '-')
                .' ('
                .$quotationCount
                .' quotation(s))';

            $previewMaterials = $pr->items->take(3)->pluck('material_name')->implode(', ');
            if ($itemCount > 3) {
                $previewMaterials .= ' (+'.($itemCount - 3).' lainnya)';
            }

            return [
                'id' => $pr->getRouteKey(),
                'label' => $label,
                'prNumber' => $pr->pr_number ?? 'DRAFT',
                'period' => $pr->period->display_label ?? $pr->period->name ?? '-',
                'quotationCount' => $quotationCount,
                'previewMaterials' => $previewMaterials,
                'search' => strtolower($label),
            ];
        })->values();

        if ($request->filled('pr_id')) {
            $selectedPr = $this->resolveHashedQueryModel(PurchaseRequisition::class, $request->query('pr_id'));
            $selectedPr->loadMissing(['items', 'period']);

            if ($selectedPr) {
                $selectedPrOption = $eligiblePrOptions->firstWhere('id', $selectedPr->getRouteKey());
                $selectedPrOption ??= [
                    'id' => $selectedPr->getRouteKey(),
                    'label' => ($selectedPr->pr_number ?? 'DRAFT').' - '.($selectedPr->period->display_label ?? $selectedPr->period->name ?? '-'),
                ];
                $comparisonItems = $selectedPr->items->values();
                $materialOptions = $comparisonItems;
                $quotations = Quotation::with(['supplier', 'items.prItem', 'exchange_rate'])
                    ->where('pr_id', $selectedPr->id)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected'])
                    ->get();

                $suppliers = $quotations->map(fn ($q) => [
                    'id' => $q->supplier_id,
                    'name' => $q->supplier->name,
                    'currency' => $q->currency,
                    'status' => $q->status,
                    'quotation_id' => $q->id,
                ]);

                $matrix = [];
                foreach ($comparisonItems as $item) {
                    $row = [
                        'item' => $item,
                        'prices' => [],
                    ];

                    foreach ($quotations as $quotation) {
                        $quotationItem = $quotation->items->where('pr_item_id', $item->id)->first();
                        $rate = $quotation->exchange_rate;
                        $pricePerKg = $quotationItem ? (float) $quotationItem->price_per_kg : null;
                        $priceIdr = ($pricePerKg && $rate)
                            ? $pricePerKg * (float) $rate->rate_to_idr
                            : null;

                        $row['prices'][$quotation->id] = [
                            'quotation_id' => $quotation->id,
                            'quotation_item_id' => $quotationItem?->id,
                            'price_per_kg' => $pricePerKg,
                            'price_idr' => $priceIdr,
                            'amount' => $quotationItem && $quotationItem->prItem
                                ? QuotationItem::calculateAmount($quotationItem->prItem, $quotationItem->price_per_kg)
                                : ($quotationItem ? (float) $quotationItem->amount : null),
                            'currency' => $quotation->currency,
                            'detail_url' => $quotationItem
                                ? PurchasingNavigation::toRoute('purchasing.quotations.show', $quotation)
                                : null,
                        ];
                    }

                    $matrix[] = $row;
                }

                $comparison = [
                    'suppliers' => $suppliers,
                    'matrix' => $matrix,
                    'quotations' => $quotations,
                ];

                $chartLabels = $comparisonItems->pluck('material_name')->toArray();
                $chartMaterialIds = $comparisonItems->pluck('id')->map(fn ($id) => (string) $id)->toArray();
                $chartDatasets = [];

                foreach ($quotations as $quotation) {
                    $data = [];

                    foreach ($comparisonItems as $item) {
                        $quotationItem = $quotation->items->where('pr_item_id', $item->id)->first();
                        $rate = $quotation->exchange_rate;
                        $data[] = ($quotationItem && $rate)
                            ? round((float) $quotationItem->price_per_kg * (float) $rate->rate_to_idr, 0)
                            : 0;
                    }

                    $chartDatasets[] = [
                        'label' => $quotation->supplier->name,
                        'data' => $data,
                    ];
                }

                $chartData = [
                    'labels' => $chartLabels,
                    'datasets' => $chartDatasets,
                ];
            }
        }

        return view('purchasing.comparison.inter-supplier', compact(
            'eligiblePrs',
            'comparison',
            'chartData',
            'chartMaterialIds',
            'materialOptions',
            'selectedPr',
            'eligiblePrOptions',
            'selectedPrOption'
        ));
    }

    /**
     * View 2: historical material pricing for one supplier across periods.
     */
    public function historical(Request $request)
    {
        $isJsonRequest = $request->ajax()
            && ($request->wantsJson() || $request->input('view') === 'json');
        $suppliers = $isJsonRequest
            ? collect()
            : User::query()
                ->select(['id', 'name', 'role'])
                ->where('role', 'supplier')
                ->orderBy('name')
                ->get();
        $selectedSupplierValue = $request->query('supplier_id', $request->query('supplier'));
        $selectedSupplier = $this->resolveSupplierQuery($selectedSupplierValue);
        $selectedSupplierId = $selectedSupplier?->getRouteKey();
        $selectedSupplierKey = $selectedSupplier?->getKey();
        $selectedMaterialName = $request->input('material_name');
        $periodView = $request->input('period_view', 'monthly') === 'yearly' ? 'yearly' : 'monthly';
        $range = $this->normalizeHistoricalRange($periodView, $request->input('range'));
        $monthlyRangeOptions = $this->historicalRangeOptions('monthly');
        $yearlyRangeOptions = $this->historicalRangeOptions('yearly');
        $rangeOptions = $this->historicalRangeOptions($periodView);
        $dateFrom = $this->dateFromRange($range);
        if ($isJsonRequest) {
            $materials = collect();
            if (
                $selectedSupplierKey
                && $selectedMaterialName
                && ! $this->historicalMaterialExistsForSupplier($selectedSupplierKey, $selectedMaterialName)
            ) {
                $selectedMaterialName = null;
            }
        } else {
            $materials = $this->historicalMaterialsForSupplier($selectedSupplierKey);
            if ($selectedSupplierId && $selectedMaterialName && ! $materials->pluck('name')->contains($selectedMaterialName)) {
                $selectedMaterialName = null;
            }
        }

        $chartData = null;
        $tableData = collect();
        $tablePagination = null;
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];
        $dimensionFilters = [];
        foreach (['thickness', 'd_inner', 'd_outer', 'width', 'length'] as $field) {
            $val = $request->input($field);
            if ($val !== null && trim((string) $val) !== '') {
                $dimensionFilters[$field] = trim((string) $val);
            }
        }

        if ($selectedSupplierKey && $selectedMaterialName) {
            if ($periodView === 'yearly') {
                [$chartData, $tableData] = $this->buildYearlyHistoricalData(
                    $selectedSupplierKey,
                    $selectedMaterialName,
                    $dateFrom,
                    $dimensionFilters,
                );

                if ($tableData->isEmpty()) {
                    $chartData = null;
                } else {
                    $summary = $this->buildHistoricalSummary($tableData);
                }
            } else {
                [$chartData, $tableData, $tablePagination, $summary] = $this->buildMonthlyHistoricalData(
                    $selectedSupplierKey,
                    $selectedMaterialName,
                    $dateFrom,
                    $dimensionFilters,
                    max(1, $request->integer('history_page', 1)),
                );
            }
        }

        $payload = [
            'chartData' => $chartData,
            'tableData' => $tableData->values(),
            'summary' => $summary,
            'pagination' => $tablePagination,
            'periodView' => $periodView,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'materialName' => $selectedMaterialName,
            'supplierName' => $selectedSupplier->name ?? '',
        ];

        if ($isJsonRequest) {
            return response()->json($payload);
        }

        return view('purchasing.comparison.historical', compact(
            'suppliers',
            'materials',
            'chartData',
            'tableData',
            'summary',
            'tablePagination',
            'selectedSupplierId',
            'selectedMaterialName',
            'periodView',
            'range',
            'rangeOptions',
            'monthlyRangeOptions',
            'yearlyRangeOptions',
            'payload'
        ));
    }

    public function historicalMaterials(Request $request)
    {
        $supplierValue = $request->query('supplier_id', $request->query('supplier'));
        $supplier = $this->resolveSupplierQuery($supplierValue);

        return response()->json([
            'materials' => $this->historicalMaterialsForSupplier($supplier?->getKey())->values(),
        ]);
    }

    /**
     * View 3: current price versus the historical MIN(price_per_kg).
     */
    public function vsBestPrice(Request $request)
    {
        [$dateFrom, $dateTo] = $this->vsBestDateRange($request);
        $dateFromInput = $request->input('date_from');
        $dateToInput = $request->input('date_to');
        $competitiveThreshold = 2.0;
        $summary = $this->emptyVsBestSummary();

        return view('purchasing.comparison.vs-best', compact(
            'dateFromInput',
            'dateToInput',
            'summary',
            'competitiveThreshold'
        ));
    }

    public function vsBestPriceData(Request $request)
    {
        [$dateFrom, $dateTo] = $this->vsBestDateRange($request);
        $competitiveThreshold = 2.0;

        if (! ($dateFrom && $dateTo)) {
            return response()->json([
                'draw' => (int) $request->input('draw', 0),
                'recordsTotal' => 0,
                'recordsFiltered' => 0,
                'data' => [],
                'summary' => $this->emptyVsBestSummary(),
            ]);
        }

        $keyword = trim((string) $request->input('search.value', ''));
        $returnUrl = route('purchasing.comparison.vs-best', $request->only([
            'date_from',
            'date_to',
        ]));
        $summaryQuery = $this->applyVsBestKeywordFilter(
            $this->buildVsBestQuery($dateFrom, $dateTo),
            $keyword
        );
        $summary = $this->buildVsBestSummary($summaryQuery, $competitiveThreshold);

        $dataTable = DataTables::query($this->buildVsBestQuery($dateFrom, $dateTo))
            ->filter(function ($query) use ($keyword) {
                $this->applyVsBestKeywordFilter($query, $keyword);
            })
            ->setFilteredRecords($summary['total_rows']);

        if ($keyword === '') {
            $dataTable->setTotalRecords($summary['total_rows']);
        }

        return $dataTable
            ->addColumn('material_display', function ($row) use ($returnUrl) {
                $prUrl = $this->routeWithReturn(
                    'purchasing.requisitions.show',
                    Hashids::encode((int) $row->current_pr_id),
                    $returnUrl
                );

                return '<div class="fw-bold">'.e($row->material_name).'</div>'
                    .'<div class="text-muted small">Qty: '.number_format((int) ($row->quantity ?? 1), 0, ',', '.').'</div>'
                    .'<div class="text-muted small">Berat/unit: '.$this->formatNumber($row->weight_needed).' kg</div>'
                    .'<div class="text-muted small">Total weight: '.$this->formatNumber($row->total_weight).' kg</div>'
                    .'<a href="'.e($prUrl).'" class="small text-primary text-decoration-none">'
                    .e($row->current_pr_number ?: '-')
                    .'</a>';
            })
            ->addColumn('current_price_display', function ($row) {
                return '<div class="fw-bold text-primary">'.$this->formatRupiah($row->current_price_idr).'</div>'
                    .'<div class="text-muted small">'.$this->formatNumber($row->current_price).' '.e($row->current_currency).'/kg</div>'
                    .'<div class="text-muted small">'.e($row->current_supplier ?: '-').'</div>'
                    .'<div class="text-muted small">'.e($this->formatDate($row->current_submitted_at) ?? 'Draft').'</div>';
            })
            ->addColumn('best_price_display', function ($row) use ($returnUrl) {
                $html = '<div class="fw-bold text-success">'.$this->formatRupiah($row->best_price_idr).'</div>'
                    .'<div class="text-muted small">'.$this->formatNumber($row->best_price).' '.e($row->best_currency).'/kg</div>'
                    .'<div class="text-muted small">'.e($row->best_supplier ?: '-').'</div>';

                if ($row->best_pr_id) {
                    $bestPrUrl = $this->routeWithReturn(
                        'purchasing.requisitions.show',
                        Hashids::encode((int) $row->best_pr_id),
                        $returnUrl
                    );
                    $html .= '<a href="'.e($bestPrUrl).'" class="small text-primary text-decoration-none">'
                        .e($row->best_pr_number ?: '-')
                        .'</a>';
                } else {
                    $html .= '<div class="text-muted small">PR: -</div>';
                }

                return $html.'<div class="text-muted small">'.e($this->formatDate($row->best_submitted_at) ?? '-').'</div>';
            })
            ->addColumn('diff_display', function ($row) {
                $diff = $row->diff_idr_per_kg !== null ? (float) $row->diff_idr_per_kg : null;
                $class = $diff > 0 ? 'tw-text-error' : ($diff < 0 ? 'tw-text-success' : 'tw-text-on-surface-variant');

                return '<div class="fw-bold '.$class.'">'.$this->formatSignedRupiah($diff).'</div>'
                    .'<div class="small '.$class.'">'.$this->formatPercent($row->diff_percent).'</div>';
            })
            ->addColumn('potential_difference_display', fn ($row) => '<span class="fw-bold">'.$this->formatRupiah($row->potential_difference_idr).'</span>')
            ->addColumn('status_badge', function ($row) use ($competitiveThreshold) {
                $status = $this->priceCompetitivenessStatus(
                    $row->diff_percent !== null ? (float) $row->diff_percent : null,
                    $competitiveThreshold
                );

                $tone = str_contains($status['class'], 'danger')
                    ? 'error'
                    : (str_contains($status['class'], 'warning')
                        ? 'warning'
                        : (str_contains($status['class'], 'success') ? 'success' : 'neutral'));

                return '<span class="ui-status-chip ui-status-chip--'.$tone.'">'
                    .e($status['label'])
                    .'</span><div class="text-muted small mt-1">'.e($status['recommendation']).'</div>';
            })
            ->addColumn('action', function ($row) use ($returnUrl) {
                $currentQuotationUrl = $this->routeWithReturn(
                    'purchasing.quotations.show',
                    Hashids::encode((int) $row->current_quotation_id),
                    $returnUrl
                );
                $html = '<div class="d-inline-flex align-items-center gap-1" role="group" aria-label="Quotation comparison actions">'
                    .'<a href="'.e($currentQuotationUrl).'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="View current quotation">Current</a>';

                if ($row->best_quotation_id) {
                    $bestQuotationUrl = $this->routeWithReturn(
                        'purchasing.quotations.show',
                        Hashids::encode((int) $row->best_quotation_id),
                        $returnUrl
                    );
                    $html .= '<a href="'.e($bestQuotationUrl).'" class="ui-data-action ui-data-action--success ui-focus-ring" aria-label="View historical best quotation">Best</a>';
                }

                return $html.'</div>';
            })
            ->rawColumns([
                'material_display',
                'current_price_display',
                'best_price_display',
                'diff_display',
                'potential_difference_display',
                'status_badge',
                'action',
            ])
            ->with('summary', $summary)
            ->make(true);
    }

    private function buildVsBestQuery(?Carbon $dateFrom = null, ?Carbon $dateTo = null)
    {
        $currentStatuses = ['submitted', 'accepted'];
        $historyPriceIdr = '(history_items.price_per_kg * COALESCE(history_po_rate.rate_to_idr, history_quote_rate.rate_to_idr, 1))';
        $currentPriceIdr = '(current_items.price_per_kg * current_rate.rate_to_idr)';
        $bestPriceIdr = '(best_items.price_per_kg * COALESCE(best_po_rate.rate_to_idr, best_quote_rate.rate_to_idr, 1))';
        $diffIdrPerKg = "($currentPriceIdr - $bestPriceIdr)";
        $diffPercent = "CASE WHEN $bestPriceIdr > 0 AND $currentPriceIdr IS NOT NULL THEN (($diffIdrPerKg / $bestPriceIdr) * 100) ELSE NULL END";
        $currentTotalWeight = '(current_pr_items.weight_needed * CASE WHEN current_pr_items.quantity IS NULL OR current_pr_items.quantity < 1 THEN 1 ELSE current_pr_items.quantity END)';
        $potentialDifference = "CASE WHEN $currentPriceIdr IS NOT NULL AND $bestPriceIdr IS NOT NULL THEN GREATEST(0, $diffIdrPerKg) * $currentTotalWeight ELSE NULL END";

        $bestPriceByMaterial = DB::table('quotation_items as history_items')
            ->join('po_quotations as history_po_links', 'history_items.quotation_id', '=', 'history_po_links.quotation_id')
            ->join('purchase_orders as history_pos', 'history_po_links.po_id', '=', 'history_pos.id')
            ->join('quotations as history_quotes', 'history_items.quotation_id', '=', 'history_quotes.id')
            ->leftJoin('exchange_rates as history_po_rate', 'history_pos.exchange_rate_id', '=', 'history_po_rate.id')
            ->leftJoin('exchange_rates as history_quote_rate', 'history_quotes.exchange_rate_id', '=', 'history_quote_rate.id')
            ->join('pr_items as history_pr_items', 'history_items.pr_item_id', '=', 'history_pr_items.id')
            ->whereNull('history_pos.deleted_at')
            ->whereNull('history_quotes.deleted_at')
            ->selectRaw('history_pr_items.material_name, MIN('.$historyPriceIdr.') as best_price_idr')
            ->groupBy('history_pr_items.material_name');

        $bestItemByMaterial = DB::table('quotation_items as history_items')
            ->join('po_quotations as history_po_links', 'history_items.quotation_id', '=', 'history_po_links.quotation_id')
            ->join('purchase_orders as history_pos', 'history_po_links.po_id', '=', 'history_pos.id')
            ->join('quotations as history_quotes', 'history_items.quotation_id', '=', 'history_quotes.id')
            ->leftJoin('exchange_rates as history_po_rate', 'history_pos.exchange_rate_id', '=', 'history_po_rate.id')
            ->leftJoin('exchange_rates as history_quote_rate', 'history_quotes.exchange_rate_id', '=', 'history_quote_rate.id')
            ->join('pr_items as history_pr_items', 'history_items.pr_item_id', '=', 'history_pr_items.id')
            ->joinSub($bestPriceByMaterial, 'best_price', function ($join) use ($historyPriceIdr) {
                $join->on('best_price.material_name', '=', 'history_pr_items.material_name')
                    ->whereRaw('ABS(('.$historyPriceIdr.') - best_price.best_price_idr) < 0.0001');
            })
            ->whereNull('history_pos.deleted_at')
            ->whereNull('history_quotes.deleted_at')
            ->selectRaw('best_price.material_name, MIN(history_items.id) as best_item_id')
            ->groupBy('best_price.material_name');

        $query = DB::table('quotation_items as current_items')
            ->join('quotations as current_quotes', 'current_items.quotation_id', '=', 'current_quotes.id')
            ->join('pr_items as current_pr_items', 'current_items.pr_item_id', '=', 'current_pr_items.id')
            ->join('purchase_requisitions as current_pr', 'current_pr_items.pr_id', '=', 'current_pr.id')
            ->leftJoin('users as current_supplier', 'current_quotes.supplier_id', '=', 'current_supplier.id')
            ->leftJoin('exchange_rates as current_rate', 'current_quotes.exchange_rate_id', '=', 'current_rate.id')
            ->leftJoinSub($bestItemByMaterial, 'best_choice', function ($join) {
                $join->on('best_choice.material_name', '=', 'current_pr_items.material_name');
            })
            ->leftJoin('quotation_items as best_items', 'best_choice.best_item_id', '=', 'best_items.id')
            ->leftJoin('quotations as best_quotes', 'best_items.quotation_id', '=', 'best_quotes.id')
            ->leftJoin('users as best_supplier', 'best_quotes.supplier_id', '=', 'best_supplier.id')
            ->leftJoin('po_quotations as best_po_links', 'best_items.quotation_id', '=', 'best_po_links.quotation_id')
            ->leftJoin('purchase_orders as best_pos', 'best_po_links.po_id', '=', 'best_pos.id')
            ->leftJoin('exchange_rates as best_po_rate', 'best_pos.exchange_rate_id', '=', 'best_po_rate.id')
            ->leftJoin('exchange_rates as best_quote_rate', 'best_quotes.exchange_rate_id', '=', 'best_quote_rate.id')
            ->leftJoin('pr_items as best_pr_items', 'best_items.pr_item_id', '=', 'best_pr_items.id')
            ->leftJoin('purchase_requisitions as best_pr', function ($join) {
                $join->on('best_pr_items.pr_id', '=', 'best_pr.id')
                    ->whereNull('best_pr.deleted_at');
            })
            ->whereIn('current_quotes.status', $currentStatuses)
            ->whereNull('current_quotes.deleted_at')
            ->whereNull('current_pr.deleted_at')
            ->select([
                'current_items.id as current_item_id',
                'current_items.quotation_id as current_quotation_id',
                'current_items.price_per_kg as current_price',
                'current_items.amount as current_amount',
                'current_quotes.currency as current_currency',
                'current_quotes.submitted_at as current_submitted_at',
                'current_pr_items.material_name',
                'current_pr_items.quantity',
                'current_pr_items.weight_needed',
                'current_pr.id as current_pr_id',
                'current_pr.pr_number as current_pr_number',
                'current_supplier.name as current_supplier',
                'best_items.id as best_item_id',
                'best_items.quotation_id as best_quotation_id',
                'best_items.price_per_kg as best_price',
                'best_quotes.currency as best_currency',
                'best_pos.created_at as best_submitted_at',
                'best_supplier.name as best_supplier',
                'best_pr.id as best_pr_id',
                'best_pr.pr_number as best_pr_number',
            ])
            ->selectRaw($currentPriceIdr.' as current_price_idr')
            ->selectRaw('('.$currentPriceIdr.' * '.$currentTotalWeight.') as current_total_idr')
            ->selectRaw($currentTotalWeight.' as total_weight')
            ->selectRaw($bestPriceIdr.' as best_price_idr')
            ->selectRaw($diffIdrPerKg.' as diff_idr_per_kg')
            ->selectRaw($diffPercent.' as diff_percent')
            ->selectRaw($potentialDifference.' as potential_difference_idr');

        if ($dateFrom && $dateTo) {
            $query->whereBetween('current_quotes.submitted_at', [$dateFrom, $dateTo]);
        }

        return $query->orderByRaw('potential_difference_idr DESC')
            ->orderBy('current_pr_items.material_name');
    }

    private function applyVsBestKeywordFilter($query, string $keyword)
    {
        if ($keyword === '') {
            return $query;
        }

        $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $keyword).'%';

        return $query->where(function ($q) use ($like) {
            $q->where('current_pr_items.material_name', 'like', $like)
                ->orWhere('current_pr.pr_number', 'like', $like)
                ->orWhere('current_supplier.name', 'like', $like)
                ->orWhere('best_supplier.name', 'like', $like)
                ->orWhere('best_pr.pr_number', 'like', $like);
        });
    }

    private function extractDimensionFilters(Request $request): array
    {
        $filters = [];

        foreach (array_merge(['shape'], PrItem::DIMENSION_FIELDS, ['weight_needed']) as $field) {
            $value = $request->input($field);
            if ($value !== null && trim((string) $value) !== '') {
                $filters[$field] = trim((string) $value);
            }
        }

        return $filters;
    }

    private function matchesDimensionFilters(PrItem $item, array $filters): bool
    {
        foreach ($filters as $field => $value) {
            if ($field === 'shape') {
                if ((string) $item->shape !== $value) {
                    return false;
                }

                continue;
            }

            $actual = $item->{$field};
            if ($actual === null || (float) $actual !== (float) $value) {
                return false;
            }
        }

        return true;
    }

    private function vsBestDateRange(Request $request): array
    {
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');

        if (! $dateFrom || ! $dateTo) {
            return [null, null];
        }

        try {
            $startDate = Carbon::createFromFormat('Y-m', $dateFrom)->startOfMonth();
            $endDate = Carbon::createFromFormat('Y-m', $dateTo)->endOfMonth();

            if ($startDate->greaterThan($endDate)) {
                return [null, null];
            }

            return [$startDate, $endDate];
        } catch (\Exception $e) {
            return [null, null];
        }
    }

    private function buildVsBestSummary($query, float $competitiveThreshold): array
    {
        $row = DB::query()
            ->fromSub($query, 'vs_best_rows')
            ->selectRaw('COUNT(*) as total_rows')
            ->selectRaw('SUM(CASE WHEN diff_percent IS NOT NULL AND diff_percent <= ? THEN 1 ELSE 0 END) as competitive_count', [$competitiveThreshold])
            ->selectRaw('SUM(CASE WHEN diff_percent IS NOT NULL AND diff_percent > ? THEN 1 ELSE 0 END) as above_count', [$competitiveThreshold])
            ->selectRaw('SUM(COALESCE(potential_difference_idr, 0)) as total_potential_difference_idr')
            ->selectRaw('AVG(diff_idr_per_kg) as average_diff_idr_per_kg')
            ->first();

        if (! $row) {
            return $this->emptyVsBestSummary();
        }

        return [
            'total_rows' => (int) $row->total_rows,
            'competitive_count' => (int) $row->competitive_count,
            'above_count' => (int) $row->above_count,
            'total_potential_difference_idr' => round((float) $row->total_potential_difference_idr, 0),
            'average_diff_idr_per_kg' => $row->average_diff_idr_per_kg !== null
                ? round((float) $row->average_diff_idr_per_kg, 0)
                : null,
        ];
    }

    private function emptyVsBestSummary(): array
    {
        return [
            'total_rows' => 0,
            'competitive_count' => 0,
            'above_count' => 0,
            'total_potential_difference_idr' => 0,
            'average_diff_idr_per_kg' => null,
        ];
    }

    private function priceCompetitivenessStatus(?float $diffPercent, float $competitiveThreshold): array
    {
        if ($diffPercent === null) {
            return [
                'label' => 'N/A',
                'class' => 'bg-secondary',
                'icon' => 'circle-minus',
                'recommendation' => 'Safe',
            ];
        }

        if ($diffPercent <= 0) {
            return [
                'label' => 'Best Price',
                'class' => 'bg-success',
                'icon' => 'check-circle',
                'recommendation' => 'Safe',
            ];
        }

        if ($diffPercent <= $competitiveThreshold) {
            return [
                'label' => 'Competitive',
                'class' => 'bg-primary',
                'icon' => 'shield-check',
                'recommendation' => 'Safe',
            ];
        }

        return [
            'label' => 'Above History',
            'class' => 'bg-warning text-dark',
            'icon' => 'info',
            'recommendation' => 'Safe, check context',
        ];
    }

    /** @param class-string<Model> $modelClass */
    private function resolveHashedQueryModel(string $modelClass, mixed $value): Model
    {
        abort_unless(is_string($value) && $value !== '' && ! ctype_digit($value), 404);

        try {
            $model = (new $modelClass)->resolveRouteBinding($value);
        } catch (\Throwable) {
            $model = null;
        }

        abort_unless($model instanceof Model, 404);

        return $model;
    }

    private function resolveSupplierQuery(mixed $value): ?User
    {
        if ($value === null || $value === '') {
            return null;
        }

        $supplier = $this->resolveHashedQueryModel(User::class, $value);
        abort_unless($supplier instanceof User && $supplier->role === 'supplier', 404);

        return $supplier;
    }

    private function routeWithReturn(string $routeName, mixed $parameters, string $returnUrl): string
    {
        $parameters = is_array($parameters) ? $parameters : [$parameters];
        $parameters[PurchasingNavigation::RETURN_URL_KEY] = $returnUrl;

        return route($routeName, $parameters);
    }

    private function historicalMaterialsForSupplier($supplierId)
    {
        if (! $supplierId) {
            return collect();
        }

        return PrItem::query()
            ->select('material_name', 'shape')
            ->distinct()
            ->whereNotNull('material_name')
            ->where('material_name', '<>', '')
            ->whereHas('quotationItems.quotation.purchaseOrders', function ($q) use ($supplierId) {
                $q->where('purchase_orders.supplier_id', $supplierId)
                    ->whereNull('purchase_orders.deleted_at');
            })
            ->orderBy('material_name')
            ->get()
            ->unique('material_name')
            ->map(function ($item) {
                return [
                    'name' => $item->material_name,
                    'shape' => $item->shape,
                ];
            })
            ->values();
    }

    private function historicalMaterialExistsForSupplier($supplierId, string $materialName): bool
    {
        return PrItem::query()
            ->where('material_name', $materialName)
            ->whereHas('quotationItems.quotation.purchaseOrders', function ($query) use ($supplierId) {
                $query->where('purchase_orders.supplier_id', $supplierId)
                    ->whereNull('purchase_orders.deleted_at');
            })
            ->exists();
    }

    private function formatRupiah($value): string
    {
        return $value !== null
            ? 'Rp '.number_format((float) $value, 0, ',', '.')
            : '-';
    }

    private function formatSignedRupiah($value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = (float) $value;
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');

        return $prefix.'Rp '.number_format(abs($value), 0, ',', '.');
    }

    private function formatNumber($value, int $decimals = 2): string
    {
        return $value !== null
            ? number_format((float) $value, $decimals, ',', '.')
            : '-';
    }

    private function formatPercent($value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = (float) $value;

        return ($value > 0 ? '+' : '').number_format($value, 2, ',', '.').'%';
    }

    private function formatDate($value): ?string
    {
        return $value
            ? \Illuminate\Support\Carbon::parse($value)->format('d M Y')
            : null;
    }

    private function buildMonthlyHistoricalData(
        $supplierId,
        string $materialName,
        $dateFrom,
        array $dimensionFilters = [],
        int $page = 1,
    ): array {
        $baseQuery = QuotationItem::query()
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->leftJoin('exchange_rates as history_po_rates', 'purchase_orders.exchange_rate_id', '=', 'history_po_rates.id')
            ->leftJoin('exchange_rates as history_quote_rates', 'quotations.exchange_rate_id', '=', 'history_quote_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->leftJoin('purchase_requisitions as history_pr', function ($join) {
                $join->on('history_pr.id', '=', 'quotations.pr_id')
                    ->whereNull('history_pr.deleted_at');
            })
            ->leftJoin('periods as history_period', 'history_pr.period_id', '=', 'history_period.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at')
            ->where('pr_items.material_name', $materialName);

        foreach ($dimensionFilters as $field => $val) {
            $baseQuery->where("pr_items.{$field}", $val);
        }

        if ($dateFrom) {
            $baseQuery->where('purchase_orders.created_at', '>=', $dateFrom);
        }

        $seriesItems = (clone $baseQuery)
            ->select([
                'quotation_items.id',
                'quotation_items.price_per_kg',
                'purchase_orders.id as history_po_id',
                'purchase_orders.created_at as history_po_created_at',
                'history_period.name as history_period_name',
                'history_period.month as history_period_month',
                'history_period.year as history_period_year',
            ])
            ->selectRaw('COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr) as history_rate_to_idr')
            ->orderBy('purchase_orders.created_at', 'asc')
            ->orderBy('quotation_items.id', 'asc')
            ->orderBy('purchase_orders.id', 'asc')
            ->get()
            ->map(function ($item) {
                $purchaseAt = $item->history_po_created_at
                    ? Carbon::parse($item->history_po_created_at)
                    : null;
                $rate = $item->history_rate_to_idr;
                $priceIdr = $rate !== null
                    ? round((float) $item->price_per_kg * (float) $rate, 0)
                    : null;
                $periodLabel = $purchaseAt?->format('M Y')
                    ?? $this->historicalPeriodLabel(
                        $item->history_period_name,
                        $item->history_period_month,
                        $item->history_period_year,
                    );

                return [
                    'row_key' => $item->id.':'.$item->history_po_id,
                    'period' => $periodLabel,
                    'period_sort' => $purchaseAt
                        ? $purchaseAt->format('Y-m-d H:i:s').'-'.str_pad((string) $item->id, 10, '0', STR_PAD_LEFT)
                        : sprintf('9999-99-99 99:99:99-%010d', (int) $item->id),
                    'price_per_kg' => (float) $item->price_per_kg,
                    'price_idr' => $priceIdr,
                ];
            })
            ->sortBy('period_sort', SORT_NATURAL)
            ->values();

        if ($seriesItems->isEmpty()) {
            return [null, collect(), null, [
                'average_change_pct' => null,
                'total_change_pct' => null,
            ]];
        }

        $seriesItems = $this->appendChangePercent($seriesItems);
        $changeByRow = $seriesItems->pluck('change_pct', 'row_key');
        $totalRows = $seriesItems->count();
        $lastPage = max(1, (int) ceil($totalRows / self::HISTORICAL_TABLE_PER_PAGE));
        $page = min(max(1, $page), $lastPage);

        $items = (clone $baseQuery)
            ->select([
                'quotation_items.*',
                'purchase_orders.id as history_po_id',
                'purchase_orders.po_number as history_po_number',
                'purchase_orders.created_at as history_po_created_at',
            ])
            ->selectRaw('COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr) as history_rate_to_idr')
            ->with([
                'quotation.supplier',
                'quotation.purchaseRequisition.period',
                'prItem.purchaseRequisition' => function ($q) {
                    $q->select('id', 'pr_number');
                },
            ])
            ->orderBy('purchase_orders.created_at', 'asc')
            ->orderBy('quotation_items.id', 'asc')
            ->orderBy('purchase_orders.id', 'asc')
            ->offset(($page - 1) * self::HISTORICAL_TABLE_PER_PAGE)
            ->limit(self::HISTORICAL_TABLE_PER_PAGE)
            ->get();

        $tableData = $items->map(function ($item) use ($changeByRow) {
            $period = optional(optional($item->quotation->purchaseRequisition)->period);
            $rate = $item->history_rate_to_idr;
            $priceIdr = $rate !== null ? round((float) $item->price_per_kg * (float) $rate, 0) : null;
            $totalAmount = $item->prItem
                ? QuotationItem::calculateAmount($item->prItem, $item->price_per_kg)
                : (float) $item->amount;
            $totalIdr = $rate !== null ? round($totalAmount * (float) $rate, 0) : null;
            $purchaseRequisition = $item->prItem?->purchaseRequisition;
            $purchaseAt = $item->history_po_created_at
                ? Carbon::parse($item->history_po_created_at)
                : null;
            $quotationSubmittedAt = $item->quotation->submitted_at;
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
                'pr_url' => $purchaseRequisition
                    ? PurchasingNavigation::toRoute('purchasing.requisitions.show', $purchaseRequisition)
                    : null,
                'supplier' => $item->quotation->supplier->name ?? '-',
                'price_per_kg' => (float) $item->price_per_kg,
                'currency' => $item->quotation->currency,
                'price_idr' => $priceIdr,
                'total_idr' => $totalIdr,
                'min_idr' => null,
                'max_idr' => null,
                'submitted_at' => $purchaseAt?->toIso8601String(),
                'submitted_at_display' => $purchaseAt?->format('d M Y'),
                'quotation_submitted_at' => $quotationSubmittedAt?->toIso8601String(),
                'change_pct' => $changeByRow->get($item->id.':'.$item->history_po_id),
            ];
        })->sortBy('period_sort', SORT_NATURAL)->values();

        $chartData = [
            'type' => 'monthly',
            'labels' => $seriesItems->pluck('period')->values(),
            'prices' => $seriesItems->pluck('price_per_kg')->values(),
            'pricesIdr' => $seriesItems->pluck('price_idr')->map(fn ($price) => $price ?? 0)->values(),
        ];
        $firstRow = (($page - 1) * self::HISTORICAL_TABLE_PER_PAGE) + 1;
        $pagination = [
            'current_page' => $page,
            'last_page' => $lastPage,
            'per_page' => self::HISTORICAL_TABLE_PER_PAGE,
            'total' => $totalRows,
            'from' => $firstRow,
            'to' => min($totalRows, $firstRow + self::HISTORICAL_TABLE_PER_PAGE - 1),
        ];

        return [
            $chartData,
            $tableData,
            $pagination,
            $this->buildHistoricalSummary($seriesItems),
        ];
    }

    private function historicalPeriodLabel($name, $month, $year): string
    {
        if ($year === null) {
            return 'Unknown';
        }

        return $month === null
            ? (string) $year
            : $name.' ('.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'/'.$year.')';
    }

    private function buildYearlyHistoricalData($supplierId, string $materialName, $dateFrom, array $dimensionFilters = []): array
    {
        $query = QuotationItem::query()
            ->join('po_quotations', 'quotation_items.quotation_id', '=', 'po_quotations.quotation_id')
            ->join('purchase_orders', 'po_quotations.po_id', '=', 'purchase_orders.id')
            ->join('quotations', 'quotation_items.quotation_id', '=', 'quotations.id')
            ->leftJoin('exchange_rates as history_po_rates', 'purchase_orders.exchange_rate_id', '=', 'history_po_rates.id')
            ->leftJoin('exchange_rates as history_quote_rates', 'quotations.exchange_rate_id', '=', 'history_quote_rates.id')
            ->join('pr_items', 'quotation_items.pr_item_id', '=', 'pr_items.id')
            ->where('purchase_orders.supplier_id', $supplierId)
            ->whereNull('purchase_orders.deleted_at')
            ->whereNull('quotations.deleted_at')
            ->where('pr_items.material_name', $materialName);

        foreach ($dimensionFilters as $field => $val) {
            $query->where("pr_items.{$field}", $val);
        }

        if ($dateFrom) {
            $query->where('purchase_orders.created_at', '>=', $dateFrom);
        }

        $rows = $query
            ->select([])
            ->selectRaw('
                YEAR(purchase_orders.created_at) as period_year,
                AVG(quotation_items.price_per_kg * COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr, 1)) as avg_idr,
                MIN(quotation_items.price_per_kg * COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr, 1)) as min_idr,
                MAX(quotation_items.price_per_kg * COALESCE(history_po_rates.rate_to_idr, history_quote_rates.rate_to_idr, 1)) as max_idr
            ')
            ->groupByRaw('YEAR(purchase_orders.created_at)')
            ->orderByRaw('YEAR(purchase_orders.created_at) ASC')
            ->get();

        $tableData = $rows->map(function ($row) {
            return [
                'period' => (string) $row->period_year,
                'period_sort' => (int) $row->period_year,
                'price_per_kg' => null,
                'currency' => 'IDR',
                'price_idr' => round((float) $row->avg_idr, 0),
                'min_idr' => round((float) $row->min_idr, 0),
                'max_idr' => round((float) $row->max_idr, 0),
                'submitted_at' => null,
            ];
        })->sortBy('period_sort')->values();

        $tableData = $this->appendChangePercent($tableData);

        return [[
            'type' => 'yearly',
            'labels' => $tableData->pluck('period')->values(),
            'prices' => [],
            'pricesIdr' => $tableData->pluck('price_idr')->values(),
            'minIdr' => $tableData->pluck('min_idr')->values(),
            'maxIdr' => $tableData->pluck('max_idr')->values(),
        ], $tableData];
    }

    private function appendChangePercent($rows)
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

    private function buildHistoricalSummary($rows): array
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

    private function historicalRangeOptions(string $periodView): array
    {
        if ($periodView === 'yearly') {
            return [
                '1y' => '1 Year',
                '2y' => '2 Years',
                '5y' => '5 Years',
                'all' => 'All Years',
            ];
        }

        return [
            '3m' => '3 Months',
            '6m' => '6 Months',
            '12m' => '12 Months',
            '24m' => '24 Months',
            'all' => 'All Months',
        ];
    }

    private function normalizeHistoricalRange(string $periodView, ?string $range): string
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

    private function dateFromRange(string $range)
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
