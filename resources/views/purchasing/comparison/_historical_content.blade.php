@php
    $formatNumber = fn ($value, $decimals = 2) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, $decimals), 'decimal') : \App\Support\NumberFormat::maxDecimals($value, $decimals)) : '-';
    $formatPrice = fn ($value, $decimals = 2) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->groupedDecimal($value, $decimals, 'indonesian') : \App\Support\NumberFormat::maxDecimals($value, $decimals)) : '-';

    $formatPct = function ($value) use ($formatNumber) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . $formatNumber($value) . '%';
    };

    $changeBadge = function ($value) use ($formatNumber) {
        if ($value === null) {
            return '<span class="tw:text-on-surface-variant">-</span>';
        }

        if ($value > 0) {
            return '<span class="tw:font-semibold tw:text-error">+' . $formatNumber($value) . '%</span>';
        }

        if ($value < 0) {
            return '<span class="tw:font-semibold tw:text-success">&minus;' . $formatNumber(abs($value)) . '%</span>';
        }

        return '<span class="tw:font-semibold tw:text-on-surface-variant">0%</span>';
    };
@endphp

@include('purchasing.comparison._evaluation_header', ['evaluation' => $evaluation, 'route' => 'purchasing.comparison.historical'])

<x-ui.toolbar class="tw:mb-0">
    <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="tw:grid tw:w-full tw:gap-3 tw:md:grid-cols-2 tw:xl:grid-cols-12 tw:xl:items-end" id="historicalFilterForm" data-server-tabs-form data-managed-submit>
        <input type="hidden" name="currency" value="{{ $currency }}">
        <div class="tw:xl:col-span-3">
            <label class="form-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface" for="historicalSupplierSelect">{{ __('purchasing.copy.supplier') }}</label>
            <select name="supplier_id" class="form-select form-select-sm" id="historicalSupplierSelect">
                <option value="">{{ __('purchasing.comparison_analytics.all_suppliers') }}</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->getRouteKey() }}" {{ $selectedSupplierId === $supplier->getRouteKey() ? 'selected' : '' }}>{{ trim((string) $supplier->supplier?->company_name) ?: $supplier->name }}</option>
                @endforeach
            </select>
        </div>
        <div class="tw:xl:col-span-3">
            <label class="form-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface" for="historicalMaterialSelect">{{ __('purchasing.copy.material') }}</label>
            {{-- PUR-07: one option per (material, shape); the hidden input carries the shape. --}}
            <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required>
                <option value="">{{ __('purchasing.copy.select_material') }}</option>
                @foreach($materials as $material)
                    <option value="{{ $material['name'] }}" data-shape="{{ $material['shape'] ?? '' }}" data-key-options='@json($material['key_options'])' @selected($selectedMaterialName === $material['name'] && ($selectedShape ?? null) === $material['shape'])>{{ $material['label'] }}</option>
                @endforeach
            </select>
            <input type="hidden" name="shape" id="historicalShapeInput" value="{{ $selectedShape ?? '' }}">
        </div>
        <div class="tw:xl:col-span-2">
            <label class="form-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface" for="historicalRangeSelect">{{ __('purchasing.copy.time_period') }}</label>
            <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                @foreach($rangeOptions as $value => $label)
                    <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <fieldset class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0 tw:xl:col-span-2">
            <legend class="form-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('purchasing.copy.aggregation') }}</legend>
            <div class="btn-group btn-group-sm w-100" role="group" aria-label="{{ __('purchasing.copy.historical_price_aggregation_interval') }}">
                <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                <label class="btn btn-outline-primary" for="periodViewMonthly">{{ __('purchasing.copy.monthly') }}</label>

                <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                <label class="btn btn-outline-primary" for="periodViewYearly">{{ __('purchasing.copy.yearly') }}</label>
            </div>
        </fieldset>

        <div class="tw:flex tw:xl:col-span-2">
            <x-ui.button type="submit" size="sm" class="tw:w-full tw:justify-center">
                <x-slot:leading><x-ui.icon name="search" /></x-slot:leading>
                {{ __('purchasing.copy.apply_filters') }}
            </x-ui.button>
        </div>

        {{-- Mission 07 (decisions 17-18): one key dimension per shape, exact or inclusive range. --}}
        @php
            $pca = 'purchasing.comparison_analytics';
            $keyLabels = ['Flat' => __($pca.'.key_thickness'), 'Round' => __($pca.'.key_diameter'), 'Hollow' => __($pca.'.key_d_outer')];
            $selectedKeyOptions = collect($materials)->first(fn ($m) => $m['name'] === $selectedMaterialName && $m['shape'] === ($selectedShape ?? null))['key_options'] ?? [];
            $exactKey = $keyDimension['min'] !== null && $keyDimension['min'] === $keyDimension['max'] ? $keyDimension['min'] : null;
        @endphp
        {{-- Hidden when the material has no shape: no key dimension can be derived. --}}
        <fieldset class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0 tw:md:col-span-2 tw:xl:col-span-12" id="historicalKeyDimension"
                  data-label-flat="{{ $keyLabels['Flat'] }}"
                  data-label-round="{{ $keyLabels['Round'] }}"
                  data-label-hollow="{{ $keyLabels['Hollow'] }}"
                  @if($keyDimension['field'] === null && $selectedMaterialName) hidden @endif>
            <legend class="form-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface" id="historicalKeyDimensionLabel">
                {{ $keyLabels[$selectedShape ?? ''] ?? $keyLabels['Flat'] }}
            </legend>
            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2">
                {{-- PUR-07: recorded values write into key_min/key_max; this control is never submitted. --}}
                <div>
                    <label class="form-label tw:mb-1 tw:text-ui-xs tw:text-on-surface-variant" for="historicalKeyOption">{{ __($pca.'.key_options') }}</label>
                    <select id="historicalKeyOption" class="form-select form-select-sm"
                            data-label-all="{{ __($pca.'.key_option_all') }}"
                            data-label-custom="{{ __($pca.'.key_option_custom') }}"
                            data-label-none="{{ __($pca.'.key_option_none') }}"
                            @disabled($selectedMaterialName && $selectedKeyOptions === [])>
                        @if($selectedMaterialName && $selectedKeyOptions === [])
                            <option value="">{{ __($pca.'.key_option_none') }}</option>
                        @else
                            <option value="">{{ __($pca.'.key_option_all') }}</option>
                            @if($keyDimension['min'] !== null && ! in_array($exactKey, $selectedKeyOptions, true))
                                <option value="__custom" selected>{{ __($pca.'.key_option_custom') }}</option>
                            @endif
                            @foreach($selectedKeyOptions as $value)
                                <option value="{{ $value }}" @selected($exactKey === $value)>{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, 4), 'decimal') }}</option>
                            @endforeach
                        @endif
                    </select>
                </div>
                <div>
                    <label class="form-label tw:mb-1 tw:text-ui-xs tw:text-on-surface-variant" for="historicalKeyMin">{{ __('purchasing.comparison_analytics.key_from') }}</label>
                    <input type="number" step="0.0001" min="0" name="key_min" id="historicalKeyMin" class="form-control form-control-sm historical-filter-input" value="{{ $keyDimension['min'] }}">
                </div>
                <div>
                    <label class="form-label tw:mb-1 tw:text-ui-xs tw:text-on-surface-variant" for="historicalKeyMax">{{ __('purchasing.comparison_analytics.key_to') }}</label>
                    <input type="number" step="0.0001" min="0" name="key_max" id="historicalKeyMax" class="form-control form-control-sm historical-filter-input" value="{{ $keyDimension['max'] }}">
                </div>
                <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __('purchasing.comparison_analytics.key_hint') }}</p>
            </div>
        </fieldset>
    </form>
