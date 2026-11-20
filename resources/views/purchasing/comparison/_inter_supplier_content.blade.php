<style>
.comparison-matrix-table {
    border-collapse: separate !important;
    border-spacing: 0;
    min-width: max-content;
    width: max-content;
    max-width: none;
}
.comparison-matrix-table th,
.comparison-matrix-table td {
    border-right: 1px solid var(--md-outline-variant);
    border-bottom: 1px solid var(--md-outline-variant);
    background-color: var(--md-surface);
}
.comparison-matrix-table thead th {
    background-color: var(--md-surface-container-low) !important;
    vertical-align: middle;
}
/* PUR-04: stickiness comes from .ui-table-sticky/.ui-sticky-col (app.css), which layers the
   header above the frozen column; only the width lives here. */
.comparison-material-col {
    min-width: 200px;
    max-width: 240px;
    width: 220px;
}
.col-ref-qty {
    min-width: 65px;
}
.col-ref-weight {
    min-width: 110px;
}
.col-ref-total-weight {
    min-width: 120px;
}
.col-supplier-group {
    min-width: 360px;
}
.col-supplier-cell {
    min-width: 120px;
}
</style>

<x-ui.toolbar class="tw:mb-0">
    <form method="GET" action="{{ route('purchasing.comparison.inter-supplier') }}" class="tw:w-full" id="interSupplierFilterForm" data-server-tabs-form>
        <label for="comparisonPrSearch" class="form-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('purchasing.copy.purchase_requisition') }}</label>
        <div class="tw:grid tw:gap-3 tw:md:grid-cols-[minmax(0,1fr)_auto] tw:md:items-center">
            <div class="position-relative">
                <input type="hidden" name="pr_id" id="comparisonPrId" value="{{ $selectedPrOption['id'] ?? '' }}">
                <input type="hidden" name="benchmark_year" id="comparisonBenchmarkYearInput" value="{{ $benchmarkYear ?? '' }}">
                <input type="hidden" name="benchmark_month" id="comparisonBenchmarkMonthInput" value="{{ $benchmarkMonth ?? '' }}">
                <div class="tw:relative tw:flex tw:items-center">
                    <span class="tw:pointer-events-none tw:absolute tw:start-3 tw:top-1/2 tw:-translate-y-1/2 tw:inline-flex tw:items-center tw:text-on-surface-variant" aria-hidden="true">
                        <x-ui.icon name="search" size="sm" />
                    </span>
                    <input type="text"
                           class="form-control form-control-sm tw:min-h-[var(--ui-control-height-sm)] tw:ps-9 tw:pe-10 ui-focus-ring"
                           id="comparisonPrSearch"
                           value="{{ $selectedPrOption['label'] ?? '' }}"
                           placeholder="{{ __('purchasing.copy.type_a_pr_number_or_period') }}"
                           aria-describedby="comparisonPrHelp"
                           autocomplete="off">
                    <span class="tw:pointer-events-none tw:absolute tw:end-1 tw:top-1/2 tw:-translate-y-1/2 tw:inline-flex tw:items-center">
                        <x-ui.icon-button
                            icon="x"
                            :label="__('purchasing.copy.clear_pr_selection')"
                            size="sm"
                            id="comparisonPrClear"
                            class="tw:pointer-events-auto {{ $selectedPrOption ? '' : 'd-none' }}"
                        />
                    </span>
                </div>
                <div class="list-group position-absolute w-100 tw:shadow-ui-2 d-none tw:z-drawer tw:max-h-[260px] tw:overflow-y-auto"
                     id="comparisonPrSuggestions"></div>
            </div>
            <div>
                <x-ui.button type="submit" size="sm" class="tw:w-full tw:md:w-auto">
                    <x-slot:leading><x-ui.icon name="search" size="sm" /></x-slot:leading>
                    {{ __('purchasing.copy.compare') }}
                </x-ui.button>
            </div>
        </div>
        <div id="comparisonPrHelp" class="form-text tw:mt-1">{{ __('purchasing.copy.type_to_display_pr_options_then_select_one_option') }}</div>
    </form>
</x-ui.toolbar>

@include('purchasing.comparison._evaluation_header', ['evaluation' => $evaluation, 'route' => 'purchasing.comparison.inter-supplier'])

