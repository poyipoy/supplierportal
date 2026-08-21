@extends('layouts.app')
@section('title', 'Price Trends - ADASI Portal')
@section('page-title', 'Material Price Trends')

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
        'Dashboard' => route('supplier.dashboard'),
        'Price History' => route('supplier.price-history.index'),
        'Price Trends' => null,
    ]" />

    <x-ui.page-header
        title="Material Price Trends"
        eyebrow="Commercial Intelligence"
        description="Analyze historical pricing trajectories across procurement periods for materials quoted by your company."
    />

    {{-- Tabs --}}
    <x-supplier.price-history-tabs active="historical" />

    {{-- Search & Dimension Filters Card --}}
        <x-ui.card title="Analytics and Material Selection">
        <x-slot:actions>
            @if($selectedMaterialName)
                <x-ui.button :href="route('supplier.price-history.export', request()->all())" variant="outline" size="sm" data-async-export>
                    <x-ui.icon name="file-spreadsheet" size="sm" />
                    <span>Export Analysis</span>
                </x-ui.button>
            @endif
        </x-slot:actions>

        <form method="GET" action="{{ route('supplier.price-history.historical') }}" class="row g-3 align-items-end" id="historicalFilterForm">
            <div class="col-md-5 col-lg-5">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="historicalMaterialSelect">Select Material Specification</label>
                <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required>
                    <option value="">Choose Material...</option>
                    @foreach($materials as $material)
                        <option value="{{ $material['name'] }}" data-shape="{{ $material['shape'] ?? '' }}" {{ $selectedMaterialName === $material['name'] ? 'selected' : '' }}>{{ $material['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4 col-lg-3">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="historicalRangeSelect">Time Horizon</label>
                <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                    @foreach($rangeOptions as $value => $label)
                        <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3 col-lg-4">
                <label class="form-label small fw-semibold tw-text-on-surface mb-1">Aggregation Interval</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewMonthly">Monthly</label>

                    <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewYearly">Yearly</label>
                </div>
            </div>

            <div class="col-12 mt-2 mb-0">
                <a href="#dimensionFilters" data-bs-toggle="collapse" class="text-decoration-none small fw-semibold d-inline-flex align-items-center gap-1 tw-text-on-surface-variant hover:tw-text-primary">
                            <x-ui.icon name="sliders-horizontal" size="sm" />
                    <span>Filter by Target Dimensions (Optional)</span>
                            <x-ui.icon name="chevron-down" size="sm" />
                </a>
            </div>

            <div class="collapse col-12 {{ request()->hasAny(['thickness', 'd_inner', 'd_outer', 'width', 'length']) ? 'show' : '' }}" id="dimensionFilters">
                <div class="row g-2 p-3 tw-bg-surface-low border rounded mt-1">
                    <div class="col-6 col-md-2 dimension-field" data-dim="thickness">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-thickness">Thickness (mm)</label>
                        <input type="number" step="0.01" name="thickness" id="supplier-history-thickness" class="form-control form-control-sm historical-filter-input" value="{{ request('thickness') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="d_inner">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-d-inner">D-Inner (mm)</label>
                        <input type="number" step="0.01" name="d_inner" id="supplier-history-d-inner" class="form-control form-control-sm historical-filter-input" value="{{ request('d_inner') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="d_outer">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-d-outer">D-Outer (mm)</label>
                        <input type="number" step="0.01" name="d_outer" id="supplier-history-d-outer" class="form-control form-control-sm historical-filter-input" value="{{ request('d_outer') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="width">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-width">Width (mm)</label>
                        <input type="number" step="0.01" name="width" id="supplier-history-width" class="form-control form-control-sm historical-filter-input" value="{{ request('width') }}">
                    </div>
                    <div class="col-6 col-md-2 dimension-field" data-dim="length">
                        <label class="form-label tw-text-ui-xs tw-text-on-surface-variant fw-semibold tw-uppercase" for="supplier-history-length">Length (mm)</label>
                        <input type="number" step="0.01" name="length" id="supplier-history-length" class="form-control form-control-sm historical-filter-input" value="{{ request('length') }}">
                    </div>
                    <div class="col-12 col-md-2 d-flex align-items-end">
                        <x-ui.button type="submit" size="sm" class="tw-w-full">
                            <x-ui.icon name="search" size="sm" />
                            <span>Apply Filters</span>
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
                    <div class="d-flex justify-content-between align-items-center w-100">
                        <h6 class="mb-0 fw-bold tw-text-ui-sm tw-text-on-surface" id="historicalChartTitle">
                            <x-ui.icon name="trending-up" size="sm" class="tw-me-1.5 text-primary" />
                            Price Trend Analysis: <span class="text-primary">{{ $selectedMaterialName }}</span>
                        </h6>
                    </div>
                </x-slot:header>
                <div class="tw-h-[320px] w-100">
                    <canvas id="historicalChart" role="img" aria-label="Supplier material price history">Supplier material price history chart.</canvas>
                </div>
            </x-ui.card>

            {{-- 2 Metrics Summary --}}
            <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant mb-4" id="historicalSummary" aria-label="Historical price summary">
                <div class="tw-p-3.5">
                    <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Average Change Per Period</div>
                    <div class="fs-4 fw-bold ui-tabular-nums mt-1 {{ ($summary['average_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['average_change_pct'] ?? null) < 0) ? 'text-success' : 'tw-text-on-surface-variant') }}" id="averageChangeValue">
                        {{ $formatPct($summary['average_change_pct'] ?? null) }}
                    </div>
                </div>
                <div class="tw-border-t tw-border-outline-variant tw-p-3.5 sm:tw-border-t-0">
                    <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Cumulative Trajectory, Initial to Latest</div>
                    <div class="fs-4 fw-bold ui-tabular-nums mt-1 {{ ($summary['total_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['total_change_pct'] ?? null) < 0) ? 'text-success' : 'tw-text-on-surface-variant') }}" id="totalChangeValue">
                        {{ $formatPct($summary['total_change_pct'] ?? null) }}
                    </div>
                </div>
            </section>

            {{-- Supporting Breakdown Table --}}
            <x-ui.data-table
                title="Historical Quotation Breakdown"
                description="Audited price quotes and converted Indonesian Rupiah benchmarks."
            >
                <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100">
                    <thead class="table-light text-center" id="historicalTableHead">
                        @if($periodView === 'yearly')
                            <tr>
                                <th scope="col">Year</th>
                                <th scope="col" class="text-end">Average Price (IDR/Kg)</th>
                                <th scope="col" class="text-end">Lowest Price</th>
                                <th scope="col" class="text-end">Highest Price</th>
                                <th scope="col" class="text-center">Period Variance</th>
                            </tr>
                        @else
                            <tr>
                                <th scope="col">PR Reference</th>
                                <th scope="col">PO Date</th>
                                <th scope="col" class="text-center">Status</th>
                                <th scope="col" class="text-end">Quoted Price/Kg</th>
                                <th scope="col" class="text-center">Currency</th>
                                <th scope="col" class="text-end">Converted IDR Price</th>
                                <th scope="col" class="text-center">% Variance</th>
                            </tr>
                        @endif
                    </thead>
                    <tbody id="historicalTableBody">
                        @foreach($tableData as $row)
                            @if($periodView === 'yearly')
                                <tr>
                                    <td class="text-center fw-bold tw-text-on-surface">{{ $row['period'] }}</td>
                                    <td class="text-end text-primary fw-bold ui-tabular-nums">Rp {{ number_format($row['price_idr'], 0, ',', '.') }}</td>
                                    <td class="text-end tw-text-on-surface ui-tabular-nums">Rp {{ number_format($row['min_idr'], 0, ',', '.') }}</td>
                                    <td class="text-end tw-text-on-surface ui-tabular-nums">Rp {{ number_format($row['max_idr'], 0, ',', '.') }}</td>
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
                                            <span class="ui-status-chip ui-status-chip--neutral">Draft</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{!! $row['status_badge'] ?? '-' !!}</td>
                                    <td class="text-end fw-semibold ui-tabular-nums">
                                        {{ number_format($row['price_per_kg'], 4, ',', '.') }}
                                    </td>
                                    <td class="text-center"><span class="ui-status-chip ui-status-chip--neutral">{{ $row['currency'] }}</span></td>
                                    <td class="text-end text-primary fw-bold ui-tabular-nums">{{ $row['price_idr'] ? 'Rp ' . number_format($row['price_idr'], 0, ',', '.') : '-' }}</td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </x-ui.data-table>
        @elseif($selectedMaterialName)
            <x-ui.alert tone="warning" title="No matching price history">No historical quotation pricing matches the selected material and dimension criteria.</x-ui.alert>
        @else
            <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface">
                <x-ui.empty-state icon="trending-up" title="Select a material specification" description="Choose a material above to review its price trajectory." />
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('historicalFilterForm');
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const rangeSelect = document.getElementById('historicalRangeSelect');
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

    if (!filterForm || !materialSelect || !rangeSelect || periodViewInputs.length === 0) {
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
            <div class="p-5 text-center bg-white border rounded tw-text-on-surface-variant">
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
            clearHistorycalResults('Select a material specification above to render price trajectory analytics.');
        }
    });

    rangeSelect.addEventListener('change', () => {
        if (materialSelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
            window.loadHistorycalPayloadFromFilters();
        } else if (materialSelect.value) {
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
    const historicalChartData = @json($chartData);
    if (chartCanvas && historicalChartData) {
        const chartTheme = getComputedStyle(document.documentElement);
        const chartColor = (token) => chartTheme.getPropertyValue(token).trim();
        const datasets = [{
            label: historicalChartData.type === 'yearly' ? 'Average Price (IDR/Kg)' : 'Price (IDR/Kg)',
            data: historicalChartData.pricesIdr || [],
            borderColor: chartColor('--md-primary'),
            backgroundColor: chartColor('--md-primary'),
            borderWidth: 2,
            fill: false,
            tension: 0.2,
            pointRadius: 3,
            pointHoverRadius: 4,
        }];

        if (historicalChartData.type === 'yearly' && Array.isArray(historicalChartData.minIdr)) {
            datasets.push({
                label: 'Lowest Price',
                data: historicalChartData.minIdr,
                borderColor: chartColor('--md-success'),
                backgroundColor: chartColor('--md-success'),
                borderWidth: 1.5,
                borderDash: [4, 3],
                fill: false,
                tension: 0.2,
                pointRadius: 2,
            });
        }

        if (historicalChartData.type === 'yearly' && Array.isArray(historicalChartData.maxIdr)) {
            datasets.push({
                label: 'Highest Price',
                data: historicalChartData.maxIdr,
                borderColor: chartColor('--md-error'),
                backgroundColor: chartColor('--md-error'),
                borderWidth: 1.5,
                borderDash: [4, 3],
                fill: false,
                tension: 0.2,
                pointRadius: 2,
            });
        }

        new Chart(chartCanvas.getContext('2d'), {
            type: 'line',
            data: {
                labels: historicalChartData.labels || [],
                datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                plugins: {
                    legend: {
                        position: 'top',
                        labels: { boxWidth: 12, usePointStyle: true }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return 'Rp ' + Number(context.raw).toLocaleString('id-ID');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        ticks: {
                            callback: function(value) {
                                return 'Rp ' + (value >= 1000 ? (value/1000) + 'k' : value);
                            }
                        },
                        grid: { color: chartColor('--md-outline-variant') }
                    },
                    x: {
                        grid: { display: false }
                    }
                }
            }
        });
    }
});
</script>
@endpush
