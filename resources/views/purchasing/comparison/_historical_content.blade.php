@php
    $formatNumber = fn ($value, $decimals = 2) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, $decimals), 'decimal') : \App\Support\NumberFormat::maxDecimals($value, $decimals)) : '-';

    $formatPct = function ($value) use ($formatNumber) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . $formatNumber($value) . '%';
    };

    $changeBadge = function ($value) use ($formatNumber) {
        if ($value === null) {
            return '<span class="tw-text-on-surface-variant">-</span>';
        }

        if ($value > 0) {
            return '<span class="tw-font-semibold tw-text-error">+' . $formatNumber($value) . '%</span>';
        }

        if ($value < 0) {
            return '<span class="tw-font-semibold tw-text-success">&minus;' . $formatNumber(abs($value)) . '%</span>';
        }

        return '<span class="tw-font-semibold tw-text-on-surface-variant">0%</span>';
    };
@endphp

<x-ui.toolbar class="tw-mb-0">
    <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-12 xl:tw-items-end" id="historicalFilterForm" data-server-tabs-form data-managed-submit>
        <div class="xl:tw-col-span-3">
            <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="historicalSupplierSelect">{{ __('purchasing.copy.supplier') }}</label>
            <select name="supplier_id" class="form-select form-select-sm" id="historicalSupplierSelect" required>
                <option value="">{{ __('purchasing.copy.select_supplier') }}</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->getRouteKey() }}" {{ $selectedSupplierId === $supplier->getRouteKey() ? 'selected' : '' }}>{{ $supplier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="xl:tw-col-span-3">
            <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="historicalMaterialSelect">{{ __('purchasing.copy.material') }}</label>
            <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required {{ $selectedSupplierId ? '' : 'disabled' }}>
                <option value="">{{ $selectedSupplierId ? __('purchasing.copy.select_material') : __('purchasing.copy.select_supplier_first') }}</option>
                @foreach($materials as $material)
                    <option value="{{ $material['name'] }}" data-shape="{{ $material['shape'] ?? '' }}" {{ $selectedMaterialName === $material['name'] ? 'selected' : '' }}>{{ $material['name'] }}</option>
                @endforeach
            </select>
        </div>
        <div class="xl:tw-col-span-2">
            <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="historicalRangeSelect">{{ __('purchasing.copy.time_period') }}</label>
            <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                @foreach($rangeOptions as $value => $label)
                    <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <fieldset class="tw-m-0 tw-min-w-0 tw-border-0 tw-p-0 xl:tw-col-span-2">
            <legend class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface">{{ __('purchasing.copy.aggregation') }}</legend>
            <div class="btn-group btn-group-sm w-100" role="group" aria-label="{{ __('purchasing.copy.historical_price_aggregation_interval') }}">
                <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                <label class="btn btn-outline-primary" for="periodViewMonthly">{{ __('purchasing.copy.monthly') }}</label>

                <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                <label class="btn btn-outline-primary" for="periodViewYearly">{{ __('purchasing.copy.yearly') }}</label>
            </div>
        </fieldset>

        <div class="tw-flex xl:tw-col-span-2">
            <x-ui.button type="submit" size="sm" class="tw-w-full tw-justify-center">
                <x-slot:leading><x-ui.icon name="search" /></x-slot:leading>
                {{ __('purchasing.copy.apply_filters') }}
            </x-ui.button>
        </div>

        <div class="md:tw-col-span-2 xl:tw-col-span-12">
            <a
                href="#dimensionFilters"
                data-bs-toggle="collapse"
                class="ui-focus-ring tw-inline-flex tw-items-center tw-gap-1.5 tw-rounded-ui-xs tw-text-ui-xs tw-font-semibold tw-text-primary tw-no-underline"
                role="button"
                aria-expanded="{{ request()->hasAny(['thickness', 'd_inner', 'd_outer', 'width', 'length']) ? 'true' : 'false' }}"
                aria-controls="dimensionFilters"
            >
                <x-ui.icon name="sliders-horizontal" /> {{ __('purchasing.copy.more_filters') }}
            </a>
        </div>
        <div class="collapse md:tw-col-span-2 xl:tw-col-span-12 {{ request()->hasAny(['thickness', 'd_inner', 'd_outer', 'width', 'length']) ? 'show' : '' }}" id="dimensionFilters">
            <div class="tw-grid tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
                <div class="dimension-field" data-dim="thickness">
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-thickness">{{ __('purchasing.copy.thickness_mm') }}</label>
                    <input type="number" step="0.01" name="thickness" id="purchasing-history-thickness" class="form-control form-control-sm historical-filter-input" value="{{ request('thickness') }}">
                </div>
                <div class="dimension-field" data-dim="d_inner">
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-d-inner">{{ __('purchasing.copy.inner_diam_mm') }}</label>
                    <input type="number" step="0.01" name="d_inner" id="purchasing-history-d-inner" class="form-control form-control-sm historical-filter-input" value="{{ request('d_inner') }}">
                </div>
                <div class="dimension-field" data-dim="d_outer">
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-d-outer">{{ __('purchasing.copy.outer_diam_mm') }}</label>
                    <input type="number" step="0.01" name="d_outer" id="purchasing-history-d-outer" class="form-control form-control-sm historical-filter-input" value="{{ request('d_outer') }}">
                </div>
                <div class="dimension-field" data-dim="width">
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-width">{{ __('purchasing.copy.width_mm') }}</label>
                    <input type="number" step="0.01" name="width" id="purchasing-history-width" class="form-control form-control-sm historical-filter-input" value="{{ request('width') }}">
                </div>
                <div class="dimension-field" data-dim="length">
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-length">{{ __('purchasing.copy.length_mm') }}</label>
                    <input type="number" step="0.01" name="length" id="purchasing-history-length" class="form-control form-control-sm historical-filter-input" value="{{ request('length') }}">
                </div>
            </div>
        </div>
    </form>
