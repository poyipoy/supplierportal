@php
    // Mission 07: amounts are in the selected analysis currency (IDR keeps the Rupiah format).
    $formatRupiah = fn ($value) => $value === null ? '-' : ($currency === 'IDR'
        ? 'Rp ' . (isset($regionalFormatter) ? $regionalFormatter->number(number_format($value, 0, ',', '.'), 'indonesian') : number_format($value, 0, ',', '.'))
        : $currency . ' ' . (isset($regionalFormatter) ? $regionalFormatter->groupedDecimal($value, 4, 'indonesian') : \App\Support\NumberFormat::maxDecimals($value, 4)));
    $formatNumber = fn ($value, $decimals = 1) => $value !== null ? (isset($regionalFormatter) ? $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, $decimals), 'decimal') : \App\Support\NumberFormat::maxDecimals($value, $decimals)) : '-';
@endphp

@include('purchasing.comparison._evaluation_header', ['evaluation' => $evaluation, 'route' => 'purchasing.comparison.vs-best'])

{{-- Filter Toolbar --}}
@php
    $vsBestRawSearch = request()->query('search');
    $vsBestInitialSearch = is_string($vsBestRawSearch) ? trim($vsBestRawSearch) : '';
    $vsBestMaterialOptions = collect([
        ['value' => '', 'label' => __('purchasing.copy.all_material'), 'dataset' => ['material-name' => '', 'shape' => '']],
    ])->merge(
        collect($vsBestMaterials)->map(fn ($option) => [
            'value' => $option['name'].'|'.($option['shape'] ?? ''),
            'label' => $option['label'],
            'dataset' => [
                'material-name' => (string) $option['name'],
                'shape' => (string) ($option['shape'] ?? ''),
            ],
        ])
    )->values()->all();
    $selectedVsBestValue = $selectedVsBestMaterial
        ? $selectedVsBestMaterial['name'].'|'.($selectedVsBestMaterial['shape'] ?? '')
        : '';
    $vsBestResetUrl = route('purchasing.comparison.vs-best', array_filter(['pr_id' => request()->query('pr_id'), 'currency' => request()->query('currency')], 'is_string'));
    if (\App\Support\WorkspaceShell::enabled() && auth()->check() && \App\Support\WorkspaceShell::originatedFromEmbed(request())) {
        $vsBestResetUrl = \App\Support\WorkspaceShell::appendEmbedParam($vsBestResetUrl);
    }
@endphp
<x-ui.toolbar class="tw:mb-0">
    <x-slot:filters>
        <search class="tw:block tw:w-full" aria-label="{{ __('purchasing.comparison_analytics.benchmark_search_region') }}">
            <form method="GET" action="{{ route('purchasing.comparison.vs-best') }}" class="ui-vs-best-filter-grid" id="vsBestFilterForm" data-server-tabs-form>
                <input type="hidden" name="currency" value="{{ $currency }}">

                <div class="ui-vs-best-filter-grid__field ui-vs-best-filter-grid__search">
                    <label for="vsBestTableSearch" class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('purchasing.comparison_analytics.benchmark_search_heading') }}</label>
                    <div class="ui-vs-best-search">
                        <x-ui.icon name="search" size="sm" class="ui-vs-best-search__icon" aria-hidden="true" />
                        <input
                            type="search"
                            id="vsBestTableSearch"
                            name="search"
                            value="{{ $vsBestInitialSearch }}"
                            class="ui-input ui-input--sm ui-vs-best-search__input"
                            placeholder="{{ __('purchasing.comparison_analytics.benchmark_search_placeholder') }}"
                            aria-description="{{ __('purchasing.comparison_analytics.benchmark_search_label') }}"
                            autocomplete="off"
                            autocorrect="off"
                            autocapitalize="none"
                            spellcheck="false"
                            enterkeyhint="search"
                        >
                        <button
                            type="button"
                            id="vsBestTableSearchClear"
                            class="ui-vs-best-search__clear ui-focus-ring"
                            aria-label="{{ __('purchasing.comparison_analytics.clear_search') }}"
                            @if($vsBestInitialSearch === '') hidden @endif
                        >
                            <x-ui.icon name="x" size="xs" />
                        </button>
                    </div>
                </div>

                <div class="ui-vs-best-filter-grid__field">
                    <span class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface" aria-hidden="true">{{ __('purchasing.comparison_analytics.benchmark_period_label') }}</span>
                    <x-ui.date-range-picker
                        id="vsBestMonthRange"
                        granularity="month"
                        start-name="date_from"
                        start-id="vsBestDateFrom"
                        :start-label="__('purchasing.copy.from_month')"
                        :start-value="$dateFromInput"
                        end-name="date_to"
                        end-id="vsBestDateTo"
                        :end-label="__('purchasing.copy.to_month')"
                        :end-value="$dateToInput"
                        compact
                        class="ui-date-range-picker--single-line"
                    />
                </div>

                {{-- PUR-05: material filter applied through AJAX; the date form still submits normally. --}}
                <div class="ui-vs-best-filter-grid__field">
                    <x-ui.searchable-select
                        id="vsBestMaterialSelect"
                        :label="__('purchasing.comparison_analytics.material_filter')"
                        label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                        size="sm"
                        :placeholder="__('purchasing.copy.all_material')"
                        :show-sublabel-on-trigger="false"
                        :value="$selectedVsBestValue"
                        :options="$vsBestMaterialOptions"
                        class="tw:gap-1"
                    />
                </div>

                <div class="ui-vs-best-filter-grid__actions">
                    <x-ui.button type="submit" variant="primary" size="sm" data-calendar-native-submit>
                        <x-ui.icon name="filter" size="sm" />
                        <span>{{ __('purchasing.copy.apply') }}</span>
                    </x-ui.button>
                    <x-ui.button :href="$vsBestResetUrl" variant="ghost" size="sm" data-server-tab data-tab-name="vs-best">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('purchasing.copy.reset') }}</span>
                    </x-ui.button>
                </div>
            </form>
        </search>
    </x-slot:filters>
