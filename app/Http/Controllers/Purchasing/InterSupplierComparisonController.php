<?php

namespace App\Http\Controllers\Purchasing;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Purchasing\Concerns\PriceComparisonSharedTrait;
use App\Models\MaterialReferencePrice;
use App\Models\PrItem;
use App\Models\PrItemAward;
use App\Models\PurchaseRequisition;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Services\Pricing\PurchaseHistoryPriceService;
use App\Services\Pricing\ReferencePriceResolver;
use App\Services\PrItemAwardService;
use App\Services\RegionalDisplayFormatter;
use App\Support\Money;
use App\Support\Pricing\EvaluationContext;
use App\Support\PurchasingNavigation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class InterSupplierComparisonController extends Controller
{
    use PriceComparisonSharedTrait;

    public function __construct(
        ?RegionalDisplayFormatter $regionalFormatter = null
    ) {
        $this->regionalFormatter = $regionalFormatter ?? app(RegionalDisplayFormatter::class);
    }

    /**
     * View 1: supplier comparison across all quotation items
     * within a single PR, shown side by side.
     */
    public function interSupplier(Request $request): View|JsonResponse
    {
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
                        'dimension_display' => $this->comparisonDimensions($item),
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
                            'amount' => $requestedAmount,
                            'requested_amount' => $requestedAmount,
                            'offer_amount' => $offerAmount,
                            'offer_amount_idr' => $offerAmountIdr,
                            'is_available' => $isAvailable,
                            'is_awarded' => $isAwarded,
                            'is_selectable' => $isSelectable,
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
                            'dimension_display' => $quotationItem ? $this->comparisonDimensions($item, $quotationItem) : '–',
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
                    'label_details' => $comparisonItems
                        ->map(fn (PrItem $item) => trim(($item->shape ? $item->shape.' ' : '').$item->dimension_label))
                        ->values()
                        ->all(),
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

    /** Compact PR/offer dimensions for the Inter-Supplier table only. */
    public function comparisonDimensions(PrItem $item, ?QuotationItem $offer = null): string
    {
        $this->ensureRegionalFormatter();
        $fields = PrItem::presentationDimensionFields($item->shape);
        if ($fields === [] || ($offer !== null && ! $offer->isAvailable())) {
            return '–';
        }
        $labels = ['thickness' => 'T', 'width' => 'W', 'd_outer' => $item->shape === PrItem::SHAPE_HOLLOW ? 'OD' : 'D', 'd_inner' => 'ID', 'length' => 'L'];
        $format = fn ($value) => $value === null || $value === '' ? '–'
            : $this->regionalFormatter->number(PrItem::formatDimensionValue($value), 'decimal');
        $parts = [];
        foreach ($fields as $field) {
            $value = $offer === null ? $item->{$field} : $offer->{'available_'.$field};
            $display = $field === 'length' && $offer !== null && $offer->available_length_min !== null && $offer->available_length_max !== null
                ? $format($offer->available_length_min).'–'.$format($offer->available_length_max)
                : $format($value);
            $parts[] = $labels[$field].$display;
        }

        return implode(' × ', $parts).' mm';
    }

    public function itemIndicators(PrItem $item, Collection $quotations, string $currency, EvaluationContext $context): array
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
}