</x-ui.toolbar>

<div id="historicalResults" class="tw-grid tw-gap-4" aria-live="polite" aria-busy="false">
@if($chartData)
    <section class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface" aria-labelledby="historicalChartTitle">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3">
            <h2 class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface" id="historicalChartTitle">
                <x-ui.icon name="chart-no-axes-combined" class="tw-text-primary" />
                <span>{{ __('common.final_review.price_trend', ['material' => $selectedMaterialName, 'supplier' => $payload['supplierName'] ?? '']) }}</span>
            </h2>
        </header>
        <div class="tw-h-72 tw-p-4">
            <canvas id="historicalChart" role="img" aria-label="{{ __('purchasing.copy.historical_material_price_trend') }}">{{ __('purchasing.copy.historical_material_price_trend_chart') }}</canvas>
        </div>
    </section>

    <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant" id="historicalSummary" aria-label="{{ __('purchasing.copy.historical_price_summary') }}">
        <div class="tw-p-4">
            <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('purchasing.copy.average_change_per_period') }}</div>
            <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold {{ ($summary['average_change_pct'] ?? null) > 0 ? 'tw-text-error' : ((($summary['average_change_pct'] ?? null) < 0) ? 'tw-text-success' : 'tw-text-on-surface-variant') }}" id="averageChangeValue">
                {{ $formatPct($summary['average_change_pct'] ?? null) }}
            </div>
        </div>
        <div class="tw-border-t tw-border-outline-variant tw-p-4 sm:tw-border-t-0">
            <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('purchasing.copy.total_change_initial_to_latest') }}</div>
            <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold {{ ($summary['total_change_pct'] ?? null) > 0 ? 'tw-text-error' : ((($summary['total_change_pct'] ?? null) < 0) ? 'tw-text-success' : 'tw-text-on-surface-variant') }}" id="totalChangeValue">
                {{ $formatPct($summary['total_change_pct'] ?? null) }}
            </div>
        </div>
    </section>

    <x-ui.data-table :title="__('purchasing.copy.supporting_data')" :description="__('purchasing.copy.quotation_values_and_period_changes_for_the_selected_supplier_and_exact_material_specification')">
        <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
            <thead class="table-light text-center" id="historicalTableHead">
                @if($periodView === 'yearly')
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.year') }}</th>
                        <th scope="col">{{ __('purchasing.copy.average_idr_kg') }}</th>
                        <th scope="col">{{ __('purchasing.copy.lowest_price') }}</th>
                        <th scope="col">{{ __('purchasing.copy.highest_price') }}</th>
                        <th scope="col">{{ __('purchasing.copy.change_from_previous_period') }}</th>
                    </tr>
                @else
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.pr_number') }}</th>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col">{{ __('purchasing.copy.price_kg') }}</th>
                        <th scope="col">{{ __('purchasing.copy.total_price_idr') }}</th>
                        <th scope="col">{{ __('purchasing.copy.po_date') }}</th>
                        <th scope="col">{{ __('purchasing.copy.change') }}</th>
                    </tr>
                @endif
            </thead>
            <tbody id="historicalTableBody">
                @foreach($tableData as $row)
                    @if($periodView === 'yearly')
                        <tr>
                            <td class="text-center fw-medium">{{ $row['period'] }}</td>
                            <td class="text-end text-primary fw-bold ui-tabular-nums">Rp {{ $formatNumber($row['price_idr']) }}</td>
                            <td class="text-end ui-tabular-nums">Rp {{ $formatNumber($row['min_idr']) }}</td>
                            <td class="text-end ui-tabular-nums">Rp {{ $formatNumber($row['max_idr']) }}</td>
                            <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                        </tr>
                    @else
                        <tr>
                            <td class="text-center fw-medium">
                                @if(!empty($row['pr_id']) && !empty($row['pr_url']))
                                    <a href="{{ $row['pr_url'] }}" class="text-primary text-decoration-none hover:tw-underline">
                                        {{ $row['pr_number'] }}
                                        <x-ui.icon name="arrow-right" class="ms-1 tw-text-ui-sm" />
                                    </a>
                                @else
                                    {{ $row['pr_number'] ?? '-' }}
                                @endif
                            </td>
                            <td class="text-center">{{ $row['supplier'] ?? '-' }}</td>
                            <td class="text-end ui-tabular-nums">
                                {{ $formatNumber($row['price_per_kg'], 4) }}
                                <span class="ui-status-chip ui-status-chip--neutral tw-ms-1">{{ $row['currency'] }}</span>
                            </td>
                            <td class="text-end text-primary fw-bold ui-tabular-nums">{{ $row['total_idr'] ? 'Rp '.$formatNumber($row['total_idr']) : '-' }}</td>
                            <td class="text-center">
                                @if(!empty($row['purchase_order_at_display']))
                                    {{ $row['purchase_order_at_display'] }}
                                @else
                                    <span class="ui-status-chip ui-status-chip--neutral">{{ __('purchasing.copy.draft') }}</span>
                                @endif
                            </td>
                            <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
        <div
            id="historicalPagination"
            class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-px-4 tw-py-3 {{ $tablePagination ? '' : 'd-none' }}"
        >
            <span id="historicalPaginationSummary" class="tw-text-ui-xs tw-text-on-surface-variant">
                @if($tablePagination)
                    {{ __('purchasing.audit_ui.pagination_summary', ['from' => $tablePagination['from'] ?? 0, 'to' => $tablePagination['to'] ?? 0, 'total' => $tablePagination['total']]) }}
                @endif
            </span>
            <div class="tw-flex tw-items-center tw-gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    id="historicalPreviousPage"
                    data-history-page="{{ max(1, (int) ($tablePagination['current_page'] ?? 1) - 1) }}"
                    :disabled="!$tablePagination || $tablePagination['current_page'] <= 1"
                >
                    {{ __('purchasing.copy.previous') }}
                </x-ui.button>
                <span id="historicalPaginationPage" class="tw-min-w-20 tw-text-center tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                    @if($tablePagination)
                        {{ __('purchasing.audit_ui.pagination_page', ['current' => $tablePagination['current_page'], 'last' => $tablePagination['last_page']]) }}
                    @endif
                </span>
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    id="historicalNextPage"
                    data-history-page="{{ min((int) ($tablePagination['last_page'] ?? 1), (int) ($tablePagination['current_page'] ?? 1) + 1) }}"
                    :disabled="!$tablePagination || $tablePagination['current_page'] >= $tablePagination['last_page']"
                >
                    {{ __('purchasing.copy.next') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.data-table>
@elseif($selectedSupplierId && $selectedMaterialName)
    <x-ui.alert tone="warning" :title="__('purchasing.copy.no_matching_price_history')">{{ __('purchasing.copy.no_quotation_data_was_found_for_this_supplier_and_material_combination') }}</x-ui.alert>
@else
    <div class="tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface">
        <x-ui.empty-state icon="chart-no-axes-combined" :title="__('purchasing.copy.select_a_supplier_and_material')" :description="__('purchasing.copy.choose_the_primary_filters_above_to_review_the_historical_price_trend')" />
    </div>
@endif
</div>

<script id="historicalConfig" type="application/json">
{
    "chartData": @json($chartData),
    "periodView": @json($periodView),
    "range": @json($range),
    "monthlyRangeOptions": @json($monthlyRangeOptions),
    "yearlyRangeOptions": @json($yearlyRangeOptions),
    "selectedSupplierId": @json($selectedSupplierId),
    "selectedMaterialName": @json($selectedMaterialName),
    "materialsUrl": @json(route('purchasing.comparison.historical.materials')),
    "historicalDataUrl": @json(route('purchasing.comparison.historical'))
}
</script>
