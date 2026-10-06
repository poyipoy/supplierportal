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
.col-sticky-material {
    position: sticky;
    left: 0;
    z-index: 5;
    background-color: var(--md-surface) !important;
    box-shadow: 2px 0 6px -2px rgba(0, 0, 0, 0.12);
    min-width: 200px;
    max-width: 240px;
    width: 220px;
}
thead th.col-sticky-material {
    background-color: var(--md-surface-container-low) !important;
    z-index: 12;
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

<x-ui.toolbar class="tw-mb-0">
    <form method="GET" action="{{ route('purchasing.comparison.inter-supplier') }}" class="tw-grid tw-w-full tw-gap-4 md:tw-grid-cols-[minmax(0,1fr)_auto] md:tw-items-end" id="interSupplierFilterForm" data-server-tabs-form>
        <div>
            <label for="comparisonPrSearch" class="form-label small fw-bold">{{ __('purchasing.copy.purchase_requisition') }}</label>
            <div class="position-relative">
                <input type="hidden" name="pr_id" id="comparisonPrId" value="{{ $selectedPrOption['id'] ?? '' }}">
                <div class="input-group input-group-sm">
                    <span class="input-group-text tw-bg-surface"><x-ui.icon name="search" /></span>
                    <input type="text"
                            class="form-control"
                            id="comparisonPrSearch"
                            value="{{ $selectedPrOption['label'] ?? '' }}"
                            placeholder="{{ __('purchasing.copy.type_a_pr_number_or_period') }}"
                            autocomplete="off">
                    <x-ui.icon-button
                        icon="x"
                        :label="__('purchasing.copy.clear_pr_selection')"
                        size="sm"
                        id="comparisonPrClear"
                        class="tw-rounded-none {{ $selectedPrOption ? '' : 'd-none' }}"
                    />
                </div>
                <div class="list-group position-absolute w-100 shadow-sm d-none tw-z-[1050] tw-max-h-[260px] tw-overflow-y-auto"
                     id="comparisonPrSuggestions"></div>
            </div>
            <div class="form-text">{{ __('purchasing.copy.type_to_display_pr_options_then_select_one_option') }}</div>
        </div>
        <div>
            <x-ui.button type="submit" size="sm" class="tw-w-full md:tw-w-auto">
                <x-slot:leading><x-ui.icon name="search" /></x-slot:leading>
                {{ __('purchasing.copy.compare') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.toolbar>

@if($comparison)
    @php
        $supplierTotals = [];
        $supplierWins = [];
        foreach($comparison['suppliers'] as $sup) {
            $supplierTotals[$sup['quotation_id']] = ['name' => $sup['name'], 'total' => 0];
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
                    if($minIdr && $p['price_idr'] <= $minIdr) {
                        $supplierWins[$sup['quotation_id']]++;
                    }
                }
            }
        }
        unset($row);

        $validTotals = array_filter($supplierTotals, fn($v) => $v['total'] > 0);
        $recommendedSupId = null;
        if(count($validTotals) > 0) {
            $minTotal = min(array_column($validTotals, 'total'));
            foreach($validTotals as $qid => $data) {
                if($data['total'] == $minTotal) {
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
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col" class="text-end">{{ __('purchasing.copy.estimated_total') }}</th>
                        <th scope="col" class="text-center">{{ __('purchasing.copy.lowest_price_items') }}</th>
                        <th scope="col">{{ __('purchasing.copy.position') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($validTotals as $qid => $data)
                        <tr>
                            <td class="fw-semibold tw-text-on-surface">{{ $data['name'] }}</td>
                            <td class="text-end fw-bold ui-tabular-nums {{ $recommendedSupId === $qid ? 'text-success' : 'text-primary' }}">
                                Rp {{ \App\Support\NumberFormat::maxDecimals($data['total']) }}
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
    <x-ui.card :title="__('purchasing.copy.price_comparison_chart_per_material_idr_kg')">
        <x-slot:actions>
            <div class="tw-min-w-60">
                <label class="form-label small fw-bold mb-1" for="comparisonMaterialFilter">{{ __('purchasing.copy.material') }}</label>
                <select class="form-select form-select-sm" id="comparisonMaterialFilter">
                    <option value="">{{ __('purchasing.copy.all_material') }}</option>
                    @foreach($materialOptions as $material)
                        <option value="{{ $material->id }}">{{ $material->material_name }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot:actions>
        <div class="tw-min-h-[280px]">
            <canvas id="comparisonChart" height="280" role="img" aria-label="{{ __('purchasing.copy.supplier_quotation_price_comparison') }}">{{ __('purchasing.copy.supplier_quotation_price_comparison_chart') }}</canvas>
        </div>
    </x-ui.card>

    {{-- Item-Level Selection Coverage Banner --}}
    @if($awardCoverage)
        <div class="tw-p-4 tw-rounded tw-border tw-border-outline-variant tw-bg-surface-container tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3">
            <div class="tw-flex tw-items-center tw-gap-3">
                <div class="tw-rounded-full tw-p-2.5 tw-bg-primary-container tw-text-primary">
                    <x-ui.icon name="clipboard-check" size="md" />
                </div>
                <div>
                    <div class="fw-bold tw-text-ui-sm tw-text-on-surface">{{ __('purchasing.copy.item_selection_status') }}</div>
                    <div class="tw-text-ui-xs tw-text-on-surface-variant">
                        {{ trans_choice('purchasing.page.selection_coverage', $awardCoverage['total_items'], ['awarded' => $awardCoverage['awarded_items'], 'total' => $awardCoverage['total_items'], 'percentage' => $awardCoverage['coverage_percentage']]) }}
                    </div>
                </div>
            </div>
            <div class="tw-flex tw-items-center tw-gap-2">
                @if($awardCoverage['is_fully_awarded'])
                    <span class="ui-status-chip ui-status-chip--success">{{ __('purchasing.copy.100_fully_selected') }}</span>
                @else
                    <span class="ui-status-chip ui-status-chip--warning">{{ trans_choice('purchasing.page.unselected_count', $awardCoverage['unawarded_items']) }}</span>
                @endif
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('purchasing.comparison.save-awards') }}" id="itemAwardForm" class="tw-min-w-0 tw-max-w-full">
        @csrf
        <input type="hidden" name="pr_id" value="{{ $selectedPr->getRouteKey() }}">

        {{-- Side-by-side comparison table --}}
        <x-ui.data-table :title="__('purchasing.page.comparison_title', ['pr' => $selectedPr->pr_number])" :description="__('purchasing.copy.select_supplier_offers_per_pr_item_maximum_one_offer_per_item')" class="tw-min-w-0 tw-max-w-full">
                <table class="table table-bordered table-hover align-middle mb-0 tw-text-ui-xs comparison-matrix-table">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" rowspan="2" class="align-middle col-sticky-material">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-qty text-center">{{ __('purchasing.copy.qty') }}</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-weight text-center">{{ __('purchasing.copy.weight_unit_kg') }}</th>
                            <th scope="col" rowspan="2" class="align-middle col-ref-total-weight text-center">{{ __('purchasing.copy.total_weight_kg') }}</th>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="colgroup" colspan="3" class="text-center col-supplier-group">
                                    <div class="fw-bold tw-text-on-surface">{{ $sup['name'] }}</div>
                                    <div class="tw-mt-1">
                                        <x-ui.status-chip :tone="$sup['status'] === 'accepted' ? 'success' : ($sup['status'] === 'rejected' ? 'error' : 'info')" size="sm">
                                            {{ \App\Support\StatusHelper::quotationLabel($sup['status']) }}
                                        </x-ui.status-chip>
                                    </div>
                                    <div class="tw-mt-1.5 tw-text-ui-xs tw-text-on-surface-variant" title="{{ __('purchasing.copy.supplier_original_estimated_ready_dispatch_date') }}">
                                        <span class="fw-semibold">{{ __('purchasing.copy.ready_dispatch') }}</span>
                                        <span class="tw-text-on-surface">{{ $sup['estimated_delivery_formatted'] ?? '-' }}</span>
                                    </div>
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
                            $formatInteger = fn ($value) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->number(number_format((float) $value, 0, ',', '.'), 'indonesian') : number_format((float) $value, 0, ',', '.')) : '-';
                        @endphp
                        @foreach($comparison['matrix'] as $row)
                            @php
                                $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
                                $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
                            @endphp
                            <tr data-comparison-row data-material-id="{{ $row['item']->id }}" class="{{ ($row['spread_pct'] ?? 0) > 15 ? 'bg-warning bg-opacity-10' : '' }}">
                                <td class="fw-medium col-sticky-material">
                                    <div class="tw-font-semibold tw-text-on-surface">{{ $row['item']->material_name }}</div>
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
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" :label="__('purchasing.copy.open_quotation_details')" size="sm" class="tw-ms-1" />
                                            @endif
                                        </td>
                                    @elseif($p && $p['price_per_kg'])
                                        <td class="text-end">
                                            {{ $formatNumber($p['price_per_kg'], 4) }}
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                                {{ __('common.fields.qty') }} {{ $p['available_qty'] ?? '-' }} · {{ $formatNumber($p['offered_total_weight']) }} kg
                                                @if($p['is_estimated_weight']) · {{ __('purchasing.copy.est_weight') }} @endif
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold {{ ($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr) ? 'text-success bg-success bg-opacity-10' : '' }}">
                                            Rp {{ $formatNumber($p['price_idr']) }}
                                            @if($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr)
                                                <x-ui.icon name="circle-check" class="ms-1" />
                                            @endif
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" :label="__('purchasing.copy.open_quotation_details')" size="sm" class="tw-ms-1" />
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold ui-tabular-nums">
                                            {{ $formatNumber($p['offer_amount']) }} {{ $p['currency'] }}
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">Rp {{ $formatNumber($p['offer_amount_idr']) }}</div>
                                            @if(!empty($p['is_selectable']))
                                                <div class="tw-mt-2 tw-pt-1.5 tw-border-t tw-border-outline-variant text-center">
                                                    <label class="tw-inline-flex tw-items-center tw-gap-1.5 tw-cursor-pointer">
                                                        <input type="radio"
                                                               name="awards[{{ $row['item']->id }}]"
                                                               value="{{ $p['quotation_item_id'] }}"
                                                               {{ !empty($p['is_awarded']) ? 'checked' : '' }}
                                                               class="form-check-input tw-mt-0 award-radio"
                                                               data-pr-item-id="{{ $row['item']->id }}"
                                                               data-pr-item-name="{{ $row['item']->material_name }}"
                                                               data-supplier-id="{{ $sup['id'] }}"
                                                               data-supplier-name="{{ $sup['name'] }}"
                                                               data-supplier-estimated-ready="{{ $sup['estimated_delivery'] ?? '' }}"
                                                               data-price="Rp {{ \App\Support\NumberFormat::maxDecimals($p['price_idr'] ?? 0) }}"
                                                        >
                                                        <span class="tw-text-ui-xs fw-bold {{ !empty($p['is_awarded']) ? 'text-success' : 'text-primary' }}">
                                                            {{ !empty($p['is_awarded']) ? __('purchasing.copy.selected_offer') : __('purchasing.copy.select_offer') }}
                                                        </span>
                                                    </label>
                                                </div>
                                            @elseif(!empty($p['is_awarded']))
                                                <div class="tw-mt-2 tw-pt-1.5 tw-border-t tw-border-outline-variant text-center">
                                                    <span class="ui-status-chip ui-status-chip--success">
                                                        <x-ui.icon name="check" size="sm" /> {{ __('purchasing.copy.po_created') }}
                                                    </span>
                                                    @if(!empty($p['purchase_order_url']))
                                                        <a href="{{ $p['purchase_order_url'] }}" class="d-block tw-mt-1 tw-text-ui-xs text-primary fw-semibold text-decoration-underline">
                                                            {{ $p['purchase_order_number'] ?? __('purchasing.copy.view_purchase_order') }}
                                                        </a>
                                                    @else
                                                        <span class="d-block tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant">{{ __('purchasing.copy.purchase_order_assigned') }}</span>
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

        @if($hasActionableAwardSelections)
            {{-- Existing PO assignments remain visible while unassigned items stay actionable. --}}
            @if($assignedPurchaseOrders->isNotEmpty() || $assignedPurchaseOrderCount > 0)
                <x-ui.card :title="__('purchasing.copy.existing_purchase_order_s')" class="tw-mt-4">
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        @forelse($assignedPurchaseOrders as $assignedPurchaseOrder)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $assignedPurchaseOrder) }}" class="ui-status-chip ui-status-chip--success text-decoration-none">
                                <x-ui.icon name="receipt" size="sm" /> {{ $assignedPurchaseOrder->po_number }}
                            </a>
                        @empty
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('purchasing.copy.purchase_order_assignment_is_recorded_but_its_detail_is_unavailable') }}</span>
                        @endforelse
                    </div>
                </x-ui.card>
            @endif

            {{-- Offer Selection & PO Grouping Preview Card --}}
            <x-ui.card :title="__('purchasing.copy.offer_selection_po_grouping_preview')" class="tw-mt-4">
                <div class="tw-grid tw-gap-4 md:tw-grid-cols-2">
                    <div>
                        <div class="fw-bold tw-text-ui-sm tw-text-on-surface tw-mb-2">
                            <x-ui.icon name="check-check" size="sm" class="me-1 text-primary" />
                            {{ __('purchasing.copy.selected_line_item_offers') }}
                        </div>
                        <div id="awardPreviewItems" class="tw-border tw-border-outline-variant tw-rounded tw-p-3 tw-bg-surface tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">{{ __('purchasing.copy.select_offers_in_the_table_above_to_preview_selection_and_supplier_po_groups') }}</span>
                        </div>
                    </div>
                    <div>
                        <div class="fw-bold tw-text-ui-sm tw-text-on-surface tw-mb-2">
                            <x-ui.icon name="building" size="sm" class="me-1 text-primary" />
                            {{ __('purchasing.copy.resulting_po_grouping_preview_1_po_per_selected_supplier') }}
                        </div>
                        <div id="supplierGroupPreview" class="tw-border tw-border-outline-variant tw-rounded tw-p-3 tw-bg-surface-container tw-text-ui-xs">
                            <span class="tw-text-on-surface-variant">{{ __('purchasing.copy.1_po_will_be_created_per_selected_supplier_group_upon_confirmation') }}</span>
                        </div>
                    </div>
                </div>

                <div class="tw-mt-4 tw-pt-4 tw-border-t tw-border-outline-variant tw-grid tw-gap-3 sm:tw-grid-cols-2">
                    <div>
                        <x-ui.date-picker
                            id="poEstimatedArrival"
                            name="estimated_arrival"
                            :label="__('purchasing.copy.po_target_arrival_date')"
                            :value="now()->addDays(14)->format('Y-m-d')"
                        />
                        <div class="form-text tw-text-ui-xs tw-text-on-surface-variant tw-mt-1">
                            <span class="fw-semibold">{{ __('purchasing.copy.supplier_ready_dispatch_date') }}</span> {{ __('purchasing.copy.when_supplier_expects_material_ready_to_send') }}<br>
                            <span class="fw-semibold">{{ __('purchasing.copy.po_target_arrival_date_21ee07') }}</span> {{ __('purchasing.copy.when_purchasing_expects_material_at_adsi') }}
                        </div>
                        <div id="targetArrivalWarning" class="alert alert-warning py-1.5 px-2.5 tw-text-ui-xs tw-mt-2 d-none" role="alert">
                            <x-ui.icon name="triangle-alert" size="sm" class="me-1 text-warning" />
                            <span>{{ __('purchasing.copy.po_target_arrival_date_is_earlier_than_the_selected_supplier_s_estimated_ready_dispatch_date_review') }}</span>
                        </div>
                    </div>
                    <div>
                        <label for="poNotes" class="form-label small fw-semibold">{{ __('purchasing.copy.po_notes_remarks') }}</label>
                        <input type="text" name="notes" id="poNotes" class="form-control form-control-sm" placeholder="{{ __('purchasing.copy.optional_notes_for_generated_po_s') }}">
                    </div>
                </div>

                <x-slot:actions>
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        <x-ui.button type="submit" name="action" value="save" variant="outline" size="sm">
                            <x-slot:leading><x-ui.icon name="save" size="sm" /></x-slot:leading>
                            {{ __('purchasing.copy.save_selected_offers') }}
                        </x-ui.button>
                        <x-ui.button type="submit" name="action" value="generate_pos" variant="primary" size="sm" id="btnGeneratePos">
                            <x-slot:leading><x-ui.icon name="receipt" size="sm" /></x-slot:leading>
                            {{ __('purchasing.audit_ui.confirm_generation') }}
                        </x-ui.button>
                    </div>
                </x-slot:actions>
            </x-ui.card>
        @elseif($allItemsAssignedToPurchaseOrder)
            <x-ui.card :title="__('purchasing.copy.purchase_order_s_already_generated')" class="tw-mt-4">
                <div class="tw-flex tw-flex-col tw-gap-3">
                    <div class="tw-text-ui-sm tw-text-on-surface-variant">
                        {{ __('purchasing.copy.all_pr_items_have_been_assigned_to_purchase_order_s_no_additional_selection_or_po_action_is_required') }}
                    </div>
                    <div class="tw-flex tw-flex-wrap tw-gap-2">
                        @forelse($assignedPurchaseOrders as $assignedPurchaseOrder)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $assignedPurchaseOrder) }}" class="ui-status-chip ui-status-chip--success text-decoration-none">
                                <x-ui.icon name="receipt" size="sm" /> {{ $assignedPurchaseOrder->po_number }}
                            </a>
                        @empty
                            <span class="tw-text-ui-xs tw-text-on-surface-variant">{{ __('purchasing.copy.purchase_order_assignment_is_recorded_but_its_detail_is_unavailable') }}</span>
                        @endforelse
                    </div>
                </div>
            </x-ui.card>
        @else
            <x-ui.card :title="__('purchasing.copy.item_selection_status')" class="tw-mt-4">
                <div class="tw-text-ui-sm tw-text-on-surface-variant">{{ __('purchasing.copy.no_additional_offer_selections_are_currently_available_for_this_pr') }}</div>
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

<script id="interSupplierConfig" type="application/json">
{
    "eligiblePrOptions": @json($eligiblePrOptions),
    "chartData": @json($chartData),
    "chartMaterialIds": @json($chartMaterialIds)
}
</script>