@if($comparison)
    @php
        $supplierTotals = [];
        $supplierWins = [];
        foreach($comparison['suppliers'] as $sup) {
            $supplierTotals[$sup['quotation_id']] = [
                'name' => $sup['name'],
                'total' => 0,
                'total_price_per_kg_idr' => 0,
                'quoted_items_count' => 0,
            ];
            $supplierWins[$sup['quotation_id']] = 0;
        }

        foreach($comparison['matrix'] as &$row) {
            $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
            $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
            $maxIdr = $idrPrices->count() > 0 ? $idrPrices->max() : null;

            $row['spread_pct'] = 0;
            if($minIdr && $minIdr > 0 && $maxIdr) {
                $row['spread_pct'] = (($maxIdr - $minIdr) / $minIdr) * 100;
            }

            foreach($comparison['suppliers'] as $sup) {
                $p = $row['prices'][$sup['quotation_id']] ?? null;
                if($p && $p['is_available'] && $p['price_idr']) {
                    $supplierTotals[$sup['quotation_id']]['total'] += $p['offer_amount_idr'] ?? 0;
                    $supplierTotals[$sup['quotation_id']]['total_price_per_kg_idr'] += (float) $p['price_idr'];
                    $supplierTotals[$sup['quotation_id']]['quoted_items_count']++;
                    if($minIdr && $p['price_idr'] <= $minIdr) {
                        $supplierWins[$sup['quotation_id']]++;
                    }
                }
            }
        }
        unset($row);

        // B1, B2, B4: Best Total is determined by the lowest Total Price/kg (IDR), NOT Estimated Total.
        $validTotals = array_filter(
            $supplierTotals,
            fn($v) => $v['total_price_per_kg_idr'] > 0 && $v['quoted_items_count'] > 0
        );
        $recommendedSupId = null;
        if(count($validTotals) > 0) {
            $maxQuotedCount = max(array_column($validTotals, 'quoted_items_count'));
            $topCoverageTotals = array_filter($validTotals, fn($v) => $v['quoted_items_count'] === $maxQuotedCount);
            $minPricePerKgTotal = min(array_column($topCoverageTotals, 'total_price_per_kg_idr'));
            foreach($topCoverageTotals as $qid => $data) {
                if(abs($data['total_price_per_kg_idr'] - $minPricePerKgTotal) < 0.01) {
                    $recommendedSupId = $qid;
                    break;
                }
            }
        }
    @endphp

    {{-- Commercial overview --}}
    @if(count($validTotals) > 0)
        <x-ui.data-table
            :title="__('purchasing.copy.commercial_overview')"
            :description="__('purchasing.copy.ranked_supplier_totals_and_line_item_price_leadership_for_the_selected_pr')"
        >
            <table class="table table-hover align-middle mb-0 tw:text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.total_price_per_kg_idr') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.estimated_total') }}</th>
                        <th scope="col" class="text-center">{{ __('purchasing.copy.lowest_price_items') }}</th>
                        <th scope="col">{{ __('purchasing.copy.position') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($validTotals as $qid => $data)
                        <tr class="{{ $recommendedSupId === $qid ? 'ui-best-price-row' : '' }}">
                            <td class="fw-semibold tw:text-on-surface">{{ $data['name'] }}</td>
                            <td class="text-end fw-bold ui-tabular-nums {{ $recommendedSupId === $qid ? 'ui-best-price-cell' : 'text-primary' }}">
                                Rp {{ isset($regionalFormatter) ? $regionalFormatter->groupedDecimal($data['total_price_per_kg_idr'], 2, 'indonesian') : \App\Support\NumberFormat::grouped($data['total_price_per_kg_idr']) }}
                            </td>
                            <td class="text-end fw-semibold ui-tabular-nums tw:text-on-surface">
                                Rp {{ isset($regionalFormatter) ? $regionalFormatter->groupedDecimal($data['total'], 2, 'indonesian') : \App\Support\NumberFormat::grouped($data['total']) }}
                            </td>
                            <td class="text-center ui-tabular-nums">{{ $supplierWins[$qid] }}</td>
                            <td>
                                @if($recommendedSupId === $qid)
                                    <x-ui.status-chip tone="success" icon="star">{{ __('purchasing.copy.best_total') }}</x-ui.status-chip>
                                @else
                                    <x-ui.status-chip tone="neutral">{{ __('purchasing.copy.alternative') }}</x-ui.status-chip>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.data-table>
    @endif

    {{-- Grafik Batang --}}
    <x-ui.card :title="__('purchasing.comparison_analytics.chart_title', ['currency' => $currency])">
        <x-slot:actions>
            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-3">
                <div class="tw:inline-flex tw:gap-1 tw:rounded-ui-sm tw:border tw:border-outline tw:p-1" role="group" aria-label="{{ __('purchasing.comparison_analytics.chart_scale') }}">
                    @foreach(['price' => __('purchasing.comparison_analytics.chart_scale_price', ['currency' => $currency]), 'relative' => __('purchasing.comparison_analytics.chart_scale_relative')] as $scale => $scaleLabel)
                        <button type="button"
                                class="ui-focus-ring tw:min-h-8 tw:rounded-ui-sm tw:border-0 tw:bg-transparent tw:px-3 tw:text-ui-sm tw:font-semibold tw:text-on-surface-variant tw:hover:bg-surface-container tw:aria-pressed:bg-primary tw:aria-pressed:text-primary-foreground"
                                aria-pressed="{{ (request('chart_scale') === 'relative') === ($scale === 'relative') ? 'true' : 'false' }}"
                                data-comparison-chart-scale="{{ $scale }}">{{ $scaleLabel }}</button>
                    @endforeach
                </div>
                <div class="tw:min-w-60">
                    <label class="form-label small fw-bold mb-1" for="comparisonMaterialFilter">{{ __('purchasing.copy.material') }}</label>
                    <select class="form-select form-select-sm" id="comparisonMaterialFilter">
                        <option value="">{{ __('purchasing.copy.all_material') }}</option>
                        @foreach($materialOptions as $material)
                            <option value="{{ $material->id }}" data-material-name="{{ $material->material_name }}" data-shape="{{ $material->shape ?? '' }}">{{ $material->material_name }}{{ $materialOptions->where('material_name', $material->material_name)->count() > 1 && $material->shape ? ' ('.$material->shape.')' : '' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </x-slot:actions>
        {{-- The aria-label carries the lowest-offer summary (set by the chart script); the matrix below lists every price. --}}
        <x-ui.chart id="comparisonChart" :label="__('purchasing.copy.supplier_quotation_price_comparison')" :fallback="__('purchasing.copy.supplier_quotation_price_comparison_chart')" :auto-summary="false" plot-class="tw:h-80" :scrollable="true" />
        <div id="comparisonChartLegend" class="tw:mt-3 tw:flex tw:flex-wrap tw:items-stretch tw:justify-center tw:gap-2.5" role="list" aria-label="{{ __('purchasing.copy.supplier_quotation_price_comparison') }}"></div>

    </x-ui.card>

    {{-- Item-Level Selection Coverage Banner --}}
    @if($awardCoverage)
        <div class="tw:p-4 tw:rounded-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
            <div class="tw:flex tw:items-center tw:gap-3">
                <div class="tw:rounded-full tw:p-2.5 tw:bg-primary-container tw:text-primary">
                    <x-ui.icon name="clipboard-check" size="md" />
                </div>
                <div>
                    <div class="fw-bold tw:text-ui-sm tw:text-on-surface">{{ __('purchasing.copy.item_selection_status') }}</div>
                    <div class="tw:text-ui-xs tw:text-on-surface-variant">
                        {{ trans_choice('purchasing.page.selection_coverage', $awardCoverage['total_items'], ['awarded' => $awardCoverage['awarded_items'], 'total' => $awardCoverage['total_items'], 'percentage' => $awardCoverage['coverage_percentage']]) }}
                    </div>
                </div>
            </div>
            <div class="tw:flex tw:items-center tw:gap-2">
                @if($awardCoverage['is_fully_awarded'])
                    <span class="ui-status-chip ui-status-chip--success">{{ __('purchasing.copy.100_fully_selected') }}</span>
                @else
                    <span class="ui-status-chip ui-status-chip--warning">{{ trans_choice('purchasing.page.unselected_count', $awardCoverage['unawarded_items']) }}</span>
                @endif
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('purchasing.comparison.save-awards') }}" id="itemAwardForm" class="tw:min-w-0 tw:max-w-full">
        @csrf
        <input type="hidden" name="pr_id" value="{{ $selectedPr->getRouteKey() }}">

        {{-- Side-by-side comparison table --}}
        <x-ui.data-table sticky :scroll-label="__('procurement.tables.comparison_matrix')" :title="__('purchasing.page.comparison_title', ['pr' => $selectedPr->pr_number])" :description="__('purchasing.copy.select_supplier_offers_per_pr_item_maximum_one_offer_per_item')" class="tw:min-w-0 tw:max-w-full">
                <table class="table table-bordered table-hover align-middle mb-0 tw:text-ui-xs comparison-matrix-table">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" rowspan="2" class="align-middle ui-sticky-col ui-table-sticky-col comparison-material-col">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-qty text-center">{{ __('purchasing.copy.qty') }}</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-weight text-center">{{ __('purchasing.comparison_analytics.kg_per_unit') }}</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-total-weight text-center">{{ __('purchasing.comparison_analytics.total_kg') }}</th>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="colgroup" colspan="3" class="text-center col-supplier-group">
                                    <div class="fw-bold tw:text-on-surface">{{ $sup['name'] }}</div>
                                    <div class="tw:mt-1">
                                        <x-ui.status-chip :tone="$sup['status'] === 'accepted' ? 'success' : ($sup['status'] === 'rejected' ? 'error' : 'info')" size="sm">
                                            {{ \App\Support\StatusHelper::quotationLabel($sup['status']) }}
                                        </x-ui.status-chip>
                                    </div>
                                    <div class="tw:mt-1.5 tw:text-ui-xs tw:text-on-surface-variant" title="{{ __('purchasing.copy.supplier_estimate_delivery') }}">
                                        <span class="fw-semibold">{{ __('purchasing.copy.supplier_estimate_delivery_prefix') }}</span>
                                        <span class="tw:text-on-surface">{{ $sup['estimated_delivery_formatted'] ?? '-' }}</span>
                                    </div>
                                    {{-- PUR-03: no supplier contact person on Price Comparison. --}}
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="col" class="text-center small col-supplier-cell">{{ __('purchasing.copy.price_kg') }} ({{ $sup['currency'] }})</th>
                                <th scope="col" class="text-center small col-supplier-cell">{{ __('purchasing.copy.price_kg_idr') }}</th>
                                <th scope="col" class="text-center small col-supplier-cell">{{ __('purchasing.copy.offer_amount') }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $formatNumber = fn ($value, $decimals = 2) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, $decimals), 'decimal') : \App\Support\NumberFormat::maxDecimals($value, $decimals)) : '-';
                            $formatPrice = fn ($value, $decimals = 2) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->groupedDecimal($value, $decimals, 'indonesian') : \App\Support\NumberFormat::maxDecimals($value, $decimals)) : '-';
                            $formatInteger = fn ($value) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->number(number_format((float) $value, 0, ',', '.'), 'indonesian') : number_format((float) $value, 0, ',', '.')) : '-';
                        @endphp
                        @foreach($comparison['matrix'] as $row)
                            @php
                                $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
                                $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
                            @endphp
                            <tr data-comparison-row data-material-id="{{ $row['item']->id }}" class="{{ ($row['spread_pct'] ?? 0) > 15 ? 'bg-warning bg-opacity-10' : '' }}">
                                <td class="fw-medium ui-sticky-col ui-table-sticky-col comparison-material-col">
                                    <div class="tw:font-semibold tw:text-on-surface">{{ $row['item']->material_name }}</div>
                                    <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5" data-requested-dimensions>{{ $row['dimension_display'] }}</div>
                                    @if(($row['spread_pct'] ?? 0) > 15)
                                        <div class="small text-danger mt-1" data-bs-toggle="tooltip" title="{{ __('purchasing.copy.high_price_spread_15') }}">
                                            <x-ui.icon name="triangle-alert" class="me-1" />{{ __('purchasing.copy.price_spread') }} {{ isset($regionalFormatter) ? $regionalFormatter->number(number_format($row['spread_pct'], 1, ',', '.'), 'indonesian') : number_format($row['spread_pct'], 1) }}%
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center col-ref-qty">{{ $formatInteger($row['item']->quantity_value) }}</td>
                                <td class="text-center col-ref-weight">{{ $formatNumber($row['item']->weight_needed) }}</td>
                                <td class="text-center fw-medium text-primary col-ref-total-weight">{{ $formatNumber($row['item']->total_weight) }}</td>
                                @foreach($comparison['suppliers'] as $sup)
                                    @php $p = $row['prices'][$sup['quotation_id']] ?? null; @endphp
                                    @if($p && !$p['is_available'])
                                        <td class="text-center" colspan="3">
                                            <span class="ui-status-chip ui-status-chip--error">{{ __('purchasing.copy.not_available') }}</span>
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" :label="__('purchasing.copy.open_quotation_details')" size="sm" class="tw:ms-1" />
                                            @endif
                                        </td>
                                    @elseif($p && $p['price_per_kg'])
                                        <td class="text-end">
                                            {{ $formatPrice($p['price_per_kg'], 4) }}
                                            <div class="tw:text-on-surface-variant tw:text-ui-xs tw:mt-0.5" data-offered-dimensions>{{ $p['dimension_display'] }}</div>
                                        </td>
                                        <td class="text-end fw-bold {{ ($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr) ? 'ui-best-price-cell' : '' }}">
                                            Rp {{ $formatPrice($p['price_idr']) }}
                                            @if($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr)
                                                <x-ui.icon name="circle-check" class="ms-1" />
                                            @endif
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" :label="__('purchasing.copy.open_quotation_details')" size="sm" class="tw:ms-1" />
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold ui-tabular-nums">
                                            {{ $formatPrice($p['offer_amount']) }} {{ $p['currency'] }}
                                            <div class="tw:text-on-surface-variant tw:text-ui-xs">Rp {{ $formatPrice($p['offer_amount_idr']) }}</div>
                                            @if(!empty($p['is_selectable']))
                                                <div class="tw:mt-2 tw:pt-1.5 tw:border-t tw:border-outline-variant text-center">
                                                    <label class="tw:inline-flex tw:items-center tw:gap-1.5 tw:cursor-pointer">
                                                        <input type="radio"
                                                               name="awards[{{ $row['item']->id }}]"
                                                               value="{{ $p['quotation_item_id'] }}"
                                                               {{ !empty($p['is_awarded']) ? 'checked' : '' }}
                                                               class="form-check-input tw:mt-0 award-radio"
                                                               data-pr-item-id="{{ $row['item']->id }}"
                                                               data-pr-item-name="{{ $row['item']->material_name }}"
                                                               data-supplier-id="{{ $sup['id'] }}"
                                                               data-supplier-name="{{ $sup['name'] }}"
                                                               data-supplier-estimated-ready="{{ $sup['estimated_delivery'] ?? '' }}"
                                                               data-price="Rp {{ \App\Support\NumberFormat::maxDecimals($p['price_idr'] ?? 0) }}"
                                                               data-price-display="Rp {{ $formatPrice($p['price_idr'] ?? 0) }}"
                                                        >
                                                        <span class="tw:text-ui-xs fw-bold {{ !empty($p['is_awarded']) ? 'text-success' : 'text-primary' }}">
                                                            {{ !empty($p['is_awarded']) ? __('purchasing.copy.selected_offer') : __('purchasing.copy.select_offer') }}
                                                        </span>
                                                    </label>
                                                    @if(\App\Support\Procurement\ProcurementFeatures::orderedQuantityWriteEnabled())
                                                        @include('procurement._order_qty_control', [
                                                            'prItemId' => $row['item']->id,
                                                            'material' => $row['item']->material_name,
                                                            'requested' => $row['item']->quantity_value,
                                                            'offered' => (int) ($p['available_qty'] ?? $row['item']->quantity_value),
                                                            'value' => $p['ordered_qty'] ?? null,
                                                            'disabled' => empty($p['is_awarded']),
                                                            'specDifferent' => ($p['spec_code'] ?? null) === 'different',
                                                            'specAccepted' => ! empty($p['spec_accepted']),
                                                            'requestedSpec' => $p['requested_spec'] ?? null,
                                                            'offeredSpec' => $p['offered_spec'] ?? null,
                                                            'idSuffix' => $row['item']->id.'_'.$p['quotation_item_id'],
                                                        ])
                                                    @endif
                                                </div>
                                            @elseif(!empty($p['is_awarded']))
                                                <div class="tw:mt-2 tw:pt-1.5 tw:border-t tw:border-outline-variant text-center">
                                                    <span class="ui-status-chip ui-status-chip--success">
                                                        <x-ui.icon name="check" size="sm" /> {{ __('purchasing.copy.po_created') }}
                                                    </span>
                                                    @if(!empty($p['purchase_order_url']))
                                                        <a href="{{ $p['purchase_order_url'] }}" class="d-block tw:mt-1 tw:text-ui-xs text-primary fw-semibold text-decoration-underline">
                                                            {{ $p['purchase_order_number'] ?? __('purchasing.copy.view_purchase_order') }}
                                                        </a>
                                                    @else
                                                        <span class="d-block tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.copy.purchase_order_assigned') }}</span>
                                                    @endif
                                                </div>
                                            @endif
                                        </td>
                                    @else
                                        <td class="text-center text-muted" colspan="3">{{ __('purchasing.copy.no_quotation') }}</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
        </x-ui.data-table>

        {{-- Mission 07: benchmark indicators per PR item --}}
        <div class="tw:mt-4">@include('purchasing.comparison._price_indicators', ['matrix' => $comparison['matrix'], 'currency' => $currency, 'benchmarkYear' => $benchmarkYear ?? null, 'benchmarkMonth' => $benchmarkMonth ?? null])</div>

        @if($hasActionableAwardSelections)
            {{-- Existing PO assignments remain visible while unassigned items stay actionable. --}}
            @if($assignedPurchaseOrders->isNotEmpty() || $assignedPurchaseOrderCount > 0)
                <x-ui.card :title="__('purchasing.copy.existing_purchase_order_s')" class="tw:mt-4">
                    <div class="tw:flex tw:flex-wrap tw:gap-2">
                        @forelse($assignedPurchaseOrders as $assignedPurchaseOrder)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $assignedPurchaseOrder) }}" class="ui-status-chip ui-status-chip--success text-decoration-none">
                                <x-ui.icon name="receipt" size="sm" /> {{ $assignedPurchaseOrder->po_number }}
                            </a>
                        @empty
                            <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.copy.purchase_order_assignment_is_recorded_but_its_detail_is_unavailable') }}</span>
                        @endforelse
                    </div>
                </x-ui.card>
            @endif

            {{-- Offer Selection & PO Grouping Preview Card --}}
            <x-ui.card :title="__('purchasing.copy.offer_selection_po_grouping_preview')" class="tw:mt-4">
                <div class="tw:grid tw:gap-4 tw:lg:grid-cols-2">
                    <div class="tw:flex tw:min-w-0 tw:flex-col tw:gap-2">
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                            <div class="tw:flex tw:items-center tw:gap-1.5 fw-bold tw:text-ui-sm tw:text-on-surface">
                                <x-ui.icon name="check-check" size="sm" class="text-primary" />
                                <span>{{ __('purchasing.copy.selected_line_item_offers') }}</span>
                                <span id="awardPreviewCountBadge" class="ui-status-chip ui-status-chip--neutral tw:hidden" aria-live="polite"></span>
                            </div>
                            <div class="tw:w-full tw:sm:w-52">
                                <label for="awardPreviewSearch" class="visually-hidden">{{ __('purchasing.copy.filter_selected_offers_label') }}</label>
                                <input
                                    type="search"
                                    id="awardPreviewSearch"
                                    class="form-control form-control-sm"
                                    placeholder="{{ __('purchasing.copy.filter_selected_offers') }}"
                                    autocomplete="off"
                                    disabled
                                >
                            </div>
                        </div>
                        <div
                            id="awardPreviewItems"
                            role="region"
                            tabindex="0"
                            aria-label="{{ __('purchasing.copy.selected_line_item_offers') }}"
                            class="ui-focus-ring tw:max-h-72 tw:overflow-y-auto tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface tw:text-ui-xs"
                        >
                            <div class="tw:p-3 tw:text-on-surface-variant">{{ __('purchasing.copy.select_offers_in_the_table_above_to_preview_selection_and_supplier_po_groups') }}</div>
                        </div>
                    </div>

                    <div class="tw:flex tw:min-w-0 tw:flex-col tw:gap-2">
                        <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2 tw:min-h-[1.9375rem]">
                            <div class="tw:flex tw:items-center tw:gap-1.5 fw-bold tw:text-ui-sm tw:text-on-surface">
                                <x-ui.icon name="building" size="sm" class="text-primary" />
                                <span>{{ __('purchasing.copy.resulting_po_grouping_preview_1_po_per_selected_supplier') }}</span>
                                <span id="supplierGroupCountBadge" class="ui-status-chip ui-status-chip--info tw:hidden" aria-live="polite"></span>
                            </div>
                        </div>
                        <div
                            id="supplierGroupPreview"
                            role="region"
                            tabindex="0"
                            aria-label="{{ __('purchasing.copy.resulting_po_grouping_preview_1_po_per_selected_supplier') }}"
                            class="ui-focus-ring tw:max-h-72 tw:overflow-y-auto tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container tw:p-2.5 tw:text-ui-xs"
                        >
                            <div class="tw:p-0.5 tw:text-on-surface-variant">{{ __('purchasing.copy.1_po_will_be_created_per_selected_supplier_group_upon_confirmation') }}</div>
                        </div>
                    </div>
                </div>

                <div class="tw:mt-4 tw:pt-4 tw:border-t tw:border-outline-variant tw:grid tw:gap-3 tw:sm:grid-cols-2">
                    <div>
                        <x-ui.date-picker
                            id="poEstimatedArrival"
                            name="estimated_arrival"
                            :label="__('purchasing.copy.po_target_arrival_date')"
                            :value="now()->addDays(14)->format('Y-m-d')"
                        />
                        <div class="form-text tw:text-ui-xs tw:text-on-surface-variant tw:mt-1">
                            <span class="fw-semibold">{{ __('purchasing.copy.supplier_estimate_delivery_prefix') }}</span> {{ __('purchasing.copy.when_supplier_expects_material_ready_to_send') }}<br>
                            <span class="fw-semibold">{{ __('purchasing.copy.po_target_arrival_date_21ee07') }}</span> {{ __('purchasing.copy.when_purchasing_expects_material_at_adsi') }}
                        </div>
                        <div id="targetArrivalWarning" class="alert alert-warning py-1.5 px-2.5 tw:text-ui-xs tw:mt-2 d-none" role="alert">
                            <x-ui.icon name="triangle-alert" size="sm" class="me-1 text-warning" />
                            <span>{{ __('purchasing.copy.po_target_earlier_than_supplier_estimate_delivery') }}</span>
                        </div>
                    </div>
                    <div>
                        <label for="poNotes" class="form-label small fw-semibold tw:text-on-surface">{{ __('purchasing.copy.po_notes_remarks') }}</label>
                        <input type="text" name="notes" id="poNotes" class="form-control form-control-sm" placeholder="{{ __('purchasing.copy.optional_notes_for_generated_po_s') }}">
                    </div>
                </div>

                {{-- D2: Destination Port (Port of Discharge) re-confirmation before PO generation --}}
                @php
                    $defaultPort = old('port_of_discharge', $selectedPr?->port_of_discharge ?? \App\Support\Procurement\LogisticsTerms::ports()[0] ?? 'TANJUNG_PRIOK');
                @endphp
                <div class="tw:mt-3 tw:p-3 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-low">
                    <div class="tw:grid tw:gap-3 tw:sm:grid-cols-2 tw:items-start">
                        <div>
                            <label for="poPortOfDischarge" class="form-label small fw-semibold tw:text-on-surface">
                                {{ __('purchasing.copy.confirm_port_of_discharge_label') }} <span class="text-danger">*</span>
                            </label>
                            <select name="port_of_discharge" id="poPortOfDischarge" class="form-select form-select-sm @error('port_of_discharge') is-invalid @enderror">
                                @foreach(\App\Support\Procurement\LogisticsTerms::ports() as $portCode)
                                    <option value="{{ $portCode }}" {{ $defaultPort === $portCode ? 'selected' : '' }}>
                                        {{ \App\Support\Procurement\LogisticsTerms::portLabel($portCode) }}
                                    </option>
                                @endforeach
                            </select>
                            @error('port_of_discharge')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="tw:sm:pt-6">
                            <div class="form-check tw:mb-0">
                                <input
                                    type="checkbox"
                                    name="confirm_port_of_discharge"
                                    id="confirmPortOfDischarge"
                                    value="1"
                                    class="form-check-input @error('confirm_port_of_discharge') is-invalid @enderror"
                                    {{ old('confirm_port_of_discharge') ? 'checked' : '' }}
                                >
                                <label for="confirmPortOfDischarge" class="form-check-label tw:text-ui-xs fw-semibold tw:text-on-surface tw:cursor-pointer">
                                    {{ __('purchasing.copy.confirm_port_of_discharge_checkbox') }}
                                </label>
                                @error('confirm_port_of_discharge')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>
                        </div>
                    </div>
                </div>

                @unless($poGenerationEnabled)
                    <x-ui.alert tone="warning" class="tw:mt-3">{{ __('procurement.po_generation.disabled_notice') }}</x-ui.alert>
                @endunless

                <x-slot:footer>
                    <div class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3">
                        <div id="awardPreviewFooterSummary" class="tw:text-ui-xs tw:text-on-surface-variant fw-semibold" aria-live="polite">
                            {{ __('purchasing.copy.selected_offers_summary_empty') }}
                        </div>
                        <div class="tw:flex tw:flex-wrap tw:gap-2">
                            <x-ui.button type="submit" name="action" value="save" variant="outline" size="sm">
                                <x-slot:leading><x-ui.icon name="save" size="sm" /></x-slot:leading>
                                {{ __('purchasing.copy.save_selected_offers') }}
                            </x-ui.button>
                            <x-ui.button type="submit" name="action" value="generate_pos" variant="primary" size="sm" id="btnGeneratePos" :disabled="! $poGenerationEnabled">
                                <x-slot:leading><x-ui.icon name="receipt" size="sm" /></x-slot:leading>
                                {{ __('purchasing.audit_ui.confirm_generation') }}
                            </x-ui.button>
                        </div>
                    </div>
                </x-slot:footer>
            </x-ui.card>
        @elseif($allItemsAssignedToPurchaseOrder)
            <x-ui.card :title="__('purchasing.copy.purchase_order_s_already_generated')" class="tw:mt-4">
                <div class="tw:flex tw:flex-col tw:gap-3">
                    <div class="tw:text-ui-sm tw:text-on-surface-variant">
                        {{ __('purchasing.copy.all_pr_items_have_been_assigned_to_purchase_order_s_no_additional_selection_or_po_action_is_required') }}
                    </div>
                    <div class="tw:flex tw:flex-wrap tw:gap-2">
                        @forelse($assignedPurchaseOrders as $assignedPurchaseOrder)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $assignedPurchaseOrder) }}" class="ui-status-chip ui-status-chip--success text-decoration-none">
                                <x-ui.icon name="receipt" size="sm" /> {{ $assignedPurchaseOrder->po_number }}
                            </a>
                        @empty
                            <span class="tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.copy.purchase_order_assignment_is_recorded_but_its_detail_is_unavailable') }}</span>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        @else
            <x-ui.card :title="__('purchasing.copy.item_selection_status')" class="tw:mt-4">
                <div class="tw:text-ui-sm tw:text-on-surface-variant">{{ __('purchasing.copy.no_additional_offer_selections_are_currently_available_for_this_pr') }}</div>
            </x-ui.card>
        @endif
    </form>
