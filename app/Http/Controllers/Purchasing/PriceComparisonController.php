<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Models\ExchangeRate;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\User;
use App\Services\Pricing\ExchangeRateResolver;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Services\Pricing\ReferencePriceResolver;
use App\Services\PrItemAwardService;
use App\Services\PurchaseOrderGenerationService;
use App\Services\RegionalDisplayFormatter;
use App\Support\HashidCodec;
use App\Support\Money;
use App\Support\NumberFormat;
use App\Support\Pricing\EvaluationContext;
use App\Support\Procurement\OrderSelectionInput;
use App\Support\PurchasingNavigation;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PriceComparisonController extends Controller
{
    private const HISTORICAL_TABLE_PER_PAGE = 50;

    private const HISTORICAL_KEY_OPTION_LIMIT = 100;

    public function __construct(
        private ?RegionalDisplayFormatter $regionalFormatter = null
    ) {
        $this->regionalFormatter ??= app(RegionalDisplayFormatter::class);
    }

    /**
     * View 1: supplier comparison across all quotation items.
     * within a single PR, shown side by side.
     */
    public function interSupplier(Request $request): View|JsonResponse
    {
        // Mission 07 (Q4, Q9): analytics use the benchmark at one evaluation date.
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();
        $eligiblePrCounts = DB::table('quotations')
            ->select('pr_id', DB::raw('COUNT(*) as quotation_count'))
            ->whereNull('deleted_at')
            ->whereIn('status', ['submitted', 'accepted', 'rejected'])
            ->groupBy('pr_id')
            ->havingRaw('COUNT(*) >= 2')
            ->pluck('quotation_count', 'pr_id');

        $eligiblePrIds = $eligiblePrCounts->keys();

        $eligiblePrs = $eligiblePrIds->isEmpty()
            ? collect()
            : PurchaseRequisition::query()
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
                ->whereIn('purchase_requisitions.id', $eligiblePrIds)
                ->where('created_at', '>=', now()->subYears(3))
                ->orderByDesc('created_at')
                ->get();

        $comparison = null;
        $chartData = null;
        $chartMaterialIds = [];
        $materialOptions = collect();
        $selectedPr = null;
        $selectedPrOption = null;
        $existingAwards = collect();
        $awardCoverage = null;
        $supplierGrouping = collect();
        $assignedPurchaseOrders = collect();
        $assignedPurchaseOrderCount = 0;
        $hasActionableAwardSelections = false;
        $allItemsAssignedToPurchaseOrder = false;

        $eligiblePrOptions = $eligiblePrs->map(function ($pr) use ($eligiblePrCounts) {
            $quotationCount = (int) ($eligiblePrCounts[$pr->id] ?? 0);
            $pr->eligible_quotation_count = $quotationCount;
            $itemCount = $pr->items->count();
            $prNumber = $pr->pr_number ?? __('purchasing.copy.draft');
            $periodLabel = $pr->period->display_label ?? $pr->period->name ?? '-';
            $quotationCountLabel = trans_choice('purchasing.copy.comparison_quotation_count', $quotationCount, ['count' => $quotationCount]);
            $label = $prNumber.' - '.$periodLabel.' ('.$quotationCountLabel.')';

            $previewMaterials = $pr->items->take(3)->pluck('material_name')->implode(', ');
            if ($itemCount > 3) {
                $previewMaterials .= ' ('.__('purchasing.copy.more_materials_count', ['count' => $itemCount - 3]).')';
            }

            return [
                'id' => $pr->getRouteKey(),
                'label' => $label,
                'prNumber' => $prNumber,
                'period' => $pr->period->display_label ?? $pr->period->name ?? '-',
                'quotationCount' => $quotationCount,
                'quotationCountLabel' => $quotationCountLabel,
                'previewMaterials' => $previewMaterials,
                'search' => strtolower($label),
            ];
        })->values();

        if ($request->filled('pr_id')) {
            $selectedPr = $this->resolveHashedQueryModel(PurchaseRequisition::class, $request->query('pr_id'));
            $selectedPr->loadMissing([
                'items',
                'period',
                'awards.supplier',
                'awards.quotationItem',
                'awards.purchaseOrder',
            ]);

            if ($selectedPr) {
                $selectedPrOption = $eligiblePrOptions->firstWhere('id', $selectedPr->getRouteKey());
                $selectedPrOption ??= [
                    'id' => $selectedPr->getRouteKey(),
                    'label' => ($selectedPr->pr_number ?? __('purchasing.copy.draft')).' - '.($selectedPr->period->display_label ?? $selectedPr->period->name ?? '-'),
                ];
                $comparisonItems = $selectedPr->items->values();
                $materialOptions = $comparisonItems;
                $existingAwards = $selectedPr->awards->keyBy('pr_item_id');
                $awardService = app(PrItemAwardService::class);
                $awardCoverage = $awardService->getCoverage($selectedPr);
                $supplierGrouping = $awardService->getSupplierGrouping($selectedPr);

                $quotations = Quotation::with(['supplier.supplier', 'items.prItem', 'exchange_rate'])
                    ->where('pr_id', $selectedPr->id)
                    ->whereIn('status', ['submitted', 'accepted', 'rejected'])
                    ->get();

                $suppliers = $quotations->map(fn ($q) => [
                    'id' => $q->supplier_id,
                    // PUR-03: company name, no contact person, on Price Comparison.
                    'name' => self::supplierDisplayName($q->supplier),
                    'currency' => $q->currency,
                    'status' => $q->status,
                    'quotation_id' => $q->id,
                    'estimated_delivery' => $q->estimated_delivery ? $q->estimated_delivery->format('Y-m-d') : null,
                    'estimated_delivery_formatted' => $q->estimated_delivery ? $this->regionalFormatter->date($q->estimated_delivery, 'human') : '-',
                ]);

                $matrix = [];
                foreach ($comparisonItems as $item) {
                    $award = $existingAwards->get($item->id);
                    $row = [
                        'item' => $item,
                        'award' => $award,
                        'prices' => [],
                    ];

                    foreach ($quotations as $quotation) {
                        $quotationItem = $quotation->items->where('pr_item_id', $item->id)->first();
                        $rate = $quotation->exchange_rate;
                        $pricePerKg = $quotationItem?->price_per_kg === null
                            ? null
                            : (float) $quotationItem->price_per_kg;
                        $priceIdr = ($pricePerKg !== null && $rate)
                            ? $pricePerKg * (float) $rate->rate_to_idr
                            : null;

                        $requestedAmount = $quotationItem && $quotationItem->prItem
                            ? QuotationItem::calculateRequestedAmount($quotationItem->prItem, $quotationItem->price_per_kg)
                            : ($quotationItem ? (float) $quotationItem->amount : null);
                        $offerAmount = $quotationItem?->resolved_amount;
                        $isAvailable = $quotationItem?->is_available ?? false;
                        $offerAmountIdr = $quotationItem && $isAvailable && $rate
                            ? $offerAmount * (float) $rate->rate_to_idr
                            : null;

                        $isAwarded = $award && (int) $award->quotation_item_id === (int) $quotationItem?->id;
                        $isSelectable = $isAvailable && in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true) && (! $award || $award->purchase_order_id === null);

                        $row['prices'][$quotation->id] = [
                            'quotation_id' => $quotation->id,
                            'quotation_item_id' => $quotationItem?->id,
                            'price_per_kg' => $pricePerKg,
                            'price_idr' => $priceIdr,
                            // `amount` is the long-standing requested-total
                            // comparison value.  Expose the official stored
                            // Offer Amount separately for consumers that need
                            // quotation/PO financial semantics.
                            'amount' => $requestedAmount,
                            'requested_amount' => $requestedAmount,
                            'offer_amount' => $offerAmount,
                            'offer_amount_idr' => $offerAmountIdr,
                            'is_available' => $isAvailable,
                            'is_awarded' => $isAwarded,
                            'is_selectable' => $isSelectable,
                            // M04-W order quantity / specification acceptance controls.
                            'ordered_qty' => $isAwarded ? $award?->ordered_qty : null,
                            'spec_code' => $quotationItem && $isAvailable ? $quotationItem->availability_comparison['specification']['code'] : null,
                            'requested_spec' => $item->dimension_label,
                            'offered_spec' => $quotationItem?->available_dimension_label,
                            'spec_accepted' => $isAwarded && $quotationItem && $award->isSpecAcceptanceCurrent($quotationItem->specificationSnapshot()),
                            'purchase_order_url' => $isAwarded && $award?->purchaseOrder
                                ? PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $award->purchaseOrder)
                                : null,
                            'purchase_order_number' => $isAwarded ? $award?->purchaseOrder?->po_number : null,
                            'available_qty' => $quotationItem?->available_qty,
                            'available_dimension_label' => $quotationItem?->available_dimension_label,
                            'offered_weight_per_unit' => $quotationItem?->offered_weight_per_unit,
                            'offered_total_weight' => $quotationItem?->offered_total_weight,
                            'is_estimated_weight' => $quotationItem?->is_estimated_weight ?? false,
                            'availability_comparison' => $quotationItem?->availability_comparison,
                            'currency' => $quotation->currency,
                            'detail_url' => $quotationItem
                                ? PurchasingNavigation::toRoute('purchasing.quotations.show', $quotation)
                                : null,
                        ];
                    }

                    $row['indicators'] = $this->itemIndicators($item, $quotations, $currency, $context);
                    $matrix[] = $row;
                }

                $assignedPurchaseOrderIds = $existingAwards
                    ->whereNotNull('purchase_order_id')
                    ->pluck('purchase_order_id')
                    ->unique()
                    ->values();
                $assignedPurchaseOrderCount = $assignedPurchaseOrderIds->count();
                $assignedPurchaseOrders = $existingAwards
                    ->whereNotNull('purchase_order_id')
                    ->map(fn (PrItemAward $award) => $award->purchaseOrder)
                    ->filter()
                    ->unique('id')
                    ->sortBy('id')
                    ->values();
                $hasActionableAwardSelections = collect($matrix)->contains(
                    fn (array $row) => collect($row['prices'])->contains(
                        fn (array $price) => ! empty($price['is_selectable'])
                    )
                );
                $allItemsAssignedToPurchaseOrder = $comparisonItems->isNotEmpty()
                    && $comparisonItems->every(
                        fn (PrItem $item) => $existingAwards->get($item->id)?->purchase_order_id !== null
                    );

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
                        // Benchmark conversion; no rate or no offer is a gap (null), never 0 (I-8).
                        $converted = $quotationItem && $quotationItem->is_available && $quotationItem->price_per_kg !== null
                            ? app(PurchaseHistoryPriceService::class)->convert((string) $quotationItem->price_per_kg, $quotation->currency, $currency, $context)
                            : null;
                        $data[] = $converted === null ? null : (float) Money::normalize($converted, 4);
                    }

                    $chartDatasets[] = [
                        'label' => self::supplierDisplayName($quotation->supplier),
                        'data' => $data,
                    ];
                }

                $chartData = [
                    'labels' => $chartLabels,
                    'datasets' => $chartDatasets,
                    'currency' => $currency,
                ];
            }
        }

        $viewData = compact(
            'eligiblePrs',
            'comparison',
            'chartData',
            'chartMaterialIds',
            'materialOptions',
            'selectedPr',
            'eligiblePrOptions',
            'selectedPrOption',
            'existingAwards',
            'awardCoverage',
            'supplierGrouping',
            'assignedPurchaseOrders',
            'assignedPurchaseOrderCount',
            'hasActionableAwardSelections',
            'allItemsAssignedToPurchaseOrder',
        ) + [
            'currency' => $currency,
            'evaluation' => $this->evaluationMeta($currency, $context),
        ];

        if ($request->ajax()) {
            return response()->json([
                'html' => view('purchasing.comparison._inter_supplier_content', $viewData)->render(),
                'tab' => 'inter-supplier',
                'url' => route('purchasing.comparison.inter-supplier', $request->query()),
            ]);
        }

        return view('purchasing.comparison.inter-supplier', $viewData);
    }

    /**
     * View 2: historical material pricing for one supplier across periods.
     */
    public function historical(Request $request): View|JsonResponse
    {
        $isDataPayloadRequest = $request->input('view') === 'json';
        // Mission 07 (decisions 15-18, Q9): supplier optional, shape-driven key
        // dimension, weighted average converted at the evaluation date.
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();

        // Cache the supplier dropdown list for 10 minutes when rendering the full page.
        // Data payload requests skip this since they don't render the dropdown.
        $suppliers = $isDataPayloadRequest
            ? collect()
            : Cache::remember(
                'purchasing:historical_suppliers:v2',
                now()->addMinutes(10),
                fn () => User::query()
                    ->select(['id', 'name', 'role'])
                    ->with('supplier:id,user_id,company_name')
                    ->where(function ($query) {
                        $query->importEligible()
                            ->orWhereIn('id', DB::table('quotations')->distinct()->pluck('supplier_id'));
                    })
                    ->orderBy('name')
                    ->get()
            );
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

        $materials = $this->historicalMaterialsForSupplier($selectedSupplierKey);
        $selectedMaterial = $this->selectHistoricalMaterial($materials, $selectedMaterialName, $request->input('shape'));
        if ($selectedMaterial === null) {
            $selectedMaterialName = null;
        }
        $selectedShape = $selectedMaterial['shape'] ?? null;
        $keyField = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$selectedMaterial['shape'] ?? ''] ?? null;
        [$keyMin, $keyMax] = $this->keyDimensionRange($request, $keyField);

        $chartData = null;
        $tableData = collect();
        $tablePagination = null;
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];

        if ($selectedMaterial) {
            [$chartData, $tableData, $tablePagination, $summary] = $this->buildHistoricalData([
                'material_name' => $selectedMaterial['name'],
                'material_master_id' => $selectedMaterial['master_id'],
                'shape' => $selectedMaterial['shape'],
                // PUR-07: a material recorded without a shape is its own choice, not "all shapes".
                'shape_is_null' => $selectedMaterial['shape'] === null,
                'key_min' => $keyMin,
                'key_max' => $keyMax,
                'supplier_id' => $selectedSupplierKey,
                'date_from' => $dateFrom,
            ], $periodView, $currency, $context, max(1, $request->integer('history_page', 1)));
        }

        $evaluation = $this->evaluationMeta($currency, $context);
        $keyDimension = ['field' => $keyField, 'min' => $keyMin, 'max' => $keyMax];

        $payload = [
            'chartData' => $chartData,
            'tableData' => $tableData->values(),
            'summary' => $summary,
            'pagination' => $tablePagination,
            'periodView' => $periodView,
            'range' => $range,
            'rangeOptions' => $rangeOptions,
            'materialName' => $selectedMaterialName,
            'shape' => $selectedShape,
            'keyOptions' => $selectedMaterial['key_options'] ?? [],
            'supplierName' => self::supplierDisplayName($selectedSupplier?->loadMissing('supplier:id,user_id,company_name')) ?? '',
            'currency' => $currency,
            'evaluation' => $evaluation,
            'keyDimension' => $keyDimension,
        ];

        if ($isDataPayloadRequest) {
            return response()->json($payload);
        }

        $materials = $materials->map(fn (array $material) => self::historicalMaterialOption($material))->values();
        $viewData = compact(
            'suppliers',
            'materials',
            'chartData',
            'tableData',
            'summary',
            'tablePagination',
            'selectedSupplierId',
            'selectedMaterialName',
            'selectedShape',
            'periodView',
            'range',
            'rangeOptions',
            'monthlyRangeOptions',
            'yearlyRangeOptions',
            'payload',
            'currency',
            'evaluation',
            'keyDimension',
        );

        if ($request->ajax()) {
            return response()->json([
                'html' => view('purchasing.comparison._historical_content', $viewData)->render(),
                'tab' => 'historical',
                'url' => route('purchasing.comparison.historical', $request->query()),
            ]);
        }

        return view('purchasing.comparison.historical', $viewData);
    }

    public function historicalMaterials(Request $request)
    {
        $supplierValue = $request->query('supplier_id', $request->query('supplier'));
        $supplier = $this->resolveSupplierQuery($supplierValue);

        return response()->json([
            'materials' => $this->historicalMaterialsForSupplier($supplier?->getKey())
                ->map(fn (array $material) => self::historicalMaterialOption($material))
                ->values(),
        ]);
    }

    /**
     * View 3: current price versus the historical MIN(price_per_kg).
     */
    public function vsBestPrice(Request $request): View|JsonResponse
    {
        [$dateFrom, $dateTo] = $this->vsBestDateRange($request);
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();
        $evaluation = $this->evaluationMeta($currency, $context);
        $dateFromInput = $request->input('date_from');
        $dateToInput = $request->input('date_to');
        $competitiveThreshold = 2.0;

        // Pre-populate summary with cached real data to avoid zero-flash on metric cards.
        // Falls back to empty summary if cache is cold (first load or after invalidation).
        $summary = $this->getCachedDefaultVsBestSummary($competitiveThreshold, $currency, $context);

        // PUR-05: material options for the Vs Best filter; an unknown requested value is ignored.
        $vsBestMaterials = $this->vsBestMaterialOptions();
        $requestedMaterial = $this->vsBestMaterialFilter($request);
        $selectedVsBestMaterial = $requestedMaterial === null ? null : $vsBestMaterials->first(
            fn (array $option) => $option['name'] === $requestedMaterial['name'] && $option['shape'] === $requestedMaterial['shape']
        );
        $vsBestMaterialIgnored = $requestedMaterial !== null && $selectedVsBestMaterial === null;

        $viewData = compact(
            'dateFromInput',
            'dateToInput',
            'summary',
            'competitiveThreshold',
            'currency',
            'evaluation',
            'vsBestMaterials',
            'selectedVsBestMaterial',
            'vsBestMaterialIgnored',
        );

        if ($request->ajax()) {
            return response()->json([
                'html' => view('purchasing.comparison._vs_best_content', $viewData)->render(),
                'tab' => 'vs-best',
                'url' => route('purchasing.comparison.vs-best', $request->query()),
            ]);
        }

        return view('purchasing.comparison.vs-best', $viewData);
    }

    public function vsBestPriceData(Request $request)
    {
        [$dateFrom, $dateTo] = $this->vsBestDateRange($request);
        $competitiveThreshold = 2.0;
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();

        $keyword = trim((string) $request->input('search.value', ''));
        $material = $this->vsBestMaterialFilter($request);
        $returnUrl = route('purchasing.comparison.vs-best', $request->only([
            'date_from',
            'date_to',
            'currency',
            'material_name',
            'shape',
        ]));

        // Cache unfiltered default summary (no date range, no keyword) for 5 minutes.
        // This eliminates the ~183ms aggregate subquery on every default page load.
        $isUnfilteredDefault = $dateFrom === null && $dateTo === null && $keyword === '' && $material === null;

        if ($isUnfilteredDefault) {
            $summary = Cache::remember(
                $this->vsBestSummaryCacheKey($currency, $context),
                now()->addMinutes(5),
                function () use ($competitiveThreshold, $currency, $context) {
                    $summaryQuery = $this->buildVsBestQuery(null, null, $currency, $context);

                    return $this->buildVsBestSummary($summaryQuery, $competitiveThreshold);
                }
            );
        } else {
            $summaryQuery = $this->applyVsBestKeywordFilter(
                $this->buildVsBestQuery($dateFrom, $dateTo, $currency, $context, $material),
                $keyword
            );
            $summary = $this->buildVsBestSummary($summaryQuery, $competitiveThreshold);
        }

        $dataTable = DataTables::query($this->buildVsBestQuery($dateFrom, $dateTo, $currency, $context, $material))
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
                    HashidCodec::encode((int) $row->current_pr_id),
                    $returnUrl
                );

                return '<div class="fw-bold">'.e($row->material_name).'</div>'
                    .'<div class="text-muted small">Qty: '.$this->regionalFormatter->number(number_format((int) ($row->quantity ?? 1), 0, ',', '.'), 'indonesian').'</div>'
                    .'<div class="text-muted small">Berat/unit: '.$this->formatNumber($row->weight_needed).' kg</div>'
                    .'<div class="text-muted small">Total weight: '.$this->formatNumber($row->total_weight).' kg</div>'
                    .'<a href="'.e($prUrl).'" class="small text-primary text-decoration-none">'
                    .e($row->current_pr_number ?: '-')
                    .'</a>';
            })
            ->addColumn('current_price_display', function ($row) use ($currency) {
                return '<div class="fw-bold text-primary">'.$this->formatAnalyticAmount($row->current_price_idr, $currency).'</div>'
                    .'<div class="text-muted small">'.$this->formatPrice($row->current_price, 4).' '.e($row->current_currency).'/kg</div>'
                    .'<div class="text-muted small">'.e($row->current_supplier ?: '-').'</div>'
                    .'<div class="text-muted small">'.e($this->formatDate($row->current_submitted_at) ?? __('purchasing.copy.draft')).'</div>';
            })
            ->addColumn('best_price_display', function ($row) use ($returnUrl, $currency) {
                $html = '<div class="fw-bold text-success">'.$this->formatAnalyticAmount($row->best_price_idr, $currency).'</div>'
                    .'<div class="text-muted small">'.$this->formatPrice($row->best_price, 4).' '.e($row->best_currency).'/kg</div>'
                    .'<div class="text-muted small">'.e($row->best_supplier ?: '-').'</div>';

                if ($row->best_pr_id) {
                    $bestPrUrl = $this->routeWithReturn(
                        'purchasing.requisitions.show',
                        HashidCodec::encode((int) $row->best_pr_id),
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
            ->addColumn('diff_display', function ($row) use ($currency) {
                $diff = $row->diff_idr_per_kg !== null ? (float) $row->diff_idr_per_kg : null;
                $class = $diff > 0 ? 'tw:text-error' : ($diff < 0 ? 'tw:text-success' : 'tw:text-on-surface-variant');

                return '<div class="fw-bold '.$class.'">'.$this->formatAnalyticAmount($diff, $currency, true).'</div>'
                    .'<div class="small '.$class.'">'.$this->formatPercent($row->diff_percent).'</div>';
            })
            ->addColumn('potential_difference_display', fn ($row) => '<span class="fw-bold">'.$this->formatAnalyticAmount($row->potential_difference_idr, $currency).'</span>')
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
                    HashidCodec::encode((int) $row->current_quotation_id),
                    $returnUrl
                );
                $html = '<div class="d-inline-flex align-items-center gap-1" role="group" aria-label="'.e(__('purchasing.copy.quotation_comparison_actions')).'">'
                    .'<a href="'.e($currentQuotationUrl).'" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="'.e(__('purchasing.copy.view_current_quotation')).'">'.e(__('purchasing.copy.current')).'</a>';

                if ($row->best_quotation_id) {
                    $bestQuotationUrl = $this->routeWithReturn(
                        'purchasing.quotations.show',
                        HashidCodec::encode((int) $row->best_quotation_id),
                        $returnUrl
                    );
                    $html .= '<a href="'.e($bestQuotationUrl).'" class="ui-data-action ui-data-action--success ui-focus-ring" aria-label="'.e(__('purchasing.copy.view_historical_best_quotation')).'">'.e(__('purchasing.copy.best')).'</a>';
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
            ->with('currency', $currency)
            ->make(true);
    }

    private function buildVsBestQuery(?Carbon $dateFrom = null, ?Carbon $dateTo = null, string $currency = 'IDR', ?EvaluationContext $context = null, ?array $material = null)
    {
        $currentStatuses = ['submitted', 'accepted'];
        // Mission 07 (decision 35, Q4): benchmark rates at the evaluation date in the
        // selected currency. A currency without a rate yields NULL and its rows never
        // rank as "best" (no COALESCE(rate, 1)). Columns keep their *_idr names.
        $context ??= EvaluationContext::current();
        // Gate 2 §3-C: "best" is ranked over authoritative purchase history only (the
        // PurchaseHistoryPriceService eligibility: no cancelled/deleted PO or quotation,
        // award line or legacy PO without awards, frozen price), per material and shape.
        $historyPrice = '('.PurchaseHistoryPriceService::PRICE.')';
        $historyPriceIdr = '('.$historyPrice.' * '.$this->benchmarkFactorSql('q.currency', $currency, $context).')';
        $currentPriceIdr = '(current_items.price_per_kg * '.$this->benchmarkFactorSql('current_quotes.currency', $currency, $context).')';
        $bestPriceIdr = '(best_choice.best_price * '.$this->benchmarkFactorSql('best_quotes.currency', $currency, $context).')';
        $diffIdrPerKg = "($currentPriceIdr - $bestPriceIdr)";
        $diffPercent = "CASE WHEN $bestPriceIdr > 0 AND $currentPriceIdr IS NOT NULL THEN (($diffIdrPerKg / $bestPriceIdr) * 100) ELSE NULL END";
        $currentTotalWeight = '(current_pr_items.weight_needed * CASE WHEN current_pr_items.quantity IS NULL OR current_pr_items.quantity < 1 THEN 1 ELSE current_pr_items.quantity END)';
        $potentialDifference = "CASE WHEN $currentPriceIdr IS NOT NULL AND $bestPriceIdr IS NOT NULL THEN GREATEST(0, $diffIdrPerKg) * $currentTotalWeight ELSE NULL END";

        $rankedHistory = app(PurchaseHistoryPriceService::class)->eligibleLines([])
            ->whereRaw($historyPriceIdr.' IS NOT NULL')
            ->selectRaw("pi.material_name, COALESCE(pi.shape, '') as shape_key, qi.id as best_item_id, po.id as best_po_id, {$historyPrice} as best_price")
            ->selectRaw("ROW_NUMBER() OVER (PARTITION BY pi.material_name, COALESCE(pi.shape, '') ORDER BY {$historyPriceIdr} ASC, qi.id ASC, po.id ASC) as rn");

        $bestItemByMaterial = DB::query()
            ->fromSub($rankedHistory, 'ranked_best')
            ->where('rn', 1)
            ->select(['material_name', 'shape_key', 'best_item_id', 'best_po_id', 'best_price']);

        $query = DB::table('quotation_items as current_items')
            ->join('quotations as current_quotes', 'current_items.quotation_id', '=', 'current_quotes.id')
            ->join('pr_items as current_pr_items', 'current_items.pr_item_id', '=', 'current_pr_items.id')
            ->join('purchase_requisitions as current_pr', 'current_pr_items.pr_id', '=', 'current_pr.id')
            ->leftJoin('users as current_supplier', 'current_quotes.supplier_id', '=', 'current_supplier.id')
            ->leftJoin('suppliers as current_supplier_profile', 'current_supplier_profile.user_id', '=', 'current_supplier.id')
            ->leftJoin('exchange_rates as current_rate', 'current_quotes.exchange_rate_id', '=', 'current_rate.id')
            ->leftJoinSub($bestItemByMaterial, 'best_choice', function ($join) {
                $join->on('best_choice.material_name', '=', 'current_pr_items.material_name')
                    ->whereRaw("best_choice.shape_key = COALESCE(current_pr_items.shape, '')");
            })
            ->leftJoin('quotation_items as best_items', 'best_choice.best_item_id', '=', 'best_items.id')
            ->leftJoin('quotations as best_quotes', 'best_items.quotation_id', '=', 'best_quotes.id')
            ->leftJoin('users as best_supplier', 'best_quotes.supplier_id', '=', 'best_supplier.id')
            ->leftJoin('suppliers as best_supplier_profile', 'best_supplier_profile.user_id', '=', 'best_supplier.id')
            // The ranked line already identifies its PO; joining every PO of the quotation
            // would duplicate rows when one quotation feeds several POs.
            ->leftJoin('purchase_orders as best_pos', 'best_choice.best_po_id', '=', 'best_pos.id')
            ->leftJoin('pr_items as best_pr_items', 'best_items.pr_item_id', '=', 'best_pr_items.id')
            ->leftJoin('purchase_requisitions as best_pr', function ($join) {
                $join->on('best_pr_items.pr_id', '=', 'best_pr.id')
                    ->whereNull('best_pr.deleted_at');
            })
            ->whereIn('current_quotes.status', $currentStatuses)
            ->whereNull('current_quotes.deleted_at')
            ->whereNull('current_pr.deleted_at')
            ->where(function ($query) {
                $query->whereNull('current_items.is_available')
                    ->orWhere('current_items.is_available', true);
            })
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
                'best_items.id as best_item_id',
                'best_items.quotation_id as best_quotation_id',
                'best_choice.best_price as best_price',
                'best_quotes.currency as best_currency',
                'best_pos.created_at as best_submitted_at',
                'best_pr.id as best_pr_id',
                'best_pr.pr_number as best_pr_number',
            ])
            ->selectRaw(self::supplierNameSql('current_supplier', 'current_supplier_profile').' as current_supplier')
            ->selectRaw(self::supplierNameSql('best_supplier', 'best_supplier_profile').' as best_supplier')
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
        // PUR-05: optional material filter by (material, shape); a null shape is its own identity.
        if ($material !== null) {
            $query->where('current_pr_items.material_name', $material['name']);
            $material['shape'] === null
                ? $query->whereNull('current_pr_items.shape')
                : $query->where('current_pr_items.shape', $material['shape']);
        }

        return $query->orderByRaw('potential_difference_idr DESC')
            ->orderBy('current_pr_items.material_name');
    }

    /**
     * Distinct (material, shape) of the current offers that Vs Best lists, one query.
     *
     * @return Collection<int, array{name: string, shape: ?string, label: string}>
     */
    private function vsBestMaterialOptions(): Collection
    {
        $rows = DB::table('quotation_items as current_items')
            ->join('quotations as current_quotes', 'current_items.quotation_id', '=', 'current_quotes.id')
            ->join('pr_items as current_pr_items', 'current_items.pr_item_id', '=', 'current_pr_items.id')
            ->join('purchase_requisitions as current_pr', 'current_pr_items.pr_id', '=', 'current_pr.id')
            ->whereIn('current_quotes.status', ['submitted', 'accepted'])
            ->whereNull('current_quotes.deleted_at')
            ->whereNull('current_pr.deleted_at')
            ->where(fn ($query) => $query->whereNull('current_items.is_available')->orWhere('current_items.is_available', true))
            ->whereNotNull('current_pr_items.material_name')
            ->where('current_pr_items.material_name', '<>', '')
            ->distinct()
            ->orderBy('current_pr_items.material_name')
            ->orderBy('current_pr_items.shape')
            ->get(['current_pr_items.material_name', 'current_pr_items.shape']);
        $nameCounts = $rows->countBy('material_name');

        return $rows->map(fn ($row) => [
            'name' => $row->material_name,
            'shape' => $row->shape,
            'label' => $nameCounts[$row->material_name] > 1 && $row->shape !== null ? $row->material_name.' ('.$row->shape.')' : $row->material_name,
        ])->values();
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
                ->orWhereRaw(self::supplierNameSql('current_supplier', 'current_supplier_profile').' like ?', [$like])
                ->orWhereRaw(self::supplierNameSql('best_supplier', 'best_supplier_profile').' like ?', [$like])
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

    /**
     * Retrieve cached default (unfiltered) vs-best summary for pre-populating metric cards.
     * Falls back to empty summary if cache is cold.
     */
    private function getCachedDefaultVsBestSummary(float $competitiveThreshold, string $currency, EvaluationContext $context): array
    {
        return Cache::get($this->vsBestSummaryCacheKey($currency, $context), $this->emptyVsBestSummary());
    }

    /** The summary depends on the evaluation date and currency (Q9), so both are part of the key. */
    private function vsBestSummaryCacheKey(string $currency, EvaluationContext $context): string
    {
        return 'purchasing:vs_best_summary_unfiltered:v3:'.$context->dateString().':'.$currency;
    }

    /**
     * PUR-05: optional (material, shape) filter. Material identity is the pair, so an empty or
     * unknown shape selects the material recorded without a shape.
     *
     * @return array{name: string, shape: ?string}|null
     */
    private function vsBestMaterialFilter(Request $request): ?array
    {
        $name = trim((string) $request->input('material_name', ''));
        if ($name === '') {
            return null;
        }
        $shape = trim((string) $request->input('shape', ''));

        return ['name' => $name, 'shape' => in_array($shape, PrItem::SHAPES, true) ? $shape : null];
    }

    private function priceCompetitivenessStatus(?float $diffPercent, float $competitiveThreshold): array
    {
        if ($diffPercent === null) {
            return [
                'label' => __('status.meta.not_applicable'),
                'class' => 'bg-secondary',
                'icon' => 'circle-minus',
                'recommendation' => __('status.meta.safe'),
            ];
        }

        if ($diffPercent <= 0) {
            return [
                'label' => __('purchasing.copy.best_price'),
                'class' => 'bg-success',
                'icon' => 'check-circle',
                'recommendation' => __('status.meta.safe'),
            ];
        }

        if ($diffPercent <= $competitiveThreshold) {
            return [
                'label' => __('purchasing.copy.competitive'),
                'class' => 'bg-primary',
                'icon' => 'shield-check',
                'recommendation' => __('status.meta.safe'),
            ];
        }

        return [
            'label' => __('purchasing.copy.above_history'),
            'class' => 'bg-warning text-dark',
            'icon' => 'info',
            'recommendation' => __('purchasing.copy.safe_check_context'),
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

    /** PUR-03: company name with the login name as fallback (supplier?->company_name ?: name). */
    private static function supplierDisplayName(?User $user): ?string
    {
        $company = trim((string) $user?->supplier?->company_name);

        return $company !== '' ? $company : $user?->name;
    }

    /** SQL twin of supplierDisplayName() for a users alias and its suppliers alias. */
    private static function supplierNameSql(string $users, string $profiles): string
    {
        return "COALESCE(NULLIF(TRIM({$profiles}.company_name), ''), {$users}.name)";
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

    /**
     * Materials with eligible PO history (decision 15: supplier optional).
     *
     * @return Collection<int, array{name: string, shape: ?string, master_id: ?int}>
     */
    private function historicalMaterialsForSupplier($supplierId): Collection
    {
        // PUR-07 (§3-G): one grouped query returns every (material, shape) with eligible
        // history and the distinct key-dimension values used by the history filter itself
        // (offered value, else the requested one). Shapes come from the code constant only.
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

        // The same name with several shapes is labelled "Name (Shape)" so each choice is identifiable.
        $nameCounts = $materials->countBy('name');

        return $materials->map(fn (array $material) => $material + [
            'label' => $nameCounts[$material['name']] > 1 && $material['shape'] !== null
                ? $material['name'].' ('.$material['shape'].')'
                : $material['name'],
        ]);
    }

    /** The material picked by (name, shape); an unknown shape falls back to the first of that name. */
    private function selectHistoricalMaterial(Collection $materials, ?string $name, ?string $shape): ?array
    {
        if ($name === null || $name === '') {
            return null;
        }
        $candidates = $materials->where('name', $name);

        return $candidates->first(fn (array $material) => (string) $material['shape'] === (string) $shape) ?? $candidates->first();
    }

    /** View/JSON shape of a material option (master ids stay server-side). */
    private static function historicalMaterialOption(array $material): array
    {
        return [
            'name' => $material['name'],
            'shape' => $material['shape'],
            'label' => $material['label'],
            'key_options' => $material['key_options'],
        ];
    }

    /**
     * Shape-driven key dimension (decisions 17-18): exact when one bound is
     * given, inclusive range when both are. Legacy single-field parameters
     * (thickness, d_outer) are accepted for the key field only.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function keyDimensionRange(Request $request, ?string $field): array
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

    /** Analysis currency (decision 20): IDR, USD, JPY or CNY; IDR by default. */
    private function analysisCurrency(Request $request): string
    {
        $currency = strtoupper(trim((string) $request->input('currency', 'IDR')));

        return in_array($currency, ExchangeRate::CURRENCIES, true) ? $currency : ExchangeRate::CURRENCY_IDR;
    }

    /**
     * Evaluation date (Q9) and the benchmark rate effective on it for every
     * supported currency (memoized; at most four queries per request).
     */
    private function evaluationMeta(string $currency, EvaluationContext $context): array
    {
        $resolver = app(ExchangeRateResolver::class);
        $rates = [];
        foreach (ExchangeRate::CURRENCIES as $code) {
            $rate = $resolver->rateAsOf($code, $context->dateString());
            $rates[$code] = [
                'available' => $rate !== null,
                'rate' => $rate?->rate,
                'valid_from' => $rate?->validFrom,
                'valid_from_display' => $rate?->validFrom ? $this->regionalFormatter->date(Carbon::parse($rate->validFrom), 'human') : null,
            ];
        }

        return [
            'date' => $context->dateString(),
            'date_display' => $this->regionalFormatter->date(Carbon::parse($context->dateString()), 'human'),
            'timezone' => config('app.business_timezone', 'Asia/Jakarta'),
            'currency' => $currency,
            'currencies' => ExchangeRate::CURRENCIES,
            'rates' => $rates,
            'unavailable' => collect($rates)->reject(fn (array $rate) => $rate['available'])->keys()->values()->all(),
        ];
    }

    /**
     * SQL factor converting an amount in the row's currency into $target at the
     * evaluation date: rate(row) / rate(target), NULL when either is missing.
     * Rates are decimal literals from exchange_rates normalized to [0-9.] only.
     */
    private function benchmarkFactorSql(string $currencyColumn, string $target, EvaluationContext $context): string
    {
        $resolver = app(ExchangeRateResolver::class);
        $targetRate = $resolver->rateAsOf($target, $context->dateString())?->rate;
        $literal = static function (?string $value): string {
            $normalized = Money::normalize((string) $value, 8);
            if (! preg_match('/^\d+(\.\d+)?$/', $normalized)) {
                throw new \LogicException('Invalid benchmark rate literal.');
            }

            return $normalized;
        };
        $cases = [];
        foreach (ExchangeRate::CURRENCIES as $code) {
            // Same currency is the identity; otherwise both rates are required.
            $rate = $resolver->rateAsOf($code, $context->dateString())?->rate;
            $cases[] = "WHEN '{$code}' THEN ".match (true) {
                $code === $target => '1',
                $rate === null || $targetRate === null => 'NULL',
                default => '('.$literal($rate).' / '.$literal($targetRate).')',
            };
        }

        return "(CASE {$currencyColumn} ".implode(' ', $cases).' ELSE NULL END)';
    }

    /**
     * Mission 07 indicator strip for one PR item (decisions 19, 20, 29): Best
     * Current Offer, Historical Best / Weighted Average (PO history, same
     * material, shape and key dimension) and the Reference Price, all in the
     * analysis currency at the evaluation date. Originals stay visible.
     */
    private function itemIndicators(PrItem $item, Collection $quotations, string $currency, EvaluationContext $context): array
    {
        $history = app(PurchaseHistoryPriceService::class);
        $best = null;
        $unavailable = [];

        foreach ($quotations as $quotation) {
            if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                continue;
            }
            $line = $quotation->items->firstWhere('pr_item_id', $item->id);
            if (! $line || ! $line->is_available || $line->price_per_kg === null || (float) $line->price_per_kg <= 0) {
                continue;
            }
            $converted = $history->convert((string) $line->price_per_kg, $quotation->currency, $currency, $context);
            if ($converted === null) {
                $unavailable[] = $quotation->currency;

                continue;
            }
            if ($best === null || bccomp($converted, $best['raw'], 16) < 0) {
                $best = [
                    'raw' => $converted,
                    'line' => $line,
                    'converted' => Money::normalize($converted, 4),
                    'original' => Money::normalize((string) $line->price_per_kg, 4),
                    'original_currency' => $quotation->currency,
                    'supplier' => self::supplierDisplayName($quotation->supplier),
                ];
            }
        }

        $field = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$item->shape] ?? null;
        $key = $field !== null && $item->{$field} !== null ? Money::normalize((string) $item->{$field}, 4) : null;
        $summary = $history->summarize([
            'material_master_id' => $item->material_master_id,
            'material_name' => $item->material_name,
            'shape' => $item->shape,
            'key_min' => $key,
            'key_max' => $key,
        ], $currency, $context)['total'];

        $reference = $best ? app(ReferencePriceResolver::class)->resolve($best['line'], $context, $currency) : null;
        if ($best !== null) {
            unset($best['raw'], $best['line']);
        }

        return [
            'best_current' => $best,
            'history' => $summary,
            'reference' => $reference === null ? null : [
                'matched' => $reference->isMatched(),
                'converted' => $reference->convertedReferencePricePerKg,
                'original' => $reference->reference === null ? null : Money::normalize((string) $reference->reference->price_per_kg, 4),
                'original_currency' => $reference->reference?->currency,
                'reason' => $reference->reasonCode,
            ],
            'unavailable_currencies' => array_values(array_unique([...$unavailable, ...$summary['excluded']['currencies']])),
        ];
    }

    private function formatAnalyticAmount($value, string $currency, bool $signed = false): string
    {
        if ($currency === ExchangeRate::CURRENCY_IDR) {
            return $signed ? $this->formatSignedRupiah($value) : $this->formatRupiah($value);
        }
        if ($value === null) {
            return '-';
        }
        $value = (float) $value;
        $prefix = $signed ? ($value > 0 ? '+' : ($value < 0 ? '-' : '')) : '';

        return $prefix.$currency.' '.$this->formatPrice($signed ? abs($value) : $value, 4);
    }

    /** PUR-06: grouped price in the module's native (Indonesian) presentation, like formatRupiah(). */
    private function formatPrice($value, int $decimals = 2): string
    {
        return $value === null ? '-' : $this->regionalFormatter->groupedDecimal(is_float($value) ? $value : (string) $value, $decimals, 'indonesian');
    }

    private function formatRupiah($value): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = number_format((float) $value, 0, ',', '.');

        return 'Rp '.$this->regionalFormatter->number($formatted, 'indonesian');
    }

    private function formatSignedRupiah($value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = (float) $value;
        $prefix = $value > 0 ? '+' : ($value < 0 ? '-' : '');
        $formatted = number_format(abs($value), 0, ',', '.');

        return $prefix.'Rp '.$this->regionalFormatter->number($formatted, 'indonesian');
    }

    private function formatNumber($value, int $decimals = 2): string
    {
        if ($value === null) {
            return '-';
        }

        $formatted = NumberFormat::maxDecimals($value, $decimals);

        return $this->regionalFormatter->number($formatted, 'international');
    }

    private function formatPercent($value): string
    {
        if ($value === null) {
            return '-';
        }

        $value = (float) $value;
        $prefix = $value > 0 ? '+' : '';
        $formatted = NumberFormat::maxDecimals($value);

        return $prefix.$this->regionalFormatter->number($formatted, 'international').'%';
    }

    private function formatDate($value): ?string
    {
        if (! $value) {
            return null;
        }

        $carbon = $value instanceof \DateTimeInterface
            ? $value
            : \Illuminate\Support\Carbon::parse($value);

        return $this->regionalFormatter->timestamp($carbon, 'date');
    }

    /**
     * Mission 07: chart and table from PurchaseHistoryPriceService. Payload keys
     * keep their historical names (pricesIdr, price_idr, min_idr, max_idr,
     * total_idr) but now carry values in the selected analysis currency.
     *
     * @return array{0: ?array, 1: Collection, 2: ?array, 3: array}
     */
    private function buildHistoricalData(array $filter, string $periodView, string $currency, EvaluationContext $context, int $page): array
    {
        $service = app(PurchaseHistoryPriceService::class);
        $history = $service->summarize($filter, $currency, $context, $periodView === 'yearly' ? 'year' : 'month');
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
        $lines = $service->lines($filter, $currency, $context, $page, self::HISTORICAL_TABLE_PER_PAGE);

        $tableData = collect($lines['rows'])->map(function (array $line) {
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

    private function historicalPeriodLabel($name, $month, $year): string
    {
        if ($year === null) {
            return __('purchasing.copy.unknown_period');
        }

        return $month === null
            ? (string) $year
            : $name.' ('.str_pad((string) $month, 2, '0', STR_PAD_LEFT).'/'.$year.')';
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

    /**
     * Save item-level awards and optionally generate PO(s).
     */
    public function saveItemAwards(
        Request $request,
        PrItemAwardService $awardService,
        PurchaseOrderGenerationService $poService
    ) {
        $request->validate([
            'pr_id' => 'required',
            'awards' => 'required|array|min:1',
            'awards.*' => 'required|integer|distinct|exists:quotation_items,id',
            'action' => 'nullable|string|in:save,generate_pos',
            'notes' => 'nullable|string',
            'estimated_arrival' => 'nullable|date',
            // M04-W: values are validated per line by PrItemAwardService.
            'order_qty' => 'nullable|array',
            'accept_spec' => 'nullable|array',
        ]);

        $pr = $this->resolveHashedQueryModel(PurchaseRequisition::class, $request->input('pr_id'));
        $orderSelections = OrderSelectionInput::fromRequest($request);

        // Filter and sanitize award selections: [pr_item_id => quotation_item_id]
        $selections = [];
        foreach ($request->input('awards', []) as $prItemId => $quotationItemId) {
            if ($prItemId && $quotationItemId) {
                $selections[(int) $prItemId] = (int) $quotationItemId;
            }
        }

        if (empty($selections)) {
            return back()->with('error', __('purchasing.copy.please_select_at_least_one_item_offer'));
        }

        try {
            if ($request->input('action') === 'generate_pos') {
                $pos = DB::transaction(function () use ($pr, $selections, $orderSelections, $awardService, $poService, $request) {
                    $lockedPr = PurchaseRequisition::whereKey($pr->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    $prItemIds = array_keys($selections);
                    sort($prItemIds);
                    $lockedPrItems = PrItem::query()
                        ->where('pr_id', $lockedPr->id)
                        ->whereIn('id', $prItemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($lockedPrItems->count() !== count($prItemIds)) {
                        throw new \InvalidArgumentException(__('purchasing.copy.one_or_more_selected_pr_items_do_not_belong_to_this_requisition'));
                    }

                    $quotationItemIds = array_values($selections);
                    sort($quotationItemIds);
                    $quotationIds = QuotationItem::query()
                        ->whereIn('id', $quotationItemIds)
                        ->pluck('quotation_id')
                        ->unique()
                        ->sort()
                        ->values()
                        ->all();

                    $lockedQuotations = Quotation::query()
                        ->whereIn('id', $quotationIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($lockedQuotations->count() !== count($quotationIds)) {
                        throw new \InvalidArgumentException(__('purchasing.copy.one_or_more_selected_quotations_could_not_be_found'));
                    }

                    foreach ($lockedQuotations as $quotation) {
                        if (! in_array($quotation->status, Quotation::AWARD_ELIGIBLE_STATUSES, true)) {
                            throw new \InvalidArgumentException(
                                __('purchasing.comparison.ineligible_status', ['quotation' => $quotation->id, 'status' => $quotation->status])
                            );
                        }
                    }

                    $lockedQuotationItems = QuotationItem::query()
                        ->whereIn('id', $quotationItemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    if ($lockedQuotationItems->count() !== count($quotationItemIds)) {
                        throw new \InvalidArgumentException(__('purchasing.copy.one_or_more_selected_quotation_items_could_not_be_found'));
                    }

                    PrItemAward::query()
                        ->whereIn('pr_item_id', $prItemIds)
                        ->orderBy('id')
                        ->lockForUpdate()
                        ->get();

                    $awards = $awardService->awardBatch($lockedPr, $selections, auth()->user(), $orderSelections);

                    return $poService->generateFromAwards($awards, auth()->user(), [
                        'estimated_arrival' => $request->input('estimated_arrival'),
                        'notes' => $request->input('notes'),
                    ]);
                });

                if ($pos->count() === 1) {
                    return redirect()->route('purchasing.purchase-orders.show', $pos->first())
                        ->with('success', __('purchasing.feedback.selected_po', ['po' => $pos->first()->po_number]));
                }

                $poNumbers = $pos->pluck('po_number')->implode(', ');

                return redirect()->route('purchasing.purchase-orders.index')
                    ->with('success', __('purchasing.feedback.selected_pos', ['count' => $pos->count(), 'pos' => $poNumbers]));
            }

            $awardService->awardBatch($pr, $selections, auth()->user(), $orderSelections);

            return back()->with('success', __('purchasing.copy.selected_item_offers_saved_successfully'));
        } catch (ValidationException $e) {
            // Line-keyed order quantity / acceptance errors (M04-W).
            throw $e;
        } catch (\InvalidArgumentException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        } catch (UniqueConstraintViolationException $e) {
            report($e);

            return back()->withInput()->with(
                'error',
                __('purchasing.copy.the_item_selection_or_purchase_order_state_changed_while_it_was_being_finalized_please_refresh_and_t')
            );
        } catch (\Throwable $e) {
            report($e);

            return back()->withInput()->with('error', __('purchasing.copy.failed_to_finalize_item_selections_and_purchase_orders'));
        }
    }
}