</x-ui.toolbar>

<div id="historicalResults" class="tw:grid tw:gap-4" aria-live="polite" aria-busy="false">
@if($chartData)
    <section class="tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface" aria-labelledby="historicalChartTitle">
        <header class="tw:border-b tw:border-outline-variant tw:bg-surface-container tw:px-4 tw:py-3">
            <h2 class="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:text-ui-sm tw:font-semibold tw:text-on-surface" id="historicalChartTitle">
                <x-ui.icon name="chart-no-axes-combined" class="tw:text-primary" />
                <span>{{ __('common.final_review.price_trend', ['material' => $selectedMaterialName, 'supplier' => ($payload['supplierName'] ?? null) ?: __('purchasing.comparison_analytics.all_suppliers')]) }}</span>
            </h2>
        </header>
        <div class="tw:h-72 tw:p-4">
            <canvas id="historicalChart" role="img" aria-label="{{ __('purchasing.copy.historical_material_price_trend') }}">{{ __('purchasing.copy.historical_material_price_trend_chart') }}</canvas>
        </div>
    </section>

    <section class="tw:grid tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface-container tw:sm:grid-cols-2 tw:sm:divide-x tw:sm:divide-outline-variant" id="historicalSummary" aria-label="{{ __('purchasing.copy.historical_price_summary') }}">
        <div class="tw:p-4">
            <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __('purchasing.copy.average_change_per_period') }}</div>
            <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold {{ ($summary['average_change_pct'] ?? null) > 0 ? 'tw:text-error' : ((($summary['average_change_pct'] ?? null) < 0) ? 'tw:text-success' : 'tw:text-on-surface-variant') }}" id="averageChangeValue">
                {{ $formatPct($summary['average_change_pct'] ?? null) }}
            </div>
        </div>
        <div class="tw:border-t tw:border-outline-variant tw:p-4 tw:sm:border-t-0">
            <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __('purchasing.copy.total_change_initial_to_latest') }}</div>
            <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold {{ ($summary['total_change_pct'] ?? null) > 0 ? 'tw:text-error' : ((($summary['total_change_pct'] ?? null) < 0) ? 'tw:text-success' : 'tw:text-on-surface-variant') }}" id="totalChangeValue">
                {{ $formatPct($summary['total_change_pct'] ?? null) }}
            </div>
        </div>
        {{-- Mission 07: weighted average over ordered KG (12.3) --}}
        <div class="tw:border-t tw:border-outline-variant tw:p-4 tw:sm:col-span-2" id="historicalWeightedSummary">
            <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __('purchasing.comparison_analytics.historical_average') }}</div>
            <div class="ui-tabular-nums tw:mt-1 tw:text-ui-xl tw:font-semibold tw:text-on-surface" id="historicalWeightedAverage">{{ ($summary['weighted_average'] ?? null) !== null ? $currency.' '.$formatPrice($summary['weighted_average'], 4) : '—' }}</div>
            <div class="tw:text-ui-xs tw:text-on-surface-variant" id="historicalWeightedBasis">{{ __('purchasing.comparison_analytics.history_basis', ['pos' => $summary['po_count'] ?? 0, 'kg' => $formatNumber($summary['total_kg'] ?? 0)]) }} · {{ __('purchasing.comparison_analytics.historical_best') }}: {{ ($summary['best'] ?? null) !== null ? $currency.' '.$formatNumber($summary['best'], 4) : '—' }}</div>
            <div class="tw:text-ui-xs tw:text-warning {{ ($summary['excluded']['lines'] ?? 0) > 0 ? '' : 'd-none' }}" id="historicalExcludedNote">{{ ($summary['excluded']['lines'] ?? 0) > 0 ? __('purchasing.comparison_analytics.excluded_note', ['lines' => $summary['excluded']['lines'], 'kg' => $formatNumber($summary['excluded']['kg']), 'currencies' => implode(', ', $summary['excluded']['currencies'])]) : '' }}</div>
        </div>
    </section>

    <x-ui.data-table sticky :title="__('purchasing.copy.supporting_data')" :description="__('purchasing.comparison_analytics.supporting_data_description')">
        <table class="table table-hover align-middle mb-0 tw:text-ui-sm">
            <thead class="table-light text-center" id="historicalTableHead">
                @if($periodView === 'yearly')
                    <tr>
                        <th scope="col" class="ui-sticky-col">{{ __('purchasing.copy.year') }}</th>
                        <th scope="col">{{ __('purchasing.comparison_analytics.average_per_kg', ['currency' => $currency]) }}</th>
                        <th scope="col">{{ __('purchasing.copy.lowest_price') }}</th>
                        <th scope="col">{{ __('purchasing.copy.highest_price') }}</th>
                        <th scope="col">{{ __('purchasing.copy.change_from_previous_period') }}</th>
                    </tr>
                @else
                    <tr>
                        <th scope="col" class="ui-sticky-col">{{ __('purchasing.copy.pr_number') }}</th>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col">{{ __('purchasing.copy.price_kg') }}</th>
                        <th scope="col">{{ __('purchasing.comparison_analytics.converted_total', ['currency' => $currency]) }}</th>
                        <th scope="col">{{ __('purchasing.copy.po_date') }}</th>
                        <th scope="col">{{ __('purchasing.copy.change') }}</th>
                    </tr>
                @endif
            </thead>
            <tbody id="historicalTableBody">
                @foreach($tableData as $row)
                    @if($periodView === 'yearly')
                        <tr>
                            <td class="text-center fw-medium ui-sticky-col">{{ $row['period'] }}</td>
                            <td class="text-end text-primary fw-bold ui-tabular-nums">{{ $currency }} {{ $formatPrice($row['price_idr'], 4) }}</td>
                            <td class="text-end ui-tabular-nums">{{ $currency }} {{ $formatPrice($row['min_idr'], 4) }}</td>
                            <td class="text-end ui-tabular-nums">{{ $currency }} {{ $formatPrice($row['max_idr'], 4) }}</td>
                            <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                        </tr>
                    @else
                        <tr>
                            <td class="text-center fw-medium ui-sticky-col">
                                @if(!empty($row['pr_id']) && !empty($row['pr_url']))
                                    <a href="{{ $row['pr_url'] }}" class="text-primary text-decoration-none tw:hover:underline">
                                        {{ $row['pr_number'] }}
                                        <x-ui.icon name="arrow-right" class="ms-1 tw:text-ui-sm" />
                                    </a>
                                @else
                                    {{ $row['pr_number'] ?? '-' }}
                                @endif
                            </td>
                            <td class="text-center">{{ $row['supplier'] ?? '-' }}</td>
                            <td class="text-end ui-tabular-nums">
                                {{ $formatPrice($row['price_per_kg'], 4) }}
                                <span class="ui-status-chip ui-status-chip--neutral tw:ms-1">{{ $row['currency'] }}</span>
                            </td>
                            <td class="text-end text-primary fw-bold ui-tabular-nums">{{ $row['total_idr'] !== null ? $currency.' '.$formatPrice($row['total_idr']) : __('purchasing.comparison_analytics.rate_unavailable') }}</td>
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
            class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-t tw:border-outline-variant tw:px-4 tw:py-3 {{ $tablePagination ? '' : 'd-none' }}"
        >
            <span id="historicalPaginationSummary" class="tw:text-ui-xs tw:text-on-surface-variant">
                @if($tablePagination)
                    {{ __('purchasing.audit_ui.pagination_summary', ['from' => $tablePagination['from'] ?? 0, 'to' => $tablePagination['to'] ?? 0, 'total' => $tablePagination['total']]) }}
                @endif
            </span>
            <div class="tw:flex tw:items-center tw:gap-2">
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
                <span id="historicalPaginationPage" class="tw:min-w-20 tw:text-center tw:text-ui-xs tw:font-semibold tw:text-on-surface">
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
@elseif($selectedMaterialName)
    <x-ui.alert tone="warning" :title="__('purchasing.copy.no_matching_price_history')">{{ __('purchasing.copy.no_quotation_data_was_found_for_this_supplier_and_material_combination') }}</x-ui.alert>
@else
    <div class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface">
        <x-ui.empty-state icon="chart-no-axes-combined" :title="__('purchasing.comparison_analytics.select_material')" :description="__('purchasing.copy.choose_the_primary_filters_above_to_review_the_historical_price_trend')" />
    </div>
@endif
</div>

<script id="historicalConfig" type="application/json">
{
    "chartData": @json($chartData),
    "currency": @json($currency),
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