@elseif(request('pr_id'))
    <x-ui.alert tone="warning">{{ __('purchasing.copy.no_data_found_for_the_selected_pr') }}</x-ui.alert>
@else
    <x-ui.card padding="none">
        <x-ui.empty-state icon="chart-no-axes-combined" :title="__('purchasing.copy.select_a_pr_to_compare')" :description="__('purchasing.copy.choose_an_eligible_purchase_requisition_above_to_compare_supplier_prices')" />
    </x-ui.card>
@endif

@php
    $chartI18n = [
        'title' => __('purchasing.comparison_analytics.chart_title', ['currency' => $currency ?? 'IDR']),
        'lowest' => __('purchasing.comparison_analytics.chart_lowest'),
        'noOffer' => __('purchasing.comparison_analytics.chart_no_offer'),
        'aboveLowest' => __('purchasing.comparison_analytics.chart_above_lowest', ['percent' => ':percent']),
        'summary' => __('purchasing.comparison_analytics.chart_summary', ['items' => ':items']),
    ];
@endphp
<script id="interSupplierConfig" type="application/json">
{
    "eligiblePrOptions": @json($eligiblePrOptions),
    "chartData": @json($chartData),
    "chartMaterialIds": @json($chartMaterialIds),
    "chartI18n": @json($chartI18n)
}
</script>
