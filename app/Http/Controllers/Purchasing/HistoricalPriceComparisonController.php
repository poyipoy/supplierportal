<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use App\Models\PurchaseRequisition;
use App\Models\User;
use App\Services\Pricing\HistoricalPriceQueryService;
use App\Services\Pricing\QuotationHistoryService;
use App\Services\RegionalDisplayFormatter;
use App\Support\HashidCodec;
use App\Support\Money;
use App\Support\Pricing\EvaluationContext;
use App\Support\Procurement\LogisticsTerms;
use App\Support\PurchasingNavigation;
use App\Support\StatusHelper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class HistoricalPriceComparisonController extends Controller
{
    use PriceComparisonSharedTrait;

    public const HISTORICAL_CHART_MODES = ['current_previous', 'quote_trend', 'po_history'];

    public function __construct(
        private ?HistoricalPriceQueryService $queryService = null,
        ?RegionalDisplayFormatter $regionalFormatter = null
    ) {
        $this->queryService ??= app(HistoricalPriceQueryService::class);
        $this->regionalFormatter = $regionalFormatter ?? app(RegionalDisplayFormatter::class);
    }

    /**
     * View 2: historical material pricing for one supplier across periods.
     */
    public function historical(Request $request): View|JsonResponse|RedirectResponse
    {
        if ($request->boolean('clear_pr')) {
            PurchasingNavigation::forgetComparisonPrContext();

            return redirect()->route('purchasing.comparison.historical', array_filter(['currency' => $request->query('currency')]));
        }

        if ($request->filled('pr_id')) {
            return $this->historicalForPr($request);
        }

        $isDataPayloadRequest = $request->input('view') === 'json';
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();

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
        $range = $this->queryService->normalizeHistoricalRange($periodView, $request->input('range'));
        $monthlyRangeOptions = $this->queryService->historicalRangeOptions('monthly');
        $yearlyRangeOptions = $this->queryService->historicalRangeOptions('yearly');
        $rangeOptions = $this->queryService->historicalRangeOptions($periodView);
        $dateFrom = $this->queryService->dateFromRange($range);

        $materials = $this->queryService->historicalMaterialsForSupplier($selectedSupplierKey);
        $selectedMaterial = $this->queryService->selectHistoricalMaterial($materials, $selectedMaterialName, $request->input('shape'));
        if ($selectedMaterial === null) {
            $selectedMaterialName = null;
        }
        $selectedShape = $selectedMaterial['shape'] ?? null;
        $keyField = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$selectedMaterial['shape'] ?? ''] ?? null;
        [$keyMin, $keyMax] = $this->queryService->keyDimensionRange($request, $keyField);

        $chartData = null;
        $tableData = collect();
        $tablePagination = null;
        $summary = [
            'average_change_pct' => null,
            'total_change_pct' => null,
        ];

        if ($selectedMaterial) {
            [$chartData, $tableData, $tablePagination, $summary] = $this->queryService->buildHistoricalData([
                'material_name' => $selectedMaterial['name'],
                'material_master_id' => $selectedMaterial['master_id'],
                'shape' => $selectedMaterial['shape'],
                'shape_is_null' => $selectedMaterial['shape'] === null,
                'key_min' => $keyMin,
                'key_max' => $keyMax,
                'supplier_id' => $selectedSupplierKey,
                'date_from' => $dateFrom,
            ], $periodView, $currency, $context, max(1, $request->integer('history_page', 1)));
        }

        $evaluation = $this->evaluationMeta($currency, $context, true);
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

        $materials = $materials->map(fn (array $material) => HistoricalPriceQueryService::historicalMaterialOption($material))->values();
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
            'materials' => $this->queryService->historicalMaterialsForSupplier($supplier?->getKey())
                ->map(fn (array $material) => HistoricalPriceQueryService::historicalMaterialOption($material))
                ->values(),
        ]);
    }

    public function historicalForPr(Request $request): View|JsonResponse
    {
        $pr = $this->resolveHashedQueryModel(PurchaseRequisition::class, $request->query('pr_id'));
        $currency = $this->analysisCurrency($request);
        $context = EvaluationContext::current();
        $service = app(QuotationHistoryService::class);
        $history = $service->forPr($pr, null, $currency);
        $items = $history['items'];
        $notices = [];

        $selectedSupplier = $this->resolveSupplierQuery($request->query('supplier_id'));
        if ($selectedSupplier !== null && ! in_array($selectedSupplier->id, array_column($history['suppliers'], 'id'), true)) {
            $notices[] = __('purchasing.quotation_history.notice_supplier_ignored');
            $selectedSupplier = null;
        }
        if ($selectedSupplier !== null) {
            $history['rows'] = array_values(array_filter($history['rows'], fn (array $row) => $row['supplier']['id'] === $selectedSupplier->id));
        }

        $chartItem = null;
        if ($request->filled('pr_item')) {
            $itemId = HashidCodec::decode((string) $request->query('pr_item'));
            $chartItem = $itemId === null ? null : $items->firstWhere('id', $itemId);
            abort_unless($chartItem instanceof PrItem, 404);
        }
        $focusName = trim((string) $request->query('material_name', ''));
        if ($chartItem === null && $focusName !== '') {
            $focusShape = trim((string) $request->query('shape', ''));
            $chartItem = $items->first(fn (PrItem $item) => mb_strtolower((string) $item->material_name) === mb_strtolower($focusName)
                && mb_strtolower((string) $item->shape) === mb_strtolower($focusShape));
            if ($chartItem === null) {
                $notices[] = __('purchasing.quotation_history.notice_material_not_in_pr');
            }
        }
        $chartItem ??= $items->first();
        $chartMode = in_array($request->query('chart'), self::HISTORICAL_CHART_MODES, true) ? $request->query('chart') : 'current_previous';
        $returnUrl = route('purchasing.comparison.historical', $request->except(['view', 'history', '_', PurchasingNavigation::RETURN_URL_KEY]));

        if ($request->input('view') === 'json' && $request->boolean('history')) {
            return $this->historicalDrawerResponse($request, $service, $history, $items, $returnUrl);
        }

        $charts = $chartItem === null ? null : $this->presentHistoricalCharts($service->charts($history, $chartItem));
        $poHistory = $chartItem === null ? null : $this->prItemPurchaseHistory($chartItem, $currency, $context);
        $rows = array_map(fn (array $row) => $this->presentHistoryRow($row, $returnUrl), $history['rows']);
        $quotationHistory = [
            'pr' => ['hash' => $pr->getRouteKey(), 'number' => $pr->pr_number, 'incoterm' => $pr->incoterm, 'port_of_discharge' => $pr->port_of_discharge],
            'chart_item' => $chartItem === null ? null : $this->presentHistoryItem($chartItem),
            'chart' => $chartMode,
            'rows' => $rows,
            'charts' => $charts,
            'notices' => $notices,
        ];
        $evaluation = $this->evaluationMeta($currency, $context, true);

        if ($request->input('view') === 'json') {
            return response()->json([
                'quotationHistory' => $quotationHistory,
                'poHistory' => $poHistory,
                'currency' => $currency,
                'evaluation' => $evaluation,
            ]);
        }

        $viewData = [
            'prMode' => true,
            'pr' => $pr,
            'quotationHistory' => $quotationHistory,
            'historyGroups' => $items->map(fn (PrItem $item) => [
                'item' => $this->presentHistoryItem($item),
                'rows' => array_values(array_filter($rows, fn (array $row) => $row['pr_item_hash'] === HashidCodec::encode($item->id))),
            ])->filter(fn (array $group) => $group['rows'] !== [])->values()->all(),
            'poHistory' => $poHistory,
            'currency' => $currency,
            'evaluation' => $evaluation,
            'returnUrl' => $returnUrl,
            'interSupplierUrl' => route('purchasing.comparison.inter-supplier', array_filter(['pr_id' => $pr->getRouteKey(), 'currency' => $request->query('currency')])),
            'clearPrUrl' => route('purchasing.comparison.historical', array_filter(['currency' => $request->query('currency'), 'clear_pr' => 1])),
            'historyItems' => $items->map(fn (PrItem $item) => $this->presentHistoryItem($item))->values()->all(),
            'regionalFormatter' => $this->regionalFormatter,
        ];

        if ($request->ajax()) {
            return response()->json([
                'html' => view('purchasing.comparison._historical_content', $viewData)->render(),
                'tab' => 'historical',
                'url' => route('purchasing.comparison.historical', $request->query()),
            ]);
        }

        return view('purchasing.comparison.historical', $viewData);
    }

    public function historicalDrawerResponse(Request $request, QuotationHistoryService $service, array $history, Collection $items, string $returnUrl): JsonResponse
    {
        $itemId = HashidCodec::decode((string) $request->query('pr_item', ''));
        $item = $itemId === null ? null : $items->firstWhere('id', $itemId);
        $supplier = $this->resolveSupplierQuery($request->query('supplier_id'));
        abort_unless($item instanceof PrItem && $supplier !== null && in_array($supplier->id, array_column($history['suppliers'], 'id'), true), 404);

        $drawer = $service->drawer($history, $item, $supplier->id);

        $price = fn (string $currency, ?string $value) => $value === null ? __('purchasing.comparison_analytics.rate_unavailable') : $currency.' '.$this->formatPrice($value, 4);
        $keyLabel = fn (?array $key) => $key === null ? null : PrItem::dimensionLabel($key['field']).' '.$key['value'];
        $current = $drawer['current'];
        $summary = $drawer['summary'];

        return response()->json([
            'total' => $drawer['total'],
            'comparable_total' => $drawer['comparable_total'],
            'other_total' => $drawer['other_total'],
            'pinned' => $drawer['pinned'],
            'current' => $current === null ? null : [
                'price' => $price($current['analysis_currency'], $current['analysis_price']),
                'original' => $price($current['currency'], $current['price_per_kg']),
                'rate' => $this->annualFxDisplay($current['annual_fx']),
                'date' => $this->formatUtcDate($current['ts']),
                'spec' => PrItem::formatDimensionLabel($current['shape'], $current['requested_spec']),
                'key' => $keyLabel($current['key_dimension']),
            ],
            'summary' => $summary === null ? null : [
                'count' => $summary['count'],
                'lowest' => $price($summary['currency'], $summary['lowest']['price_per_kg']),
                'lowest_date' => $this->formatUtcDate($summary['lowest']['ts']),
                'highest' => $price($summary['currency'], $summary['highest']['price_per_kg']),
                'highest_date' => $this->formatUtcDate($summary['highest']['ts']),
            ],
            'records' => array_map(fn (array $record) => [
                'pr_number' => $record['pr_number'] ?? '-',
                'po_number' => $record['po_number'] ?? '-',
                'date' => $this->formatUtcDate($record['ts']),
                'status' => $record['status'],
                'status_label' => StatusHelper::quotationLabel($record['status']),
                'selected' => $record['selected'],
                'spec_equal' => $record['spec_equal'],
                'key' => $keyLabel($record['key_dimension']),
                'price' => $price($record['analysis_currency'], $record['analysis_price']),
                'original' => $price($record['currency'], $record['price_per_kg']),
                'rate' => $this->annualFxDisplay($record['annual_fx']),
                'diff_pct' => $record['diff_pct'],
                'diff_display' => $record['diff_pct'] === null ? null : $this->formatPercent((float) Money::normalize($record['diff_pct'], 2)),
                'diff_converted' => $record['diff_converted'],
                'basis_note' => $record['basis_differs'] ? implode(' · ', array_filter([
                    $record['incoterm'],
                    $record['port_of_discharge'] === null ? null : LogisticsTerms::portLabel($record['port_of_discharge']),
                ])) : null,
                'detail_url' => $this->routeWithReturn('purchasing.quotations.show', $record['quotation_hash'], $returnUrl),
            ], $drawer['records']),
        ]);
    }

    public function prItemPurchaseHistory(PrItem $item, string $currency, EvaluationContext $context): array
    {
        $field = MaterialReferencePrice::DIMENSION_FIELD_BY_SHAPE[$item->shape] ?? null;
        $key = $field !== null && $item->{$field} !== null ? Money::normalize((string) $item->{$field}, 4) : null;
        [$chartData, , , $summary] = $this->queryService->buildHistoricalData([
            'material_master_id' => $item->material_master_id,
            'material_name' => $item->material_name,
            'shape' => $item->shape,
            'shape_is_null' => $item->shape === null,
            'key_min' => $key,
            'key_max' => $key,
        ], 'monthly', $currency, $context, 1);

        return ['chartData' => $chartData, 'summary' => $summary, 'currency' => $currency];
    }

    public function presentHistoryItem(PrItem $item): array
    {
        return [
            'hash' => HashidCodec::encode($item->id),
            'material' => $item->material_name,
            'shape' => $item->shape,
            'spec' => $item->dimension_label,
            'label' => trim($item->material_name.' '.($item->shape ? '('.$item->shape.')' : '').' '.$item->dimension_label),
        ];
    }

    public function presentHistoryRow(array $row, string $returnUrl): array
    {
        $side = function (?array $side) use ($returnUrl): ?array {
            if ($side === null) {
                return null;
            }

            return [
                'quotation_hash' => $side['quotation_hash'],
                'pr_number' => $side['pr_number'],
                'po_number' => $side['po_number'] ?? null,
                'status' => $side['status'],
                'status_label' => StatusHelper::quotationLabel($side['status']),
                'price_per_kg' => $side['price_per_kg'],
                'currency' => $side['currency'],
                'price_display' => array_key_exists('analysis_price', $side)
                    ? ($side['analysis_price'] === null ? __('purchasing.comparison_analytics.rate_unavailable') : $side['analysis_currency'].' '.$this->formatPrice($side['analysis_price'], 4))
                    : $side['currency'].' '.$this->formatPrice($side['price_per_kg'], 4),
                'original_display' => $side['currency'].' '.$this->formatPrice($side['price_per_kg'], 4),
                'rate_display' => isset($side['annual_fx']) ? $this->annualFxDisplay($side['annual_fx']) : null,
                'date_display' => $this->formatUtcDate($side['ts']),
                'spec' => $side['spec'],
                'spec_display' => $this->specLabel($side['spec']),
                'as_requested' => in_array('as_requested', array_column($side['spec']['fields'], 'source'), true),
                'incoterm' => $side['incoterm'],
                'port_of_discharge' => $side['port_of_discharge'],
                'weight_basis' => $side['weight_basis'],
                'detail_url' => $this->routeWithReturn('purchasing.quotations.show', $side['quotation_hash'], $returnUrl),
            ];
        };
        $comparison = $row['comparison'];
        $priced = in_array($row['status'], QuotationHistoryService::PRICED, true);

        return [
            'pr_item_hash' => $row['pr_item_hash'],
            'supplier' => ['hash' => $row['supplier']['hash'], 'name' => $row['supplier']['name']],
            'status' => $row['status'],
            'status_label' => StatusHelper::quotationHistoryLabel($row['status']),
            'status_tone' => StatusHelper::quotationHistoryTone($row['status']),
            'indicative' => $row['indicative'],
            'unverified_fields' => $row['unverified_fields'],
            'unverified_display' => implode(', ', array_unique(array_map(fn (array $field) => __('purchasing.quotation_history.basis_field_'.$field['field']), $row['unverified_fields']))),
            'sources' => array_map(fn (array $source) => $source + [
                'price_display' => $source['price_per_kg'] === null ? '-' : $source['currency'].' '.$this->formatPrice($source['price_per_kg'], 4),
                'date_display' => $this->formatUtcDate($source['ts']),
                'detail_url' => $this->routeWithReturn('purchasing.quotations.show', $source['quotation_hash'], $returnUrl),
            ], $row['sources']),
            'current' => $side($row['current']),
            'previous' => $side($row['previous']),
            'reference_only' => $row['reference_only'],
            'spec_diff' => array_map(fn (array $diff) => $diff + ['label' => PrItem::dimensionLabel($diff['field'])], $row['spec_diff']),
            'spec_diff_display' => implode(', ', array_map(fn (array $diff) => PrItem::dimensionLabel($diff['field']), $row['spec_diff'])),
            'key_dimension_display' => $row['key_dimension'] === null ? null : PrItem::dimensionLabel($row['key_dimension']['field']).' '.$row['key_dimension']['value'],
            'basis' => $row['basis'],
            'comparison' => $comparison,
            'notes' => $row['notes'],
            'diff' => $row['diff'],
            'diff_pct' => $row['diff_pct'],
            'diff_display' => $priced ? $this->signedAmount($row['diff'], $comparison['currency']) : null,
            'diff_pct_display' => $priced ? $this->formatPercent((float) Money::normalize($row['diff_pct'], 2)) : null,
            'direction' => $priced ? (bccomp($row['diff'], '0', 4) ?: bccomp($row['diff_pct'], '0', 4)) : null,
            'fx_note' => $priced && $comparison['method'] === 'idr_conversion',
            'history_count' => $row['history_count'],
            'comparable_count' => $row['comparable_count'],
        ];
    }

    public function presentHistoricalCharts(array $charts): array
    {
        $tooltip = fn (?array $meta) => $meta === null ? null : [
            'original' => $meta['currency'].' '.$this->formatPrice($meta['native'], 4),
            'method' => $meta['method'],
            'rate' => isset($meta['annual_fx']) ? $this->annualFxDisplay($meta['annual_fx']) : ($meta['rate_to_idr'] === null ? null : __('purchasing.quotation_history.fx_snapshot', ['rate' => $this->formatPrice($meta['rate_to_idr'], 4), 'date' => $this->formatPlainDate($meta['valid_from'])])),
            'pr_number' => $meta['pr_number'],
            'date' => $this->formatUtcDate($meta['ts']),
            'status' => StatusHelper::quotationLabel($meta['status']),
            'indicative' => $meta['indicative'],
        ];
        $reason = fn (string $code) => __('purchasing.quotation_history.chart_reason_'.$code);

        $currentPrevious = $charts['current_previous'];
        $currentPrevious['tooltips'] = [
            'current' => array_map($tooltip, $currentPrevious['meta']['current']),
            'previous' => array_map($tooltip, $currentPrevious['meta']['previous']),
        ];
        unset($currentPrevious['meta']);
        $currentPrevious['excluded'] = array_map(fn (array $entry) => $entry + ['reason_label' => $reason($entry['reason'])], $currentPrevious['excluded']);
        $currentPrevious['axis_label'] = $currentPrevious['basis'].'/kg · '.__('annual_rates.basis');

        $trend = $charts['quote_trend'];
        $trend['series'] = array_map(fn (array $series) => [
            'supplier' => $series['supplier'],
            'points' => array_map(fn (array $point) => ['x' => $point['x'], 'y' => $point['y'], 'tooltip' => $tooltip($point)], $series['points']),
        ], $trend['series']);
        $trend['excluded'] = array_map(fn (array $entry) => $entry + ['reason_label' => trans_choice('purchasing.quotation_history.trend_reason_'.$entry['reason'], $entry['count'], ['count' => $entry['count']])], $trend['excluded']);
        $trend['axis_label'] = $trend['basis'].'/kg · '.__('annual_rates.basis');

        return ['current_previous' => $currentPrevious, 'quote_trend' => $trend];
    }

    public function chartBasisLabel(string $basis, bool $converted): string
    {
        return $converted
            ? __('purchasing.quotation_history.basis_idr_converted')
            : __('purchasing.quotation_history.basis_native', ['currency' => $basis]);
    }

    public function specLabel(array $spec): string
    {
        $parts = [];
        foreach ($spec['fields'] as $field => $value) {
            $display = $value['kind'] === 'range'
                ? $this->formatNumber($value['min'], 4).'-'.$this->formatNumber($value['max'], 4)
                : $this->formatNumber($value['value'], 4);
            $parts[] = PrItem::dimensionLabel($field).' '.$display.($value['source'] === 'as_requested' ? ' ('.__('purchasing.quotation_history.as_requested').')' : '');
        }

        return $parts === [] ? '-' : implode(', ', $parts);
    }

    public function weightBasisLabel(?string $source): string
    {
        return match ($source) {
            'auto' => __('purchasing.quotation_history.weight_basis_auto'),
            'estimated' => __('purchasing.quotation_history.weight_basis_estimated'),
            default => __('purchasing.quotation_history.not_recorded'),
        };
    }
}
