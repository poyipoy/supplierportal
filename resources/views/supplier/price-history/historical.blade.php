@extends('layouts.app')
@section('title', __('supplier.copy.price_trends_adasi_portal'))
@section('page-title', __('supplier.copy.material_price_trends'))

@section('content')
@php
    $formatPct = function ($value) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
    };

    $changeBadge = function ($value) {
        if ($value === null) {
            return '<span class="tw-text-outline">-</span>';
        }

        if ($value > 0) {
            return '<span class="ui-status-chip ui-status-chip--error ui-tabular-nums">+' . number_format($value, 2, ',', '.') . '%</span>';
        }

        if ($value < 0) {
            return '<span class="ui-status-chip ui-status-chip--success ui-tabular-nums">−' . number_format(abs($value), 2, ',', '.') . '%</span>';
        }

        return '<span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">0%</span>';
    };
@endphp

<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.price_history') => route('supplier.price-history.index'),
        __('purchasing.breadcrumbs.price_trends') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.copy.material_price_trends')"
        :eyebrow="__('supplier.copy.commercial_intelligence')"
        :description="__('supplier.copy.analyze_historical_pricing_trajectories_across_procurement_periods_for_materials_quoted_by_your_comp')"
    />

    {{-- Tabs --}}
    <x-supplier.price-history-tabs active="historical" />

    {{-- Search & Dimension Filters Card --}}
        <x-ui.card :title="__('supplier.copy.analytics_and_material_selection')">
        <x-slot:actions>
            @if($selectedMaterialName && $selectedCurrency)
                <form action="{{ route('supplier.price-history.export') }}" method="POST" data-format-export-form data-advanced-export-form data-export-failed="{{ __('exports.advanced.failed') }}" class="tw-flex tw-flex-wrap tw-items-end tw-gap-2">
                    @csrf
                    @foreach(array_merge(request()->only(['thickness', 'd_inner', 'd_outer', 'width', 'length']), ['material_name' => $selectedMaterialName, 'period_view' => $periodView, 'range' => $range, 'currency' => $selectedCurrency]) as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <div>
                        <label for="history-export-format" class="form-label tw-text-ui-xs">{{ __('exports.advanced.format_title') }}</label>
                        <select name="options[format]" id="history-export-format" aria-describedby="history-export-format-help" class="form-select form-select-sm tw-min-h-10"><option value="xlsx">XLSX</option><option value="csv">CSV</option></select>
                    </div>
                    <x-ui.button type="submit" variant="outline" size="sm" class="tw-min-h-10"><x-ui.icon name="file-spreadsheet" size="sm" /><span>{{ __('supplier.copy.export_analysis') }}</span></x-ui.button>
                    <p id="history-export-format-help" class="tw-w-full tw-m-0 tw-text-ui-xs tw-text-on-surface-variant">{{ __('exports.advanced.csv_hint') }}</p>
                    <p data-format-export-error hidden role="alert" tabindex="-1" class="tw-w-full tw-m-0 tw-text-ui-sm tw-text-error"></p>
                </form>
            @endif
        </x-slot:actions>

        <form method="GET" action="{{ route('supplier.price-history.historical') }}" class="row g-3 align-items-end" id="historicalFilterForm">
            <div class="col-md-6 col-lg-4">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="historicalMaterialSelect">{{ __('supplier.copy.select_material_specification') }}</label>
                <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required>
                    <option value="">{{ __('supplier.copy.choose_material') }}</option>
                    @foreach($materials as $material)
                        <option value="{{ $material['name'] }}" data-shape="{{ $material['shape'] ?? '' }}" {{ $selectedMaterialName === $material['name'] ? 'selected' : '' }}>{{ $material['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 col-lg-3">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="historicalRangeSelect">{{ __('supplier.copy.time_horizon') }}</label>
                <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                    @foreach($rangeOptions as $value => $label)
                        <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="historicalCurrencySelect">{{ __('supplier.copy.currency') }}</label>
                <select name="currency" class="form-select form-select-sm" id="historicalCurrencySelect" {{ $currencyOptions->isEmpty() ? 'disabled' : 'required' }}>
                    @if($currencyOptions->isEmpty())
                        <option value="">{{ __('supplier.copy.select_material_first') }}</option>
                    @else
                        @foreach($currencyOptions as $currency)
                            <option value="{{ $currency }}" @selected($selectedCurrency === $currency)>{{ $currency }}</option>
                        @endforeach
                    @endif
                </select>
            </div>
            <div class="col-md-12 col-lg-3">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1">{{ __('supplier.copy.aggregation_interval') }}</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewMonthly">{{ __('supplier.copy.monthly') }}</label>

                    <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewYearly">{{ __('supplier.copy.yearly') }}</label>
                </div>
            </div>

            <div class="col-12 mt-2 mb-0">
                <a href="#dimensionFilters" data-bs-toggle="collapse" class="text-decoration-none small fw-semibold d-inline-flex align-items-center gap-1 tw-text-on-surface-variant hover:tw-text-primary">
                            <x-ui.icon name="sliders-horizontal" size="sm" />
                    <span>{{ __('supplier.copy.filter_by_target_dimensions_optional') }}</span>
                            <x-ui.icon name="chevron-down" size="sm" />
                </a>
            </div>

            <div class="collapse col-12 {{ request()->hasAny(['thickness', 'd_inner', 'd_outer', 'width', 'length']) ? 'show' : '' }}" id="dimensionFilters">
                <div class="row g-2 p-3 tw-bg-surface-low border rounded mt-1">
                    <div class="col-6 col-md-2 dimension-field" data-dim="thickness">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-thickness">{{ __('supplier.copy.thickness_mm') }}</label>
                        <input type="number" step="0.01" name="thickness" id="supplier-history-thickness" class="form-control form-control-sm historical-filter-input" value="{{ request('thickness') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="d_inner">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-d-inner">{{ __('supplier.copy.d_inner_mm') }}</label>
                        <input type="number" step="0.01" name="d_inner" id="supplier-history-d-inner" class="form-control form-control-sm historical-filter-input" value="{{ request('d_inner') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="d_outer">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-d-outer">{{ __('supplier.copy.d_outer_mm') }}</label>
                        <input type="number" step="0.01" name="d_outer" id="supplier-history-d-outer" class="form-control form-control-sm historical-filter-input" value="{{ request('d_outer') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="width">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-width">{{ __('supplier.copy.width_mm') }}</label>
                        <input type="number" step="0.01" name="width" id="supplier-history-width" class="form-control form-control-sm historical-filter-input" value="{{ request('width') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="length">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-length">{{ __('supplier.copy.length_mm') }}</label>
                        <input type="number" step="0.01" name="length" id="supplier-history-length" class="form-control form-control-sm historical-filter-input" value="{{ request('length') }}">
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <x-ui.button type="submit" size="sm" class="tw-w-full">
                            <x-ui.icon name="search" size="sm" />
                            <span>{{ __('supplier.copy.apply_filters') }}</span>
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </form>
    </x-ui.card>

    {{-- Results Container --}}
    <div id="historicalResults">
        @if($chartData)
            {{-- Trend Chart Card --}}
            <x-ui.card class="mb-4">
                <x-slot:header>
                    <div class="w-100">
                        <h6 class="mb-0 fw-bold tw-text-ui-sm tw-text-on-surface" id="historicalChartTitle">
                            <x-ui.icon name="trending-up" size="sm" class="tw-me-1.5 text-primary" />
                            {{ __('supplier.copy.price_trend_analysis') }} <span class="text-primary">{{ $selectedMaterialName }}</span> · {{ $selectedCurrency }}
                        </h6>
                        <p class="tw-mb-0 tw-mt-1 tw-text-ui-xs tw-text-on-surface-variant" id="historicalChartDescription">
                            {{ __('supplier.page.price_currency', ['currency' => $selectedCurrency]) }}
                        </p>
                    </div>
                </x-slot:header>
                <div id="historicalChartContainer" class="tw-relative tw-h-64 tw-w-full md:tw-h-[320px]">
                    <canvas id="historicalChart" role="img" aria-label="{{ __('supplier.page.price_history', ['currency' => $selectedCurrency]) }}" aria-describedby="historicalChartDescription">{{ __('supplier.copy.original_material_price_history_chart') }}</canvas>
                    <div id="historicalChartFallback" class="d-none tw-flex tw-h-full tw-items-center tw-justify-center tw-p-4 tw-text-center tw-text-ui-sm tw-text-on-surface-variant" role="status">
                        {{ __('supplier.copy.price_chart_is_temporarily_unavailable_the_historical_breakdown_remains_available_below') }}
                    </div>
                </div>
            </x-ui.card>

            {{-- 2 Metrics Summary --}}
            <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant mb-4" id="historicalSummary" aria-label="{{ __('supplier.copy.historical_price_summary') }}">
                <div class="tw-p-3.5">
                    <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('supplier.copy.average_change_per_period') }}</div>
                    <div class="fs-4 fw-bold ui-tabular-nums mt-1 {{ ($summary['average_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['average_change_pct'] ?? null) < 0) ? 'text-success' : 'tw-text-on-surface-variant') }}" id="averageChangeValue">
                        {{ $formatPct($summary['average_change_pct'] ?? null) }}
                    </div>
                </div>
                <div class="tw-border-t tw-border-outline-variant tw-p-3.5 sm:tw-border-t-0">
                    <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">{{ __('supplier.copy.cumulative_trajectory_initial_to_latest') }}</div>
                    <div class="fs-4 fw-bold ui-tabular-nums mt-1 {{ ($summary['total_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['total_change_pct'] ?? null) < 0) ? 'text-success' : 'tw-text-on-surface-variant') }}" id="totalChangeValue">
                        {{ $formatPct($summary['total_change_pct'] ?? null) }}
                    </div>
                </div>
            </section>

            {{-- Supporting Breakdown Table --}}
            <x-ui.data-table
                :title="__('supplier.copy.historical_quotation_breakdown')"
                :description="__('supplier.copy.audited_original_prices_in_the_selected_transaction_currency')"
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center" id="historicalTableHead">
                        @if($periodView === 'yearly')
                            <tr>
                                <th scope="col">{{ __('supplier.copy.year') }}</th>
                                <th scope="col" class="text-end">{{ __('supplier.js.average_price', ['currency' => $selectedCurrency]) }}</th>
                                <th scope="col" class="text-end">{{ __('supplier.copy.lowest_price_kg') }}</th>
                                <th scope="col" class="text-end">{{ __('supplier.copy.highest_price_kg') }}</th>
                                <th scope="col" class="text-center">{{ __('supplier.copy.period_variance') }}</th>
                            </tr>
                        @else
                            <tr>
                                <th scope="col">{{ __('supplier.copy.pr_reference') }}</th>
                                <th scope="col">{{ __('supplier.copy.po_date') }}</th>
                                <th scope="col" class="text-center">{{ __('supplier.copy.status') }}</th>
                                <th scope="col" class="text-end">{{ __('supplier.copy.quoted_price_kg') }}</th>
                                <th scope="col" class="text-center">{{ __('supplier.copy.currency') }}</th>
                                <th scope="col" class="text-center">{{ __('supplier.copy.variance') }}</th>
                            </tr>
                        @endif
                    </thead>
                    <tbody id="historicalTableBody">
                        @foreach($tableData as $row)
                            @if($periodView === 'yearly')
                                <tr>
                                    <td class="text-center fw-bold tw-text-on-surface">{{ $row['period'] }}</td>
                                    <td class="text-end text-primary fw-bold ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($row['price_per_kg'], 4) }}</td>
                                    <td class="text-end tw-text-on-surface ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($row['min_price'], 4) }}</td>
                                    <td class="text-end tw-text-on-surface ui-tabular-nums">{{ \App\Support\NumberFormat::maxDecimals($row['max_price'], 4) }}</td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @else
                                <tr>
                                    <td class="text-center fw-semibold">
                                        @if(!empty($row['pr_url']))
                                            <a href="{{ $row['pr_url'] }}" class="text-primary text-decoration-none hover:tw-underline" target="_blank">{{ $row['pr_number'] ?? '-' }}</a>
                                        @else
                                            {{ $row['pr_number'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="text-center tw-text-on-surface-variant ui-tabular-nums">
                                        @if(!empty($row['purchase_order_at_display']))
                                            {{ $row['purchase_order_at_display'] }}
                                        @else
                                            <span class="ui-status-chip ui-status-chip--neutral">{{ __('supplier.copy.draft') }}</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{!! $row['status_badge'] ?? '-' !!}</td>
                                    <td class="text-end fw-semibold ui-tabular-nums">
                                        {{ \App\Support\NumberFormat::maxDecimals($row['price_per_kg'], 4) }}
                                    </td>
                                    <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ $row['currency'] }}</span></td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        @elseif($selectedMaterialName)
            <x-ui.alert tone="warning" :title="__('supplier.copy.no_matching_price_history')">
                {{ $selectedCurrency
                    ? __('supplier.copy.no_historical_pricing_in_the_selected_currency_matches_the_material_period_and_dimension_criteria')
                    : __('supplier.copy.no_purchase_backed_pricing_is_available_for_the_selected_material_and_dimension_criteria') }}
            </x-ui.alert>
        @else
            <div class="tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface">
                <x-ui.empty-state icon="trending-up" :title="__('supplier.copy.select_a_material_specification')" :description="__('supplier.copy.choose_a_material_above_to_review_its_price_trajectory')" />
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('historicalFilterForm');
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const rangeSelect = document.getElementById('historicalRangeSelect');
    const currencySelect = document.getElementById('historicalCurrencySelect');
    const resultsContainer = document.getElementById('historicalResults');
    const periodViewInputs = document.querySelectorAll('input[name="period_view"]');
    const rangeOptionSets = {
        monthly: @json($monthlyRangeOptions),
        yearly: @json($yearlyRangeOptions),
    };
    const rangeAliases = {
        monthly: { '1y': '12m', '2y': '24m' },
        yearly: { '3m': '1y', '6m': '1y', '12m': '1y', '24m': '2y' },
    };

    if (!filterForm || !materialSelect || !rangeSelect || !currencySelect || periodViewInputs.length === 0) {
        return;
    }

    function escapeOptionText(value) {
        return String(value ?? '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));
    }

    function renderRangeOptions(view, preferredValue) {
        const options = rangeOptionSets[view] || {};
        const values = Object.keys(options);
        const aliasedValue = (rangeAliases[view] && rangeAliases[view][preferredValue])
            ? rangeAliases[view][preferredValue]
            : preferredValue;
        const selectedValue = values.includes(aliasedValue)
            ? aliasedValue
            : (values.includes('all') ? 'all' : values[0]);

        rangeSelect.innerHTML = values.map((value) => (
            `<option value="${escapeOptionText(value)}"${value === selectedValue ? ' selected' : ''}>${escapeOptionText(options[value])}</option>`
        )).join('');
    }

    function clearHistorycalResults(message) {
        if (!resultsContainer) return;

        resultsContainer.innerHTML = `
            <div class="p-5 text-center tw-bg-surface border rounded tw-text-on-surface-variant">
                <x-ui.icon name="trending-up" size="lg" class="tw-text-outline mb-2" />
                <p class="mb-0 tw-text-ui-sm">${escapeOptionText(message)}</p>
            </div>
        `;
    }

    periodViewInputs.forEach((input) => {
        input.addEventListener('change', () => {
            renderRangeOptions(input.value, rangeSelect.value);
            if (materialSelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
                window.loadHistorycalPayloadFromFilters();
            } else if (materialSelect.value) {
                filterForm.submit();
            }
        });
    });

    materialSelect.addEventListener('change', () => {
        if (materialSelect.value) {
            if (typeof window.loadHistorycalPayloadFromFilters === 'function') {
                window.loadHistorycalPayloadFromFilters();
            } else {
                filterForm.submit();
            }
        } else {
            clearHistorycalResults(@js(__('supplier.copy.choose_a_material_above_to_review_its_price_trajectory')));
        }
    });

    rangeSelect.addEventListener('change', () => {
        if (materialSelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
            window.loadHistorycalPayloadFromFilters();
        } else if (materialSelect.value) {
            filterForm.submit();
        }
    });

    currencySelect.addEventListener('change', () => {
        if (materialSelect.value && currencySelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
            window.loadHistorycalPayloadFromFilters();
        } else if (materialSelect.value && currencySelect.value) {
            filterForm.submit();
        }
    });

    filterForm.addEventListener('submit', (e) => {
        if (materialSelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
            e.preventDefault();
            window.loadHistorycalPayloadFromFilters();
        }
    });

    const activeView = document.querySelector('input[name="period_view"]:checked')?.value || 'monthly';
    renderRangeOptions(activeView, rangeSelect.value);

    // Shape Validation Logic
    const dimensionFields = document.querySelectorAll('.dimension-field');
    const relevantDimensions = {
        'Flat': ['thickness', 'width', 'length'],
        'Round': ['d_outer', 'length'],
        'Hollow': ['d_inner', 'd_outer', 'length']
    };

    function updateDimensionVisibility() {
        if (!materialSelect || !materialSelect.selectedOptions.length) return;
        const selectedOption = materialSelect.selectedOptions[0];
        const shape = selectedOption.dataset.shape || '';
        const allowed = relevantDimensions[shape] || ['thickness', 'd_inner', 'd_outer', 'width', 'length'];

        dimensionFields.forEach(field => {
            const dim = field.dataset.dim;
            if (allowed.includes(dim)) {
                field.style.display = '';
            } else {
                field.style.display = 'none';
                const input = field.querySelector('input');
                if (input) input.value = '';
            }
        });
    }

    materialSelect.addEventListener('change', updateDimensionVisibility);
    updateDimensionVisibility();

    // Chart initialization if canvas present
    const chartCanvas = document.getElementById('historicalChart');
    const chartFallback = document.getElementById('historicalChartFallback');
    const historicalChartData = @json($chartData);

    const showHistoricalChartFallback = () => {
        chartCanvas?.classList.add('d-none');
        chartFallback?.classList.remove('d-none');
    };

    if (chartCanvas && historicalChartData) {
        const colors = window.AdasiChart ? window.AdasiChart.getColors() : {
            primary: '#1F5FA6',
            success: '#1E8449',
            error: '#C0392B',
            surface: '#FFFFFF',
            onSurfaceVariant: '#64748B',
            gridLine: 'rgba(226, 232, 240, 0.75)',
        };
        const chartCurrency = historicalChartData.currency || '';
        const formatChartPrice = (value) => Number(value).toLocaleString('id-ID', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2,
        });
        const datasets = [{
            label: historicalChartData.type === 'yearly' ? window.AdasiI18n.t('supplier.js.average_price', { currency: chartCurrency }) : window.AdasiI18n.t('supplier.js.price_kg', { currency: chartCurrency }),
            data: historicalChartData.prices || [],
            borderColor: colors.primary,
            backgroundColor: (ctx) => window.AdasiChart?.createAreaGradient(ctx, colors.primary, 0.16, 0.01) || 'transparent',
            borderWidth: 2.5,
            fill: true,
            tension: 0.3,
            pointRadius: 3.5,
            pointHoverRadius: 6,
            pointBackgroundColor: colors.primary,
            pointBorderColor: colors.surface,
            pointBorderWidth: 2,
        }];

        if (historicalChartData.type === 'yearly' && Array.isArray(historicalChartData.minPrices)) {
            datasets.push({
                label: 'Lowest Price',
                data: historicalChartData.minPrices,
                borderColor: colors.success,
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                borderDash: [4, 3],
                fill: false,
                tension: 0.3,
                pointRadius: 2.5,
                pointHoverRadius: 5,
                pointBackgroundColor: colors.success,
                pointBorderColor: colors.surface,
                pointBorderWidth: 1.5,
            });
        }

        if (historicalChartData.type === 'yearly' && Array.isArray(historicalChartData.maxPrices)) {
            datasets.push({
                label: 'Highest Price',
                data: historicalChartData.maxPrices,
                borderColor: colors.error,
                backgroundColor: 'transparent',
                borderWidth: 1.5,
                borderDash: [4, 3],
                fill: false,
                tension: 0.3,
                pointRadius: 2.5,
                pointHoverRadius: 5,
                pointBackgroundColor: colors.error,
                pointBorderColor: colors.surface,
                pointBorderWidth: 1.5,
            });
        }

        if (typeof window.Chart !== 'function') {
            showHistoricalChartFallback();
            return;
        }

        try {
            new window.Chart(chartCanvas.getContext('2d'), {
                type: 'line',
                data: {
                    labels: historicalChartData.labels || [],
                    datasets,
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 400 },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: {
                                boxWidth: 8,
                                boxHeight: 8,
                                usePointStyle: true,
                                font: { family: 'Inter', size: 11, weight: '500' },
                                color: colors.onSurfaceVariant,
                                padding: 12,
                            }
                        },
                        tooltip: window.AdasiChart?.getTooltip({
                            callbacks: {
                                label: function(context) {
                                    return ' ' + context.dataset.label + ': ' + formatChartPrice(context.raw) + ' ' + chartCurrency + '/Kg';
                                }
                            }
                        }) || {},
                    },
                    scales: window.AdasiChart?.getScales({
                        yMaxTicks: 5,
                        yBeginAtZero: true,
                        yTitle: `${chartCurrency} / Kg`,
                        yFormat: (val) => formatChartPrice(val),
                    }) || {},
                }
            });
        } catch (error) {
            console.error('Supplier price history chart initialization failed:', error);
            showHistoricalChartFallback();
        }
    }
});
</script>
@endpush
