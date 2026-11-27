{{-- Gate 1 C7: with an active PR the tab renders the quotation comparison; otherwise legacy history. --}}
@if(!empty($prMode))
@include('purchasing.comparison._historical_pr_mode')
@else
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

    $pca = 'purchasing.comparison_analytics';
    $poDateSortDir = ($sortDir ?? 'desc') === 'asc' ? 'asc' : 'desc';
    $poDateAriaSort = $poDateSortDir === 'asc' ? 'ascending' : 'descending';
    $selectedMaterialsList = $selectedMaterials ?? [];
    $selectedMaterialKeys = $selectedMaterialKeys ?? [];
    $isMultiMaterial = count($selectedMaterialsList) > 1;
    $chartPeriodCount = count($chartData['labels'] ?? []);
    $defaultAxisMode = $isMultiMaterial ? 'material' : 'period';
    $defaultChartType = $defaultAxisMode === 'material' ? 'bar' : ($chartPeriodCount >= 3 ? 'bar' : 'line');
    $isPeriodCappedInitial = $defaultAxisMode === 'material' && $chartPeriodCount > 6;
    $chartSwitchActiveClass = 'tw:bg-primary tw:text-primary-foreground tw:hover:bg-[var(--ui-primary-hover)] tw:hover:text-primary-foreground';
    $chartSwitchInactiveClass = 'tw:bg-transparent tw:text-on-surface-variant tw:hover:bg-surface-container tw:hover:text-on-surface';
    $chartTitleMaterial = $isMultiMaterial
        ? __($pca.'.multi_material_title', ['count' => count($selectedMaterialsList)])
        : ($selectedMaterialName ?? '');
    $seriesTokenFor = static fn (int $idx): string => 'var(--md-chart-series-'.(($idx % 6) + 1).')';
    $supportingFiltersData = $supportingFilters ?? [
        'categories' => [],
        'doc_search' => '',
        'source_currencies' => [],
        'start_date' => null,
        'end_date' => null,
        'price_min' => null,
        'price_max' => null,
        'change_direction' => null,
    ];
    $activeSupportingCount = (int) ($activeSupportingFilterCount ?? \App\Services\Pricing\HistoricalPriceQueryService::countActiveSupportingFilters($supportingFiltersData));
    $categoryMultiOptions = collect($categoryOptions ?? [])->map(fn (array $cat) => [
        'value' => (string) $cat['key'],
        'label' => (string) $cat['label'],
        'sublabel' => (string) ($cat['sublabel'] ?? ''),
    ])->values()->all();
    $sourceCurrencyMultiOptions = $sourceCurrencyOptions ?? array_map(
        static fn (string $code): array => ['value' => $code, 'label' => $code],
        \App\Models\ExchangeRate::CURRENCIES
    );
    $categoryLabelMap = collect($categoryOptions ?? [])->mapWithKeys(fn (array $cat) => [(string) $cat['key'] => (string) $cat['label']])->all();
    $changeDirectionLabels = [
        'increased' => __($pca.'.change_increased'),
        'decreased' => __($pca.'.change_decreased'),
        'unchanged' => __($pca.'.change_unchanged'),
    ];
    $initialSupportingChips = [];
    if (! empty($supportingFiltersData['categories'])) {
        $catNames = array_map(fn (string $key) => $categoryLabelMap[$key] ?? $key, $supportingFiltersData['categories']);
        $initialSupportingChips[] = ['key' => 'categories', 'label' => __($pca.'.chip_category'), 'value' => implode(', ', $catNames)];
    }
    if (trim((string) ($supportingFiltersData['doc_search'] ?? '')) !== '') {
        $initialSupportingChips[] = ['key' => 'doc_search', 'label' => __($pca.'.chip_doc_search'), 'value' => (string) $supportingFiltersData['doc_search']];
    }
    if (! empty($supportingFiltersData['source_currencies'])) {
        $initialSupportingChips[] = ['key' => 'source_currencies', 'label' => __($pca.'.chip_source_currency'), 'value' => implode(', ', $supportingFiltersData['source_currencies'])];
    }
    if (($supportingFiltersData['start_date'] ?? null) !== null || ($supportingFiltersData['end_date'] ?? null) !== null) {
        $dateRangeText = trim(($supportingFiltersData['start_date'] ?? '...').' - '.($supportingFiltersData['end_date'] ?? '...'));
        $initialSupportingChips[] = ['key' => 'date_range', 'label' => __($pca.'.chip_po_date'), 'value' => $dateRangeText];
    }
    if (($supportingFiltersData['price_min'] ?? null) !== null || ($supportingFiltersData['price_max'] ?? null) !== null) {
        $minLabel = ($supportingFiltersData['price_min'] ?? null) !== null ? $formatPrice($supportingFiltersData['price_min'], 4) : '...';
        $maxLabel = ($supportingFiltersData['price_max'] ?? null) !== null ? $formatPrice($supportingFiltersData['price_max'], 4) : '...';
        $initialSupportingChips[] = ['key' => 'price_range', 'label' => __($pca.'.chip_price_range'), 'value' => $currency.' '.$minLabel.' - '.$maxLabel];
    }
    if (($supportingFiltersData['change_direction'] ?? null) !== null) {
        $dirKey = (string) $supportingFiltersData['change_direction'];
        $initialSupportingChips[] = ['key' => 'change_direction', 'label' => __($pca.'.chip_change'), 'value' => $changeDirectionLabels[$dirKey] ?? $dirKey];
    }
@endphp

@include('purchasing.comparison._evaluation_header', ['evaluation' => $evaluation, 'route' => 'purchasing.comparison.historical'])