</x-ui.toolbar>
@if($vsBestMaterialIgnored)
    <p class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant" role="status">{{ __('purchasing.comparison_analytics.filter_ignored') }}</p>
@endif

{{-- Summary Metrics Strip --}}
<div class="tw:grid tw:gap-px tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-outline-variant tw:shadow-ui-surface tw:sm:grid-cols-2 tw:xl:grid-cols-4" aria-label="{{ __('purchasing.copy.historical_benchmark_summary') }}">
    <x-ui.metric-card flat :label="__('purchasing.copy.total_compared_data')" :value="$summary['total_rows']" icon="list-check" tone="neutral" value-id="vsBestTotalRows" />
    <x-ui.metric-card flat :label="__('purchasing.copy.competitive_safe')" :value="$summary['competitive_count']" icon="shield-check" tone="success" value-id="vsBestCompetitiveCount" />
    <x-ui.metric-card flat :label="__('purchasing.copy.above_history')" :value="$summary['above_count']" icon="trending-up" tone="{{ $summary['above_count'] > 0 ? 'warning' : 'neutral' }}" value-id="vsBestAboveCount" />
    <x-ui.metric-card flat :label="__('purchasing.copy.potential_total_difference')" :value="$formatRupiah($summary['total_potential_difference_idr'])" icon="banknote" tone="primary" value-id="vsBestPotentialTotal" />
</div>

<x-ui.data-table :title="__('purchasing.copy.price_benchmark_details')" :description="__('purchasing.copy.server_side_results_are_ordered_by_the_largest_potential_difference')">
    <table class="ui-table ui-table--hover tw:align-middle tw:mb-0 tw:w-full tw:text-ui-sm" id="vsBestTable">
        <thead class="tw:text-center">
            <tr>
                <th scope="col" class="tw:text-start">{{ __('purchasing.copy.material') }}</th>
                <th scope="col" class="tw:text-start">{{ __('purchasing.copy.dimensions') }}</th>
                <th scope="col" class="tw:text-end">{{ __('purchasing.copy.current_price') }}</th>
                <th scope="col" class="tw:text-end">{{ __('purchasing.copy.historical_best_price') }}</th>
                <th scope="col">{{ __('purchasing.comparison_analytics.difference_per_kg', ['currency' => $currency]) }}</th>
                <th scope="col" class="tw:text-end">{{ __('purchasing.copy.potential_total_difference') }}</th>
                <th scope="col">{{ __('purchasing.copy.status') }}</th>
                <th scope="col">{{ __('common.table.action') }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</x-ui.data-table>

<script id="vsBestConfig" type="application/json">
{
    "summaryFallback": @json($summary),
    "currency": @json($currency),
    "filterParams": {
        "date_from": @json($dateFromInput),
        "date_to": @json($dateToInput),
        "currency": @json($currency),
        "material_name": @json($selectedVsBestMaterial['name'] ?? null),
        "shape": @json($selectedVsBestMaterial['shape'] ?? null)
    },
    "tableDataUrl": @json(route('purchasing.comparison.vs-best.data'))
}
</script>