<x-ui.toolbar class="tw:mb-0">
    @php
        $supplierFilterOptions = collect([
            ['value' => '', 'label' => __('purchasing.comparison_analytics.all_suppliers')],
        ])->merge(
            $suppliers->map(fn ($supplier) => [
                'value' => (string) $supplier->getRouteKey(),
                'label' => trim((string) $supplier->supplier?->company_name) ?: $supplier->name,
            ])
        )->values()->all();

        $materialMultiOptions = collect($materials)->map(fn (array $material) => [
            'value' => (string) ($material['key'] ?? \App\Services\Pricing\HistoricalPriceQueryService::encodeMaterialKey($material['name'], $material['shape'] ?? null)),
            'label' => (string) $material['label'],
            'sublabel' => !empty($material['shape']) ? \App\Models\PrItem::shapeLabel($material['shape']) : null,
            'dataset' => [
                'name' => (string) $material['name'],
                'shape' => (string) ($material['shape'] ?? ''),
                'keyOptions' => json_encode($material['key_options'] ?? []),
            ],
        ])->values()->all();

        $shapeFilterValues = collect(\App\Models\PrItem::SHAPES);
        $shapeFilterValue = $shapeFilterValues->contains($selectedShape ?? null) ? $selectedShape : '';
        $shapeFilterOptions = collect([
            ['value' => '', 'label' => __('purchasing.comparison_analytics.all_shapes')],
        ])->merge(
            $shapeFilterValues->map(fn ($shape) => [
                'value' => (string) $shape,
                'label' => \App\Models\PrItem::shapeLabel(\App\Models\PrItem::canonicalShape($shape) ?? $shape),
            ])
        )->values()->all();

        $rangeFilterOptions = collect($rangeOptions)->map(fn ($label, $value) => [
            'value' => (string) $value,
            'label' => (string) $label,
        ])->values()->all();

        $keyLabels = ['Flat' => __($pca.'.key_thickness'), 'Round' => __($pca.'.key_diameter'), 'Hollow' => __($pca.'.key_d_outer')];
        $keyMode = $keyDimension['mode'] ?? (($keyDimension['field'] ?? null) === 'mixed' ? 'mixed' : (($keyDimension['field'] ?? null) !== null ? 'single' : 'none'));
        $selectedKeyOptions = $keyMode === 'single' && $selectedMaterialsList !== []
            ? collect($selectedMaterialsList)
                ->flatMap(fn (array $m) => $m['key_options'] ?? [])
                ->unique()
                ->sort(fn (string $a, string $b) => bccomp($a, $b, 4))
                ->values()
                ->all()
            : [];
        $exactKey = ($keyDimension['min'] ?? null) !== null && ($keyDimension['min'] ?? null) === ($keyDimension['max'] ?? null) ? $keyDimension['min'] : null;
        $keySelectOptions = [];
        $selectedKeyOptionValue = '';
        if ($selectedMaterialsList !== [] && ($keyMode === 'mixed' || $selectedKeyOptions === [])) {
            $keySelectOptions[] = ['value' => '', 'label' => $keyMode === 'mixed' ? __($pca.'.key_option_all') : __($pca.'.key_option_none')];
        } else {
            $keySelectOptions[] = ['value' => '', 'label' => __($pca.'.key_option_all')];
            if (($keyDimension['min'] ?? null) !== null && ! in_array($exactKey, $selectedKeyOptions, true)) {
                $keySelectOptions[] = ['value' => '__custom', 'label' => __($pca.'.key_option_custom')];
                $selectedKeyOptionValue = '__custom';
            }
            foreach ($selectedKeyOptions as $value) {
                $keySelectOptions[] = [
                    'value' => (string) $value,
                    'label' => $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($value, 4), 'decimal'),
                ];
                if ($exactKey === $value) {
                    $selectedKeyOptionValue = (string) $value;
                }
            }
        }
        $keyOptionDisabled = (bool) ($selectedMaterialsList === [] || $keyMode === 'mixed' || $selectedKeyOptions === []);
        $keyRangeDisabled = (bool) ($selectedMaterialsList === [] || $keyMode === 'none');
    @endphp
    <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="tw:grid tw:w-full tw:gap-3 tw:md:grid-cols-2 tw:xl:grid-cols-12 tw:xl:items-end" id="historicalFilterForm" data-server-tabs-form data-managed-submit>
        <input type="hidden" name="currency" value="{{ $currency }}">
        <input type="hidden" name="sort_dir" id="historicalSortDirInput" value="{{ $poDateSortDir }}">
        <div class="tw:min-w-0 tw:xl:col-span-3">
            <x-ui.searchable-select
                name="supplier_id"
                id="historicalSupplierSelect"
                :label="__('purchasing.copy.supplier')"
                label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                size="sm"
                :placeholder="__('purchasing.comparison_analytics.all_suppliers')"
                :show-sublabel-on-trigger="false"
                :value="$selectedSupplierId ?? ''"
                :options="$supplierFilterOptions"
                class="tw:gap-1"
            />
        </div>
        <div class="tw:min-w-0 tw:xl:col-span-3">
            <x-ui.multi-select
                name="materials[]"
                id="historicalMaterialSelect"
                :label="__('purchasing.copy.material')"
                label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                size="sm"
                :placeholder="__('purchasing.copy.select_material')"
                :disabled-placeholder="__('purchasing.comparison_analytics.no_history')"
                :search-placeholder="__('purchasing.copy.material').'...'"
                :item-unit-label="__('purchasing.copy.material')"
                :max-selection="\App\Services\Pricing\HistoricalPriceQueryService::MAX_MULTI_MATERIALS"
                :value="$selectedMaterialKeys"
                :options="$materialMultiOptions"
                :disabled="$materials->isEmpty()"
                data-filter-name="materials"
                class="tw:gap-1"
            />
            <input type="hidden" name="shape" id="historicalShapeInput" value="{{ $selectedShape ?? '' }}">
        </div>
        <div class="tw:min-w-0 tw:md:col-span-1 tw:xl:col-span-2">
            <x-ui.searchable-select
                id="historicalShapeFilter"
                :label="__('purchasing.copy.shape')"
                label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                size="sm"
                :placeholder="__('purchasing.comparison_analytics.all_shapes')"
                :show-sublabel-on-trigger="false"
                :value="$shapeFilterValue"
                :options="$shapeFilterOptions"
                :select-attributes="[
                    'aria-controls' => 'historicalMaterialSelect',
                    'data-label-all' => __('purchasing.comparison_analytics.all_shapes'),
                    'data-label-flat' => __('materials.shape.flat'),
                    'data-label-round' => __('materials.shape.round'),
                    'data-label-hollow' => __('materials.shape.hollow'),
                ]"
                class="tw:gap-1"
            />
        </div>
        <div class="tw:min-w-0 tw:xl:col-span-2">
            <x-ui.searchable-select
                name="range"
                id="historicalRangeSelect"
                :label="__('purchasing.copy.time_period')"
                label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                size="sm"
                :clearable="false"
                :show-sublabel-on-trigger="false"
                :value="$range"
                :options="$rangeFilterOptions"
                class="tw:gap-1"
            />
        </div>
        <fieldset class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0 tw:xl:col-span-2">
            <legend class="ui-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __('purchasing.copy.aggregation') }}</legend>
            <div class="tw:inline-flex tw:w-full tw:rounded-ui-sm tw:border tw:border-input tw:overflow-hidden tw:bg-surface" role="group" aria-label="{{ __('purchasing.copy.historical_price_aggregation_interval') }}">
                <label class="tw:flex-1 tw:m-0 tw:cursor-pointer" for="periodViewMonthly">
                    <input type="radio" class="tw:sr-only tw:peer" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                    <span class="ui-focus-ring tw:flex tw:w-full tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:justify-center tw:px-3 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:transition-colors tw:hover:bg-surface-container tw:hover:text-on-surface tw:peer-checked:bg-primary tw:peer-checked:text-primary-foreground tw:peer-checked:hover:bg-[var(--ui-primary-hover)] tw:peer-checked:hover:text-primary-foreground tw:peer-focus-visible:outline tw:peer-focus-visible:outline-2 tw:peer-focus-visible:outline-primary">{{ __('purchasing.copy.monthly') }}</span>
                </label>

                <label class="tw:flex-1 tw:m-0 tw:border-s tw:border-outline-variant tw:cursor-pointer" for="periodViewYearly">
                    <input type="radio" class="tw:sr-only tw:peer" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                    <span class="ui-focus-ring tw:flex tw:w-full tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:justify-center tw:px-3 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:transition-colors tw:hover:bg-surface-container tw:hover:text-on-surface tw:peer-checked:bg-primary tw:peer-checked:text-primary-foreground tw:peer-checked:hover:bg-[var(--ui-primary-hover)] tw:peer-checked:hover:text-primary-foreground tw:peer-focus-visible:outline tw:peer-focus-visible:outline-2 tw:peer-focus-visible:outline-primary">{{ __('purchasing.copy.yearly') }}</span>
                </label>
            </div>
        </fieldset>

        {{-- Mission 07 (decisions 17-18) & R6: shape-driven key dimension, exact or inclusive range. --}}
        <fieldset class="tw:m-0 tw:min-w-0 tw:border-0 tw:p-0 tw:md:col-span-2 tw:xl:col-span-10" id="historicalKeyDimension"
                  data-label-flat="{{ $keyLabels['Flat'] }}"
                  data-label-round="{{ $keyLabels['Round'] }}"
                  data-label-hollow="{{ $keyLabels['Hollow'] }}"
                  data-label-mixed="{{ __($pca.'.key_dimension_mixed') }}"
                  data-hint-default="{{ __($pca.'.key_hint') }}"
                  data-hint-mixed="{{ __($pca.'.key_dimension_mixed_hint') }}"
                  @if($keyMode === 'none' && $selectedMaterialsList !== []) hidden @endif>
            <legend class="ui-label tw:mb-1 tw:text-ui-xs tw:font-semibold tw:text-on-surface" id="historicalKeyDimensionLabel">
                {{ $keyMode === 'mixed' ? __($pca.'.key_dimension_mixed') : ($keyLabels[$selectedShape ?? ''] ?? $keyLabels['Flat']) }}
            </legend>
            <div class="tw:flex tw:flex-wrap tw:items-end tw:gap-2">
                {{-- Recorded values write into key_min/key_max; this control is never submitted. --}}
                <div class="tw:min-w-44">
                    <x-ui.searchable-select
                        id="historicalKeyOption"
                        :label="__($pca.'.key_options')"
                        label-class="ui-label tw:mb-0 tw:text-ui-xs tw:text-on-surface-variant"
                        size="sm"
                        :allow-custom="true"
                        :custom-option-label="__($pca.'.use_custom_dimension')"
                        :placeholder="__($pca.'.key_option_all')"
                        :show-sublabel-on-trigger="false"
                        :value="$selectedKeyOptionValue"
                        :options="$keySelectOptions"
                        :disabled="$keyOptionDisabled"
                        :select-attributes="[
                            'data-label-all' => __($pca.'.key_option_all'),
                            'data-label-custom' => __($pca.'.key_option_custom'),
                            'data-label-none' => __($pca.'.key_option_none'),
                        ]"
                        class="tw:gap-1"
                    />
                </div>
                <div>
                    <label class="ui-label tw:mb-1 tw:text-ui-xs tw:text-on-surface-variant" for="historicalKeyMin">{{ __('purchasing.comparison_analytics.key_from') }}</label>
                    <input type="number" step="0.0001" min="0" name="key_min" id="historicalKeyMin" aria-describedby="historicalKeyHint" class="ui-input ui-input--sm tw:min-h-[var(--ui-control-height-sm)] historical-filter-input" value="{{ $keyDimension['min'] ?? '' }}" @disabled($keyRangeDisabled)>
                </div>
                <div>
                    <label class="ui-label tw:mb-1 tw:text-ui-xs tw:text-on-surface-variant" for="historicalKeyMax">{{ __('purchasing.comparison_analytics.key_to') }}</label>
                    <input type="number" step="0.0001" min="0" name="key_max" id="historicalKeyMax" aria-describedby="historicalKeyHint" class="ui-input ui-input--sm tw:min-h-[var(--ui-control-height-sm)] historical-filter-input" value="{{ $keyDimension['max'] ?? '' }}" @disabled($keyRangeDisabled)>
                </div>
                <p id="historicalKeyHint" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ $keyMode === 'mixed' ? __($pca.'.key_dimension_mixed_hint') : __($pca.'.key_hint') }}</p>
            </div>
        </fieldset>

        {{-- Second row, after the filters it applies: the first row's 12 columns are full. --}}
        <div class="tw:min-w-0 tw:flex tw:md:col-span-2 tw:xl:col-span-2 tw:xl:col-start-11">
            <x-ui.button type="submit" size="sm" class="tw:w-full tw:justify-center">
                <x-slot:leading><x-ui.icon name="search" size="sm" /></x-slot:leading>
                {{ __('purchasing.copy.apply_filters') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.toolbar>

<p id="historicalResultsStatus" class="tw:sr-only" aria-live="polite" role="status"></p>
<div id="historicalResults" class="tw:grid tw:gap-4" aria-busy="false">
    {{-- Permanent results shell toggled via tw:hidden so interactive export/chart controls never detach on AJAX reload. --}}
    <div id="historicalDataWrapper" class="tw:grid tw:gap-4 {{ $chartData ? '' : 'tw:hidden' }}">
        <section class="tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-ui-surface" aria-labelledby="historicalChartTitle">
            <header class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-b tw:border-outline-variant tw:px-4 tw:py-3">
                <h2 class="tw:m-0 tw:flex tw:items-center tw:gap-2 tw:text-ui-sm tw:font-semibold tw:text-on-surface" id="historicalChartTitle">
                    <x-ui.icon name="chart-no-axes-combined" class="tw:text-primary tw:shrink-0" />
                    <span id="historicalChartTitleText">{{ __('common.final_review.price_trend', ['material' => $chartTitleMaterial, 'supplier' => ($payload['supplierName'] ?? null) ?: __('purchasing.comparison_analytics.all_suppliers')]) }}</span>
                </h2>
                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                    <div
                        id="historicalChartAxisSwitch"
                        class="ui-chart-segmented tw:inline-flex tw:shrink-0 tw:rounded-ui-sm tw:border tw:border-input tw:overflow-hidden tw:bg-surface"
                        role="group"
                        aria-label="{{ __($pca.'.chart_axis_mode') }}"
                    >
                        <button
                            type="button"
                            data-historical-axis-mode="period"
                            aria-pressed="{{ $defaultAxisMode === 'period' ? 'true' : 'false' }}"
                            class="ui-chart-segmented__btn ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:gap-1.5 tw:border-0 tw:px-3 tw:text-ui-xs tw:font-semibold tw:cursor-pointer {{ $defaultAxisMode === 'period' ? $chartSwitchActiveClass : $chartSwitchInactiveClass }}"
                        >
                            <x-ui.icon name="calendar-days" size="xs" />
                            <span>{{ __($pca.'.chart_axis_period') }}</span>
                        </button>
                        <button
                            type="button"
                            data-historical-axis-mode="material"
                            aria-pressed="{{ $defaultAxisMode === 'material' ? 'true' : 'false' }}"
                            class="ui-chart-segmented__btn ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:gap-1.5 tw:border-0 tw:border-s tw:border-outline-variant tw:px-3 tw:text-ui-xs tw:font-semibold tw:cursor-pointer {{ $defaultAxisMode === 'material' ? $chartSwitchActiveClass : $chartSwitchInactiveClass }}"
                        >
                            <x-ui.icon name="package" size="xs" />
                            <span>{{ __($pca.'.chart_axis_material') }}</span>
                        </button>
                    </div>
                    <div
                        id="historicalChartTypeSwitch"
                        class="ui-chart-segmented tw:inline-flex tw:shrink-0 tw:rounded-ui-sm tw:border tw:border-input tw:overflow-hidden tw:bg-surface"
                        role="group"
                        aria-label="{{ __($pca.'.chart_type') }}"
                    >
                        <button
                            type="button"
                            data-historical-chart-type="line"
                            aria-pressed="{{ $defaultChartType === 'line' ? 'true' : 'false' }}"
                            class="ui-chart-segmented__btn ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:gap-1.5 tw:border-0 tw:px-3 tw:text-ui-xs tw:font-semibold tw:cursor-pointer {{ $defaultChartType === 'line' ? $chartSwitchActiveClass : $chartSwitchInactiveClass }}"
                        >
                            <x-ui.icon name="chart-line" size="xs" />
                            <span>{{ __($pca.'.chart_type_line') }}</span>
                        </button>
                        <button
                            type="button"
                            data-historical-chart-type="bar"
                            aria-pressed="{{ $defaultChartType === 'bar' ? 'true' : 'false' }}"
                            class="ui-chart-segmented__btn ui-focus-ring tw:inline-flex tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:gap-1.5 tw:border-0 tw:border-s tw:border-outline-variant tw:px-3 tw:text-ui-xs tw:font-semibold tw:cursor-pointer {{ $defaultChartType === 'bar' ? $chartSwitchActiveClass : $chartSwitchInactiveClass }}"
                        >
                            <x-ui.icon name="chart-column" size="xs" />
                            <span>{{ __($pca.'.chart_type_bar') }}</span>
                        </button>
                    </div>
                </div>
            </header>
            <div class="tw:p-4">
                <x-ui.chart
                    id="historicalChart"
                    :scrollable="true"
                    :summary-sr-only="true"
                    :label="__('purchasing.copy.historical_material_price_trend')"
                    :fallback="__('purchasing.copy.historical_material_price_trend_chart')"
                    plot-class="tw:h-72 ui-chart-plot-fade"
                />
                <div id="historicalChartFooter" class="ui-chart-summary-bar" hidden>
                    <div class="ui-chart-summary-bar__top">
                        <div id="historicalChartSummaryLead" class="ui-chart-summary-bar__lead ui-tabular-nums"></div>
                        <details id="historicalChartSummaryDetails" class="ui-chart-summary-details" hidden>
                            <summary id="historicalChartSummaryToggle" class="ui-chart-summary-details__toggle ui-focus-ring">
                                <span id="historicalChartSummaryToggleText"></span>
                                <x-ui.icon name="chevron-down" size="xs" class="ui-chart-summary-details__chevron" aria-hidden="true" />
                            </summary>
                            <div id="historicalChartSummaryList" class="ui-chart-summary-grid"></div>
                        </details>
                    </div>
                    <p
                        id="historicalChartAxisCapNote"
                        role="status"
                        aria-live="polite"
                        class="ui-chart-cap-note"
                        @if(! $isPeriodCappedInitial) hidden @endif
                    >{{ $isPeriodCappedInitial ? __($pca.'.chart_axis_capped_note', ['shown' => 6, 'total' => $chartPeriodCount]) : '' }}</p>
                </div>
            </div>
        </section>

        {{-- R4 KPI Summary: 4 peer cells for single material; per-material comparison table for 2+ materials. --}}
        <div id="historicalSummary" aria-label="{{ __('purchasing.copy.historical_price_summary') }}">
            <section
                id="historicalSingleSummary"
                class="tw:grid tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-ui-surface tw:sm:grid-cols-2 tw:xl:grid-cols-4 tw:divide-y tw:sm:divide-y-0 tw:sm:divide-x tw:divide-outline-variant {{ ($summary['is_multi'] ?? false) ? 'tw:hidden' : '' }}"
            >
                <div class="tw:p-4">
                    <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __($pca.'.best_price') }}</div>
                    <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold tw:text-success" id="historicalBestPriceValue">
                        {{ ($summary['best'] ?? null) !== null ? $currency.' '.$formatPrice($summary['best'], 4) : '-' }}
                    </div>
                    <div class="tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums" id="historicalBestBasis">
                        {{ __($pca.'.history_basis', ['pos' => $summary['po_count'] ?? 0, 'kg' => $formatNumber($summary['total_kg'] ?? 0)]) }}
                    </div>
                </div>
                <div class="tw:p-4">
                    <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __($pca.'.weighted_average') }}</div>
                    <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold tw:text-primary" id="historicalWeightedAverage">
                        {{ ($summary['weighted_average'] ?? null) !== null ? $currency.' '.$formatPrice($summary['weighted_average'], 4) : '-' }}
                    </div>
                    <div class="tw:mt-1 tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums" id="historicalWeightedBasis">
                        {{ __('purchasing.copy.highest_price') }}: {{ ($summary['highest'] ?? null) !== null ? $currency.' '.$formatPrice($summary['highest'], 4) : '-' }}
                    </div>
                    <div class="tw:mt-0.5 tw:text-ui-xs tw:text-warning {{ ($summary['excluded']['lines'] ?? 0) > 0 ? '' : 'tw:hidden' }}" id="historicalExcludedNote">
                        {{ ($summary['excluded']['lines'] ?? 0) > 0 ? __($pca.'.excluded_note', ['lines' => $summary['excluded']['lines'], 'kg' => $formatNumber($summary['excluded']['kg']), 'currencies' => implode(', ', $summary['excluded']['currencies'])]) : '' }}
                    </div>
                </div>
                <div class="tw:p-4">
                    <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __('purchasing.copy.average_change_per_period') }}</div>
                    <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold {{ ($summary['average_change_pct'] ?? null) > 0 ? 'tw:text-error' : ((($summary['average_change_pct'] ?? null) < 0) ? 'tw:text-success' : 'tw:text-on-surface-variant') }}" id="averageChangeValue">
                        {{ $formatPct($summary['average_change_pct'] ?? null) }}
                    </div>
                </div>
                <div class="tw:p-4">
                    <div class="tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __('purchasing.copy.total_change_initial_to_latest') }}</div>
                    <div class="ui-tabular-nums tw:mt-1 tw:text-ui-2xl tw:font-semibold {{ ($summary['total_change_pct'] ?? null) > 0 ? 'tw:text-error' : ((($summary['total_change_pct'] ?? null) < 0) ? 'tw:text-success' : 'tw:text-on-surface-variant') }}" id="totalChangeValue">
                        {{ $formatPct($summary['total_change_pct'] ?? null) }}
                    </div>
                </div>
            </section>

            <section
                id="historicalMultiSummary"
                class="tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface tw:shadow-ui-surface {{ ($summary['is_multi'] ?? false) ? '' : 'tw:hidden' }}"
                aria-labelledby="historicalMultiSummaryTitle"
            >
                <header class="tw:border-b tw:border-outline-variant tw:px-4 tw:py-2.5">
                    <h3 id="historicalMultiSummaryTitle" class="tw:m-0 tw:text-ui-sm tw:font-semibold tw:text-on-surface">{{ __($pca.'.per_material_summary') }}</h3>
                </header>
                <div class="tw:overflow-x-auto">
                    <table class="ui-table ui-table--sm ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-sm">
                        <thead>
                            <tr>
                                <th scope="col" class="tw:text-start">{{ __('purchasing.copy.material') }}</th>
                                <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.best_price') }}</th>
                                <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.weighted_average') }}</th>
                                <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __('purchasing.copy.average_change_per_period') }}</th>
                                <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __('purchasing.copy.total_change_initial_to_latest') }}</th>
                                <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.volume') }}</th>
                            </tr>
                        </thead>
                        <tbody id="historicalMultiSummaryBody">
                            @foreach($summary['per_material'] ?? [] as $matSummary)
                                <tr>
                                    <td class="tw:text-start tw:font-semibold tw:text-on-surface">
                                        <span class="tw:inline-flex tw:items-center tw:gap-2">
                                            <span data-historical-material-dot @if($defaultAxisMode === 'material') hidden @endif class="tw:inline-block tw:size-2.5 tw:shrink-0 tw:rounded-full" style="background-color: {{ $seriesTokenFor((int) ($matSummary['index'] ?? 0)) }};" aria-hidden="true"></span>
                                            <span>{{ $matSummary['label'] ?? $matSummary['material_name'] }}</span>
                                        </span>
                                    </td>
                                    <td class="tw:text-end tw:font-semibold tw:text-success ui-tabular-nums tw:whitespace-nowrap">{{ ($matSummary['best'] ?? null) !== null ? $currency.' '.$formatPrice($matSummary['best'], 4) : '-' }}</td>
                                    <td class="tw:text-end tw:font-bold tw:text-primary ui-tabular-nums tw:whitespace-nowrap">{{ ($matSummary['weighted_average'] ?? null) !== null ? $currency.' '.$formatPrice($matSummary['weighted_average'], 4) : '-' }}</td>
                                    <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">{!! $changeBadge($matSummary['average_change_pct'] ?? null) !!}</td>
                                    <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">{!! $changeBadge($matSummary['total_change_pct'] ?? null) !!}</td>
                                    <td class="tw:text-end tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums tw:whitespace-nowrap">{{ __($pca.'.history_basis', ['pos' => $matSummary['po_count'] ?? 0, 'kg' => $formatNumber($matSummary['total_kg'] ?? 0)]) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="tw:border-t tw:border-outline-variant tw:px-4 tw:py-2 tw:text-ui-xs tw:text-warning {{ ($summary['excluded']['lines'] ?? 0) > 0 ? '' : 'tw:hidden' }}" id="historicalMultiExcludedNote">
                    {{ ($summary['excluded']['lines'] ?? 0) > 0 ? __($pca.'.excluded_note', ['lines' => $summary['excluded']['lines'], 'kg' => $formatNumber($summary['excluded']['kg']), 'currencies' => implode(', ', $summary['excluded']['currencies'])]) : '' }}
                </div>
            </section>
        </div>

        <x-ui.data-table sticky :title="__('purchasing.copy.supporting_data')" :description="__('purchasing.comparison_analytics.supporting_data_description')">
            <x-slot:actions>
                <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                    <x-ui.button
                        type="button"
                        id="historicalSupportingFilterBtn"
                        variant="outline"
                        size="sm"
                        data-ui-modal-open="#historicalSupportingFilterModal"
                        aria-haspopup="dialog"
                        aria-controls="historicalSupportingFilterModal"
                    >
                        <x-ui.icon name="sliders-horizontal" size="sm" />
                        <span>{{ __('common.labels_review.filter') }}</span>
                        <span
                            id="historicalSupportingFilterCount"
                            class="ui-filter-bar__badge ui-tabular-nums {{ $activeSupportingCount > 0 ? '' : 'tw:hidden' }}"
                        >{{ $activeSupportingCount }}</span>
                    </x-ui.button>
                    <x-export.advanced-modal
                        export-key="purchasing.price-history"
                        :action="route('purchasing.export.price-history')"
                        :suppliers="$suppliers"
                        :materials="$materialMultiOptions"
                        :filter-options="[
                            'materials' => $materialMultiOptions,
                            'categories' => $categoryMultiOptions,
                            'source_currencies' => $sourceCurrencyMultiOptions,
                        ]"
                        :initial-filters="[
                            'currency' => $currency,
                            'period_view' => $periodView,
                            'range' => $range,
                            'sort_dir' => $poDateSortDir,
                        ]"
                        :filter-selectors="[
                            'supplier_id' => '#historicalSupplierSelect',
                            'materials' => '#historicalMaterialSelect',
                            'currency' => '#historicalFilterForm input[name=currency]',
                            'period_view' => 'input[name=period_view]:checked',
                            'range' => '#historicalRangeSelect',
                            'key_min' => '#historicalKeyMin',
                            'key_max' => '#historicalKeyMax',
                            'categories' => '#historicalFilterCategories',
                            'doc_search' => '#historicalFilterDocSearch',
                            'source_currencies' => '#historicalFilterSourceCurrencies',
                            'start_date' => '#historicalFilterStartDate',
                            'end_date' => '#historicalFilterEndDate',
                            'price_min' => '#historicalFilterPriceMin',
                            'price_max' => '#historicalFilterPriceMax',
                            'change_direction' => '#historicalSupportingFilterForm input[name=change_direction]:checked',
                            'sort_dir' => '#historicalSortDirInput',
                        ]"
                    />
                </div>
            </x-slot:actions>
            <x-slot:filters>
                <div
                    id="historicalSupportingFilterChips"
                    role="region"
                    aria-label="{{ rtrim(__('common.filters.active'), ':') }}"
                    aria-live="polite"
                    aria-atomic="false"
                    class="ui-filter-chips tw:w-full {{ $activeSupportingCount > 0 ? '' : 'tw:hidden' }}"
                >
                    @if($activeSupportingCount > 0)
                        <span class="ui-filter-chips__label">{{ __('common.filters.active') }}</span>
                        @foreach($initialSupportingChips as $chip)
                            <span class="ui-filter-chip" data-filter-chip-key="{{ $chip['key'] }}">
                                <span class="ui-filter-chip__key">{{ $chip['label'] }}:</span>
                                <span class="ui-filter-chip__value">{{ $chip['value'] }}</span>
                                <button
                                    type="button"
                                    class="ui-filter-chip__remove ui-focus-ring"
                                    data-supporting-chip-remove="{{ $chip['key'] }}"
                                    aria-label="{{ __('common.filters.remove_filter', ['label' => $chip['label'].': '.$chip['value']]) }}"
                                >
                                    <x-ui.icon name="x" size="xs" />
                                </button>
                            </span>
                        @endforeach
                        <button
                            type="button"
                            class="ui-filter-chips__clear ui-focus-ring"
                            data-supporting-chips-clear
                        >{{ __('common.filters.clear_all') }}</button>
                    @endif
                </div>
            </x-slot:filters>
            <table id="historicalSupportingTable" class="ui-table ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-sm">
                <caption class="tw:sr-only">{{ __('purchasing.copy.supporting_data') }}</caption>
                <thead id="historicalTableHead">
                    @if($periodView === 'yearly')
                        <tr>
                            <th scope="col" class="tw:text-start ui-sticky-col tw:whitespace-nowrap">{{ __('purchasing.copy.year') }}</th>
                            <th scope="col" class="tw:text-start">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.volume') }}</th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __('purchasing.copy.lowest_price') }}</th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.average_per_kg', ['currency' => $currency]) }}</th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __('purchasing.copy.highest_price') }}</th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.yoy_change') }}</th>
                        </tr>
                    @else
                        <tr>
                            <th scope="col" class="tw:text-start ui-sticky-col tw:whitespace-nowrap">{{ __('purchasing.copy.pr_number') }}</th>
                            <th scope="col" class="tw:text-start">{{ __('purchasing.copy.material') }}</th>
                            <th scope="col" class="tw:text-start">{{ __('purchasing.copy.dimensions') }}</th>
                            <th scope="col" class="tw:text-start">{{ __('materials.reference_prices.rules.category_name') }}</th>
                            <th scope="col" class="tw:text-start">{{ __('purchasing.copy.supplier') }}</th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __('purchasing.copy.price_kg') }}</th>
                            <th scope="col" data-converted-total-header class="tw:text-end tw:whitespace-nowrap">{{ __($pca.'.converted_total', ['currency' => $currency]) }}</th>
                            <th scope="col" aria-sort="{{ $poDateAriaSort }}" class="tw:text-start tw:whitespace-nowrap">
                                <button
                                    type="button"
                                    id="historicalSortPoDateBtn"
                                    class="ui-table-sort-btn"
                                    commandfor="historicalSupportingTable"
                                    command="--sort-po-date"
                                >
                                    <span>{{ __('purchasing.copy.po_date') }}</span>
                                    <span class="ui-table-sort-icon" aria-hidden="true"><x-ui.icon name="arrow-down" size="xs" class="tw:shrink-0 tw:text-current" /></span>
                                </button>
                            </th>
                            <th scope="col" class="tw:text-end tw:whitespace-nowrap">{{ __('purchasing.copy.change') }}</th>
                        </tr>
                    @endif
                </thead>
                <tbody id="historicalTableBody">
                    @forelse($tableData as $row)
                        @if($periodView === 'yearly')
                            <tr>
                                <td class="tw:text-start tw:font-semibold ui-sticky-col ui-tabular-nums tw:whitespace-nowrap">{{ $row['period'] }}</td>
                                <td class="tw:text-start tw:font-semibold ui-col-data-name">
                                    <span class="tw:inline-flex tw:items-center tw:gap-2">
                                        <span data-historical-material-dot @if($defaultAxisMode === 'material') hidden @endif class="tw:inline-block tw:size-2.5 tw:shrink-0 tw:rounded-full" style="background-color: {{ $seriesTokenFor((int) ($row['series_index'] ?? 0)) }};" aria-hidden="true"></span>
                                        <span>{{ $row['material_label'] ?? $row['material_name'] ?? $selectedMaterialName ?? '-' }}</span>
                                    </span>
                                </td>
                                <td class="tw:text-end tw:text-ui-xs tw:text-on-surface-variant ui-tabular-nums tw:whitespace-nowrap">{{ __($pca.'.history_basis', ['pos' => $row['po_count'] ?? 0, 'kg' => $formatNumber($row['total_kg'] ?? 0)]) }}</td>
                                <td class="tw:text-end tw:font-semibold tw:text-success ui-tabular-nums tw:whitespace-nowrap">{{ $row['min_idr'] !== null ? $currency.' '.$formatPrice($row['min_idr'], 4) : '-' }}</td>
                                <td class="tw:text-end tw:text-primary tw:font-bold ui-tabular-nums tw:whitespace-nowrap">{{ $row['price_idr'] !== null ? $currency.' '.$formatPrice($row['price_idr'], 4) : '-' }}</td>
                                <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">{{ $row['max_idr'] !== null ? $currency.' '.$formatPrice($row['max_idr'], 4) : '-' }}</td>
                                <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                            </tr>
                        @else
                            <tr>
                                <td class="tw:text-start tw:font-semibold ui-sticky-col tw:whitespace-nowrap">
                                    @if(!empty($row['pr_id']) && !empty($row['pr_url']))
                                        <a href="{{ $row['pr_url'] }}" class="ui-focus-ring tw:rounded-ui-xs tw:text-primary tw:no-underline tw:hover:underline">
                                            {{ $row['pr_number'] }}
                                            <x-ui.icon name="arrow-right" class="tw:ms-1 tw:text-ui-sm" />
                                        </a>
                                    @else
                                        {{ $row['pr_number'] ?? '-' }}
                                    @endif
                                </td>
                                <td class="tw:text-start tw:font-semibold ui-col-data-name">
                                    <span class="tw:inline-flex tw:items-center tw:gap-1.5">
                                        @if($isMultiMaterial)
                                            <span data-historical-material-dot @if($defaultAxisMode === 'material') hidden @endif class="tw:inline-block tw:size-2 tw:shrink-0 tw:rounded-full" style="background-color: {{ $seriesTokenFor((int) ($row['series_index'] ?? 0)) }};" aria-hidden="true"></span>
                                        @endif
                                        <span>{{ $row['material_name'] ?? $selectedMaterialName ?? '-' }}</span>
                                    </span>
                                </td>
                                <td class="tw:text-start ui-tabular-nums tw:whitespace-nowrap">{{ $row['dimension_display'] ?? '-' }}</td>
                                <td class="tw:text-start">
                                    <span class="ui-status-chip ui-status-chip--neutral">{{ $row['range_classification'] ?? '-' }}</span>
                                </td>
                                <td class="tw:text-start ui-col-data-name">{{ $row['supplier'] ?? '-' }}</td>
                                <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">
                                    {{ $formatPrice($row['price_per_kg'], 4) }}
                                    <span class="ui-status-chip ui-status-chip--neutral tw:ms-1">{{ $row['currency'] }}</span>
                                    <div class="tw:text-ui-xs tw:text-on-surface-variant">{{ $row['annual_price_display'] }}</div>
                                </td>
                                <td class="tw:text-end tw:text-primary tw:font-bold ui-tabular-nums tw:whitespace-nowrap">{{ $row['total_idr'] !== null ? $currency.' '.$formatPrice($row['total_idr']) : __('purchasing.comparison_analytics.rate_unavailable') }}</td>
                                <td class="tw:text-start tw:whitespace-nowrap">
                                    @if(!empty($row['purchase_order_at_display']))
                                        {{ $row['purchase_order_at_display'] }}
                                    @else
                                        <span class="ui-status-chip ui-status-chip--neutral">{{ __('purchasing.copy.draft') }}</span>
                                    @endif
                                </td>
                                <td class="tw:text-end ui-tabular-nums tw:whitespace-nowrap">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="{{ $periodView === 'yearly' ? 7 : 9 }}" class="tw:py-6 tw:text-center tw:text-ui-sm tw:text-on-surface-variant">
                                {{ __($pca.'.no_matching_supporting_rows') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <nav
                id="historicalPagination"
                aria-label="{{ __('pagination.navigation') }}"
                class="tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-3 tw:border-t tw:border-outline-variant tw:px-4 tw:py-3 {{ $tablePagination ? '' : 'tw:hidden' }}"
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
            </nav>
        </x-ui.data-table>
    </div>

    <div id="historicalNoDataAlert" class="{{ (!$chartData && $selectedMaterialsList !== []) ? '' : 'tw:hidden' }}">
        <x-ui.alert tone="warning" :title="__('purchasing.copy.no_matching_price_history')">
            <span id="historicalNoDataMessage">{{ $activeSupportingCount > 0 ? __($pca.'.no_data_with_supporting_filters') : __('purchasing.copy.no_quotation_data_was_found_for_this_supplier_and_material_combination') }}</span>
            <div id="historicalNoDataFilterActions" class="tw:mt-3 tw:flex tw:flex-wrap tw:items-center tw:gap-2 {{ $activeSupportingCount > 0 ? '' : 'tw:hidden' }}">
                <x-ui.button
                    type="button"
                    variant="outline-primary"
                    size="sm"
                    id="historicalNoDataAdjustFiltersBtn"
                    data-ui-modal-open="#historicalSupportingFilterModal"
                >
                    <x-ui.icon name="sliders-horizontal" size="sm" />
                    <span>{{ __($pca.'.adjust_filters') }}</span>
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="ghost"
                    size="sm"
                    id="historicalNoDataResetFiltersBtn"
                >
                    <x-ui.icon name="rotate-ccw" size="sm" />
                    <span>{{ __($pca.'.reset_supporting_filters') }}</span>
                </x-ui.button>
            </div>
        </x-ui.alert>
    </div>

    <div id="historicalEmptyState" class="tw:rounded-ui-md tw:border tw:border-outline tw:bg-surface {{ (!$chartData && $selectedMaterialsList === []) ? '' : 'tw:hidden' }}">
        <x-ui.empty-state icon="chart-no-axes-combined" :title="__('purchasing.comparison_analytics.select_material')">
            <span id="historicalEmptyMessage">{{ __('purchasing.copy.choose_the_primary_filters_above_to_review_the_historical_price_trend') }}</span>
        </x-ui.empty-state>
    </div>

    <div id="historicalErrorState" class="tw:hidden">
        <x-ui.alert tone="warning" role="alert">
            <span id="historicalErrorMessage">{{ __('purchasing.copy.comparison_failed_historical_data') }}</span>
        </x-ui.alert>
    </div>
</div>

{{-- Mounted outside #historicalDataWrapper so Adjust Filters stays accessible even when filters yield 0 rows. --}}
<dialog
    id="historicalSupportingFilterModal"
    class="ui-native-dialog"
    aria-labelledby="historicalSupportingFilterModalTitle"
    aria-describedby="historicalSupportingFilterModalDesc"
    closedby="any"
>
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content tw:border-outline tw:bg-surface tw:text-on-surface">
            <div class="modal-header">
                <div class="tw:min-w-0">
                    <h2 class="modal-title tw:m-0 tw:text-ui-lg tw:font-semibold tw:text-on-surface" id="historicalSupportingFilterModalTitle" tabindex="-1">{{ __($pca.'.supporting_filters_title') }}</h2>
                    <p id="historicalSupportingFilterModalDesc" class="tw:m-0 tw:mt-0.5 tw:text-ui-xs tw:text-on-surface-variant">{{ __($pca.'.supporting_filters_description') }}</p>
                </div>
                <button type="button" class="btn-close" data-ui-modal-close aria-label="{{ __('common.actions.close') }}"></button>
            </div>
            <form id="historicalSupportingFilterForm" data-managed-submit data-no-loader class="tw:flex tw:flex-col tw:min-h-0 tw:overflow-hidden">
                <div class="modal-body tw:grid tw:gap-4">
                    <fieldset class="tw:m-0 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container-low/40 tw:p-3.5">
                        <legend class="tw:float-none tw:w-auto tw:px-1.5 tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __($pca.'.panel_classification_doc') }}</legend>
                        <div class="tw:grid tw:gap-3 tw:md:grid-cols-2">
                            <div class="tw:min-w-0">
                                <x-ui.multi-select
                                    name="categories[]"
                                    id="historicalFilterCategories"
                                    :label="__('materials.reference_prices.rules.category_name')"
                                    label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                                    size="sm"
                                    :placeholder="__($pca.'.all_categories')"
                                    :disabled-placeholder="__($pca.'.all_categories')"
                                    :search-placeholder="__('materials.reference_prices.rules.category_name').'...'"
                                    :item-unit-label="__('materials.reference_prices.rules.category_name')"
                                    :value="$supportingFiltersData['categories'] ?? []"
                                    :options="$categoryMultiOptions"
                                    class="tw:gap-1"
                                />
                            </div>
                            <div class="tw:grid tw:content-start tw:gap-1 tw:min-w-0">
                                <label for="historicalFilterDocSearch" class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __($pca.'.doc_search_label') }}</label>
                                <input
                                    type="search"
                                    id="historicalFilterDocSearch"
                                    name="doc_search"
                                    maxlength="100"
                                    autocomplete="off"
                                    class="ui-input ui-input--sm tw:min-h-[var(--ui-control-height-sm)]"
                                    placeholder="{{ __($pca.'.doc_search_placeholder') }}"
                                    value="{{ $supportingFiltersData['doc_search'] ?? '' }}"
                                >
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="tw:m-0 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container-low/40 tw:p-3.5">
                        <legend class="tw:float-none tw:w-auto tw:px-1.5 tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __($pca.'.panel_date_currency') }}</legend>
                        <div class="tw:grid tw:gap-3 tw:md:grid-cols-2">
                            <div class="tw:min-w-0">
                                <x-ui.multi-select
                                    name="source_currencies[]"
                                    id="historicalFilterSourceCurrencies"
                                    :label="__($pca.'.source_currency')"
                                    label-class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface"
                                    size="sm"
                                    :placeholder="__($pca.'.all_currencies')"
                                    :disabled-placeholder="__($pca.'.all_currencies')"
                                    :search-placeholder="__($pca.'.source_currency').'...'"
                                    :item-unit-label="__($pca.'.source_currency')"
                                    :value="$supportingFiltersData['source_currencies'] ?? []"
                                    :options="$sourceCurrencyMultiOptions"
                                    class="tw:gap-1"
                                />
                            </div>
                            <div class="tw:grid tw:content-start tw:gap-1 tw:min-w-0">
                                <span class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __($pca.'.po_date_range') }}</span>
                                <x-ui.date-range-picker
                                    id="historicalFilterPoDateRange"
                                    granularity="day"
                                    start-name="start_date"
                                    start-id="historicalFilterStartDate"
                                    :start-label="__('exports.advanced.filters.start_date')"
                                    end-name="end_date"
                                    end-id="historicalFilterEndDate"
                                    :end-label="__('exports.advanced.filters.end_date')"
                                    :start-value="$supportingFiltersData['start_date'] ?? null"
                                    :end-value="$supportingFiltersData['end_date'] ?? null"
                                    :compact="true"
                                    :helper="__($pca.'.po_date_range_hint')"
                                />
                            </div>
                        </div>
                    </fieldset>

                    <fieldset class="tw:m-0 tw:rounded-ui-sm tw:border tw:border-outline-variant tw:bg-surface-container-low/40 tw:p-3.5">
                        <legend class="tw:float-none tw:w-auto tw:px-1.5 tw:text-ui-xs tw:font-semibold tw:uppercase tw:tracking-wider tw:text-on-surface-variant">{{ __($pca.'.panel_price_change') }}</legend>
                        <div class="tw:grid tw:gap-4 tw:md:grid-cols-2">
                            <div class="tw:grid tw:content-start tw:gap-1.5 tw:min-w-0">
                                <span id="historicalFilterPriceRangeLabel" class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __($pca.'.price_per_kg_range', ['currency' => $currency]) }}</span>
                                <div class="tw:grid tw:grid-cols-2 tw:gap-2">
                                    <div class="tw:min-w-0">
                                        <label for="historicalFilterPriceMin" class="tw:sr-only">{{ __($pca.'.price_min') }}</label>
                                        <input
                                            type="number"
                                            step="any"
                                            min="0"
                                            id="historicalFilterPriceMin"
                                            name="price_min"
                                            aria-describedby="historicalFilterPriceHint"
                                            class="ui-input ui-input--sm ui-tabular-nums tw:min-h-[var(--ui-control-height-sm)]"
                                            placeholder="{{ __($pca.'.price_min') }}"
                                            value="{{ $supportingFiltersData['price_min'] ?? '' }}"
                                        >
                                    </div>
                                    <div class="tw:min-w-0">
                                        <label for="historicalFilterPriceMax" class="tw:sr-only">{{ __($pca.'.price_max') }}</label>
                                        <input
                                            type="number"
                                            step="any"
                                            min="0"
                                            id="historicalFilterPriceMax"
                                            name="price_max"
                                            aria-describedby="historicalFilterPriceHint"
                                            class="ui-input ui-input--sm ui-tabular-nums tw:min-h-[var(--ui-control-height-sm)]"
                                            placeholder="{{ __($pca.'.price_max') }}"
                                            value="{{ $supportingFiltersData['price_max'] ?? '' }}"
                                        >
                                    </div>
                                </div>
                                <p id="historicalFilterPriceHint" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __($pca.'.price_range_hint') }}</p>
                            </div>

                            <div class="tw:grid tw:content-start tw:gap-1.5 tw:min-w-0">
                                <span class="ui-label tw:mb-0 tw:text-ui-xs tw:font-semibold tw:text-on-surface">{{ __($pca.'.change_direction') }}</span>
                                <div class="tw:inline-flex tw:w-full tw:rounded-ui-sm tw:border tw:border-input tw:overflow-hidden tw:bg-surface" role="group" aria-label="{{ __($pca.'.change_direction') }}" aria-describedby="historicalFilterChangeHint">
                                    @foreach(['' => __($pca.'.change_all'), 'increased' => __($pca.'.change_increased'), 'decreased' => __($pca.'.change_decreased'), 'unchanged' => __($pca.'.change_unchanged')] as $dirVal => $dirLabel)
                                        @php
                                            $dirId = 'historicalFilterChangeDir_'.($dirVal !== '' ? $dirVal : 'all');
                                            $dirChecked = (string) ($supportingFiltersData['change_direction'] ?? '') === (string) $dirVal;
                                        @endphp
                                        <label class="tw:flex-1 tw:m-0 {{ $loop->first ? '' : 'tw:border-s tw:border-outline-variant' }} tw:cursor-pointer" for="{{ $dirId }}">
                                            <input type="radio" class="tw:sr-only tw:peer" name="change_direction" id="{{ $dirId }}" value="{{ $dirVal }}" @checked($dirChecked)>
                                            <span class="ui-focus-ring tw:flex tw:w-full tw:min-h-[var(--ui-control-height-sm)] tw:items-center tw:justify-center tw:px-2 tw:text-ui-xs tw:font-semibold tw:text-on-surface-variant tw:transition-colors tw:hover:bg-surface-container tw:hover:text-on-surface tw:peer-checked:bg-primary tw:peer-checked:text-primary-foreground tw:peer-checked:hover:bg-[var(--ui-primary-hover)] tw:peer-checked:hover:text-primary-foreground tw:peer-focus-visible:outline tw:peer-focus-visible:outline-2 tw:peer-focus-visible:outline-primary">{{ $dirLabel }}</span>
                                        </label>
                                    @endforeach
                                </div>
                                <p id="historicalFilterChangeHint" class="tw:m-0 tw:text-ui-xs tw:text-on-surface-variant">{{ __($pca.'.change_direction_hint') }}</p>
                            </div>
                        </div>
                    </fieldset>
                </div>
                <div class="modal-footer tw:flex tw:flex-wrap tw:items-center tw:justify-between tw:gap-2">
                    <x-ui.button type="button" variant="ghost" size="sm" id="historicalSupportingFilterResetBtn">
                        <x-ui.icon name="rotate-ccw" size="sm" />
                        <span>{{ __('common.filters.reset') }}</span>
                    </x-ui.button>
                    <div class="tw:flex tw:items-center tw:gap-2">
                        <x-ui.button type="button" variant="ghost" size="sm" data-ui-modal-close>{{ __('common.actions.cancel') }}</x-ui.button>
                        <x-ui.button type="submit" variant="primary" size="sm" id="historicalSupportingFilterApplyBtn">
                            <x-ui.icon name="check" size="sm" />
                            <span>{{ __('purchasing.copy.apply_filters') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</dialog>

@php
    $selectedMaterialsConfig = array_values(array_map(fn (array $m) => [
        'key' => $m['key'] ?? \App\Services\Pricing\HistoricalPriceQueryService::encodeMaterialKey($m['name'], $m['shape'] ?? null),
        'name' => $m['name'],
        'shape' => $m['shape'] ?? null,
        'label' => $m['label'] ?? $m['name'],
    ], $selectedMaterialsList));
    $supportingFiltersConfig = [
        'categories' => array_values($supportingFiltersData['categories'] ?? []),
        'doc_search' => (string) ($supportingFiltersData['doc_search'] ?? ''),
        'source_currencies' => array_values($supportingFiltersData['source_currencies'] ?? []),
        'start_date' => $supportingFiltersData['start_date'] ?? null,
        'end_date' => $supportingFiltersData['end_date'] ?? null,
        'price_min' => $supportingFiltersData['price_min'] ?? null,
        'price_max' => $supportingFiltersData['price_max'] ?? null,
        'change_direction' => $supportingFiltersData['change_direction'] ?? null,
    ];
    $supportingFilterLabelsConfig = [
        'active' => __('common.filters.active'),
        'clearAll' => __('common.filters.clear_all'),
        'removeTemplate' => __('common.filters.remove_filter', ['label' => ':label']),
        'priceRangeLabelTemplate' => __($pca.'.price_per_kg_range', ['currency' => ':currency']),
        'noMatchingRows' => __($pca.'.no_matching_supporting_rows'),
        'noDataWithFilters' => __($pca.'.no_data_with_supporting_filters'),
        'sortPoDateDesc' => __($pca.'.sort_po_date_desc'),
        'sortPoDateAsc' => __($pca.'.sort_po_date_asc'),
        'sortAnnouncementTemplate' => __($pca.'.sorted_po_date_announcement'),
        'chips' => [
            'categories' => __($pca.'.chip_category'),
            'doc_search' => __($pca.'.chip_doc_search'),
            'source_currencies' => __($pca.'.chip_source_currency'),
            'date_range' => __($pca.'.chip_po_date'),
            'price_range' => __($pca.'.chip_price_range'),
            'change_direction' => __($pca.'.chip_change'),
        ],
        'categoryLabels' => $categoryLabelMap,
        'changeLabels' => $changeDirectionLabels,
    ];
@endphp
<script id="historicalConfig" type="application/json">
{
    "chartData": @json($chartData),
    "summary": @json($summary),
    "currency": @json($currency),
    "periodView": @json($periodView),
    "sortDir": @json($poDateSortDir),
    "tablePagination": @json($tablePagination),
    "range": @json($range),
    "monthlyRangeOptions": @json($monthlyRangeOptions),
    "yearlyRangeOptions": @json($yearlyRangeOptions),
    "selectedSupplierId": @json($selectedSupplierId),
    "selectedMaterialName": @json($selectedMaterialName),
    "selectedMaterialKeys": @json($selectedMaterialKeys),
    "selectedMaterials": @json($selectedMaterialsConfig),
    "supportingFilters": @json($supportingFiltersConfig),
    "supportingFilterLabels": @json($supportingFilterLabelsConfig),
    "supplierName": @json($payload['supplierName'] ?? ''),
    "maxMaterials": @json(\App\Services\Pricing\HistoricalPriceQueryService::MAX_MULTI_MATERIALS),
    "materialsUrl": @json(route('purchasing.comparison.historical.materials')),
    "historicalDataUrl": @json(route('purchasing.comparison.historical'))
}
</script>
@endif
