@extends('layouts.app')
@section('title', 'Tren Price - ADASI Portal')
@section('page-title', 'Price Comparison')

@section('content')
@php
    $formatPct = function ($value) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
    };

    $changeBadge = function ($value) {
        if ($value === null) {
            return '<span class="text-muted">-</span>';
        }

        if ($value > 0) {
            return '<span class="text-danger fw-bold">▲ ' . number_format($value, 2, ',', '.') . '%</span>';
        }

        if ($value < 0) {
            return '<span class="text-success fw-bold">▼ ' . number_format(abs($value), 2, ',', '.') . '%</span>';
        }

        return '<span class="text-muted fw-bold">- 0%</span>';
    };
@endphp

@push('styles')
<style>
    .hover-underline:hover {
        text-decoration: underline !important;
    }
</style>
@endpush

<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Material Price Trends" description="Analyze only your own quotation history by material, period, and matching dimensions." eyebrow="Supplier Portal" />
    <x-supplier.price-history-tabs active="historical" />

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Search Filters</h6>
        @if($selectedMaterialName)
        <a href="{{ route('supplier.price-history.export', request()->all()) }}" class="btn btn-sm btn-success" data-async-export>
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </a>
        @endif
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('supplier.price-history.historical') }}" class="row g-3 align-items-end" id="historicalFilterForm">
            <div class="col-md-6">
                <label class="form-label small fw-bold">Material</label>
                <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required>
                    <option value="">Select Material</option>
                    @foreach($materials as $material)
                        <option value="{{ $material['name'] }}" data-shape="{{ $material['shape'] ?? '' }}" {{ $selectedMaterialName === $material['name'] ? 'selected' : '' }}>{{ $material['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Time Period</label>
                <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                    @foreach($rangeOptions as $value => $label)
                        <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Tampilan</label>
                <div class="btn-group btn-group-sm w-100" role="group">
                    <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewMonthly">Monthly</label>

                    <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewYearly">Yearly</label>
                </div>
            </div>

            <div class="col-12 mt-3 mb-1">
                <a href="#dimensionFilters" data-bs-toggle="collapse" class="text-decoration-none small fw-bold">
                    <i class="bi bi-funnel"></i> Dimension Filter (Optional)
                </a>
            </div>
            <div class="collapse {{ request()->hasAny(['thickness', 'd_inner', 'd_outer', 'width', 'length']) ? 'show' : '' }}" id="dimensionFilters">
                <div class="row g-2">
                    <div class="col-md-2 dimension-field" data-dim="thickness">
                        <label class="form-label small text-muted" for="supplier-history-thickness">Thickness (mm)</label>
                        <input type="number" step="0.01" name="thickness" id="supplier-history-thickness" class="form-control form-control-sm historical-filter-input" value="{{ request('thickness') }}">
                    </div>
                    <div class="col-md-2 dimension-field" data-dim="d_inner">
                        <label class="form-label small text-muted" for="supplier-history-d-inner">D-Inner (mm)</label>
                        <input type="number" step="0.01" name="d_inner" id="supplier-history-d-inner" class="form-control form-control-sm historical-filter-input" value="{{ request('d_inner') }}">
                    </div>
                    <div class="col-md-2 dimension-field" data-dim="d_outer">
                        <label class="form-label small text-muted" for="supplier-history-d-outer">D-Outer (mm)</label>
                        <input type="number" step="0.01" name="d_outer" id="supplier-history-d-outer" class="form-control form-control-sm historical-filter-input" value="{{ request('d_outer') }}">
                    </div>
                    <div class="col-md-2 dimension-field" data-dim="width">
                        <label class="form-label small text-muted" for="supplier-history-width">Width (mm)</label>
                        <input type="number" step="0.01" name="width" id="supplier-history-width" class="form-control form-control-sm historical-filter-input" value="{{ request('width') }}">
                    </div>
                    <div class="col-md-2 dimension-field" data-dim="length">
                        <label class="form-label small text-muted" for="supplier-history-length">Length (mm)</label>
                        <input type="number" step="0.01" name="length" id="supplier-history-length" class="form-control form-control-sm historical-filter-input" value="{{ request('length') }}">
                    </div>
                    <div class="col-md-2 d-flex align-items-end flex-grow-1">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i> Apply</button>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div id="historicalResults">
@if($chartData)
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold" id="historicalChartTitle">
                <i class="bi bi-graph-up me-1"></i> Tren Price "{{ $selectedMaterialName }}"
            </h6>
        </div>
        <div class="card-body">
            <canvas id="historicalChart" height="300" role="img" aria-label="Supplier material price history">Supplier material price history chart.</canvas>
        </div>
    </div>

    <div class="row g-3 mb-4" id="historicalSummary">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Average change per period</div>
                    <div class="fs-4 fw-bold {{ ($summary['average_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['average_change_pct'] ?? null) < 0) ? 'text-success' : 'text-muted') }}" id="averageChangeValue">
                        {{ $formatPct($summary['average_change_pct'] ?? null) }}
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Total change (initial → latest)</div>
                    <div class="fs-4 fw-bold {{ ($summary['total_change_pct'] ?? null) > 0 ? 'text-danger' : ((($summary['total_change_pct'] ?? null) < 0) ? 'text-success' : 'text-muted') }}" id="totalChangeValue">
                        {{ $formatPct($summary['total_change_pct'] ?? null) }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Supporting Data</h6></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                    <thead class="table-light text-center" id="historicalTableHead">
                        @if($periodView === 'yearly')
                            <tr>
                                <th>Year</th>
                                <th>Average IDR/Kg</th>
                                <th>Lowest Price</th>
                                <th>Highest Price</th>
                                <th>Change from Previous Period</th>
                            </tr>
                        @else
                            <tr>
                                <th>PR No.</th>
                                <th>PO Date</th>
                                <th>Status</th>
                                <th>Price/Kg</th>
                                <th>Currency</th>
                                <th>IDR Price</th>
                                <th>% Change</th>
                            </tr>
                        @endif
                    </thead>
                    <tbody id="historicalTableBody">
                        @foreach($tableData as $row)
                            @if($periodView === 'yearly')
                                <tr>
                                    <td class="text-center fw-medium">{{ $row['period'] }}</td>
                                    <td class="text-end text-primary fw-bold">Rp {{ number_format($row['price_idr'], 0, ',', '.') }}</td>
                                    <td class="text-end">Rp {{ number_format($row['min_idr'], 0, ',', '.') }}</td>
                                    <td class="text-end">Rp {{ number_format($row['max_idr'], 0, ',', '.') }}</td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @else
                                <tr>
                                    <td class="text-center fw-medium">
                                        @if(!empty($row['pr_url']))
                                            <a href="{{ $row['pr_url'] }}" class="text-decoration-none hover-underline text-primary" target="_blank">{{ $row['pr_number'] ?? '-' }}</a>
                                        @else
                                            {{ $row['pr_number'] ?? '-' }}
                                        @endif
                                    </td>
                                    <td class="text-center">
                                        @if(!empty($row['purchase_order_at_display']))
                                            {{ $row['purchase_order_at_display'] }}
                                        @else
                                            <span class="badge bg-secondary">Draft</span>
                                        @endif
                                    </td>
                                    <td class="text-center">{!! $row['status_badge'] ?? '-' !!}</td>
                                    <td class="text-end">
                                        {{ number_format($row['price_per_kg'], 2, ',', '.') }}
                                    </td>
                                    <td class="text-center"><span class="badge bg-dark">{{ $row['currency'] }}</span></td>
                                    <td class="text-end text-primary fw-bold">{{ $row['price_idr'] ? 'Rp ' . number_format($row['price_idr'], 0, ',', '.') : '-' }}</td>
                                    <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
@elseif($selectedMaterialName)
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> No quotation data found for this material.</div>
@else
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5 text-muted">
            <i class="bi bi-graph-up tw-text-[3rem] tw-opacity-50"></i>
            <p class="mt-3 mb-0">Select the material above to view your historical price trend.</p>
        </div>
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
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-graph-up tw-text-[3rem] tw-opacity-50"></i>
                    <p class="mt-3 mb-0">${escapeOptionText(message)}</p>
                </div>
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
            clearHistorycalResults('Select the material above to view your historical price trend.');
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

    if (materialSelect) {
        materialSelect.addEventListener('change', updateDimensionVisibility);
        updateDimensionVisibility();
    }
});
</script>
@if($chartData)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const initialHistorycalPayload = @json($payload);
let historicalChart = null;
const historicalDataUrl = @json(route('supplier.price-history.historical'));
const historicalThemeStyles = getComputedStyle(document.documentElement);
const historicalThemeColor = (token) => historicalThemeStyles.getPropertyValue(token).trim();
const historicalThemeRgba = (token, alpha) => `rgba(${historicalThemeColor(token)}, ${alpha})`;
const historicalTooltipTheme = {
    backgroundColor: historicalThemeColor('--md-surface'),
    titleColor: historicalThemeColor('--md-on-surface'),
    bodyColor: historicalThemeColor('--md-on-surface-variant'),
    borderColor: historicalThemeColor('--md-outline'),
    borderWidth: 1,
};

function formatRupiah(value) {
    if (value === null || value === undefined || value === '') return '-';
    return 'Rp ' + Number(value).toLocaleString('id-ID');
}

function formatNumber(value, decimals = 2) {
    if (value === null || value === undefined || value === '') return '-';
    return Number(value).toLocaleString('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    });
}

function formatPercent(value) {
    if (value === null || value === undefined) return '-';
    return (Number(value) > 0 ? '+' : '') + Number(value).toLocaleString('id-ID', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }) + '%';
}

function changeHtml(value) {
    if (value === null || value === undefined) return '<span class="text-muted">-</span>';
    const numberValue = Number(value);

    if (numberValue > 0) {
        return `<span class="text-danger fw-bold">▲ ${formatNumber(numberValue)}%</span>`;
    }

    if (numberValue < 0) {
        return `<span class="text-success fw-bold">▼ ${formatNumber(Math.abs(numberValue))}%</span>`;
    }

    return '<span class="text-muted fw-bold">- 0%</span>';
}

function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, (char) => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;',
    }[char]));
}

function emptyHistorycalResultHtml(message, alertClass = 'card') {
    if (alertClass === 'warning') {
        return `<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> ${escapeHtml(message)}</div>`;
    }

    return `
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5 text-muted">
                <i class="bi bi-graph-up tw-text-[3rem] tw-opacity-50"></i>
                <p class="mt-3 mb-0">${escapeHtml(message)}</p>
            </div>
        </div>
    `;
}

function historicalResultShellHtml(materialName) {
    const exportUrl = new URL(@json(route('supplier.price-history.export')), window.location.origin);
    const formData = new FormData(document.getElementById('historicalFilterForm'));
    for (const [key, value] of formData.entries()) {
        if (value.trim() !== '') exportUrl.searchParams.set(key, value.trim());
    }

    document.querySelector('.card-header.bg-white.py-3.d-flex.justify-content-between.align-items-center').innerHTML = `
        <h6 class="mb-0 fw-bold">Search Filters</h6>
        <a href="${exportUrl.toString()}" class="btn btn-sm btn-success" data-async-export>
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </a>
    `;

    return `
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold" id="historicalChartTitle">
                    <i class="bi bi-graph-up me-1"></i> Tren Price "${escapeHtml(materialName)}"
                </h6>
            </div>
            <div class="card-body">
                <canvas id="historicalChart" height="300" role="img" aria-label="Supplier material price history">Supplier material price history chart.</canvas>
            </div>
        </div>

        <div class="row g-3 mb-4" id="historicalSummary">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Average change per period</div>
                        <div class="fs-4 fw-bold text-muted" id="averageChangeValue">-</div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <div class="text-muted small">Total change (initial → latest)</div>
                        <div class="fs-4 fw-bold text-muted" id="totalChangeValue">-</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Supporting Data</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                        <thead class="table-light text-center" id="historicalTableHead"></thead>
                        <tbody id="historicalTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    `;
}

function historicalChartConfig(payload) {
    const chartData = payload.chartData || {};

    if (payload.periodView === 'yearly') {
        return {
            type: 'line',
            data: {
                labels: chartData.labels || [],
                datasets: [{
                    label: 'Average Price (IDR/Kg)',
                    data: chartData.pricesIdr || [],
                    borderColor: historicalThemeColor('--md-primary'),
                    backgroundColor: historicalThemeRgba('--md-primary-rgb', .1),
                    fill: true,
                    tension: 0.3,
                    pointRadius: 6,
                    pointBackgroundColor: historicalThemeColor('--md-primary'),
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { position: 'bottom' },
                    tooltip: {
                        ...historicalTooltipTheme,
                        callbacks: {
                            label: (context) => 'Average: ' + formatRupiah(context.parsed.y),
                            afterLabel: (context) => {
                                const index = context.dataIndex;
                                return [
                                    'Tertinggi: ' + formatRupiah(chartData.maxIdr?.[index]),
                                    'Terendah: ' + formatRupiah(chartData.minIdr?.[index]),
                                ];
                            },
                        },
                    },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { callback: (value) => 'Rp ' + Number(value).toLocaleString('id-ID') } },
                },
            },
        };
    }

    return {
        type: 'line',
        data: {
            labels: chartData.labels || [],
            datasets: [
                {
                    label: 'Price/Kg (Original)',
                    data: chartData.prices || [],
                    borderColor: historicalThemeColor('--md-primary'),
                    backgroundColor: historicalThemeRgba('--md-primary-rgb', .1),
                    fill: true,
                    tension: 0.3,
                    pointRadius: 6,
                    pointBackgroundColor: historicalThemeColor('--md-primary'),
                    yAxisID: 'y',
                },
                {
                    label: 'Price/Kg (IDR)',
                    data: chartData.pricesIdr || [],
                    borderColor: historicalThemeColor('--md-error'),
                    backgroundColor: historicalThemeRgba('--md-error-rgb', .1),
                    fill: true,
                    tension: 0.3,
                    pointRadius: 6,
                    pointBackgroundColor: historicalThemeColor('--md-error'),
                    yAxisID: 'y1',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'bottom' },
                tooltip: historicalTooltipTheme,
            },
            scales: {
                y: { type: 'linear', position: 'left', title: { display: true, text: 'Original Currency' } },
                y1: { type: 'linear', position: 'right', title: { display: true, text: 'IDR' }, grid: { drawOnChartArea: false }, ticks: { callback: (value) => 'Rp ' + Number(value).toLocaleString('id-ID') } },
            },
        },
    };
}

function renderHistorycalChart(payload) {
    if (historicalChart) {
        historicalChart.destroy();
    }

    historicalChart = new Chart(document.getElementById('historicalChart'), historicalChartConfig(payload));
}

function updateSummaryClass(element, value) {
    element.classList.remove('text-danger', 'text-success', 'text-muted');
    element.classList.add(value > 0 ? 'text-danger' : (value < 0 ? 'text-success' : 'text-muted'));
}

function renderSummary(summary) {
    const averageChange = document.getElementById('averageChangeValue');
    const totalChange = document.getElementById('totalChangeValue');
    averageChange.textContent = formatPercent(summary.average_change_pct);
    totalChange.textContent = formatPercent(summary.total_change_pct);
    updateSummaryClass(averageChange, summary.average_change_pct);
    updateSummaryClass(totalChange, summary.total_change_pct);
}

function renderTable(payload) {
    const head = document.getElementById('historicalTableHead');
    const body = document.getElementById('historicalTableBody');
    const rows = payload.tableData || [];

    if (payload.periodView === 'yearly') {
        head.innerHTML = `
            <tr>
                <th>Year</th>
                <th>Average IDR/Kg</th>
                <th>Lowest Price</th>
                <th>Highest Price</th>
                <th>Change from Previous Period</th>
            </tr>
        `;
        body.innerHTML = rows.map((row) => `
            <tr>
                <td class="text-center fw-medium">${escapeHtml(row.period)}</td>
                <td class="text-end text-primary fw-bold">${formatRupiah(row.price_idr)}</td>
                <td class="text-end">${formatRupiah(row.min_idr)}</td>
                <td class="text-end">${formatRupiah(row.max_idr)}</td>
                <td class="text-center">${changeHtml(row.change_pct)}</td>
            </tr>
        `).join('');
        return;
    }

    head.innerHTML = `
        <tr>
            <th>PR No.</th>
            <th>PO Date</th>
            <th>Status</th>
            <th>Price/Kg</th>
            <th>Currency</th>
            <th>IDR Price</th>
            <th>% Change</th>
        </tr>
    `;
    body.innerHTML = rows.map((row) => `
        <tr>
            <td class="text-center fw-medium">
                ${row.pr_url ? `<a href="${row.pr_url}" class="text-decoration-none hover-underline text-primary" target="_blank">${escapeHtml(row.pr_number || '-')}</a>` : escapeHtml(row.pr_number || '-')}
            </td>
            <td class="text-center">${row.purchase_order_at_display ? escapeHtml(row.purchase_order_at_display) : '<span class="badge bg-secondary">Draft</span>'}</td>
            <td class="text-center">${row.status_badge || '-'}</td>
            <td class="text-end">${formatNumber(row.price_per_kg)}</td>
            <td class="text-center"><span class="badge bg-dark">${escapeHtml(row.currency)}</span></td>
            <td class="text-end text-primary fw-bold">${formatRupiah(row.price_idr)}</td>
            <td class="text-center">${changeHtml(row.change_pct)}</td>
        </tr>
    `).join('');
}

function renderPayload(payload) {
    const resultsContainer = document.getElementById('historicalResults');

    if (!payload.chartData) {
        if (historicalChart) {
            historicalChart.destroy();
            historicalChart = null;
        }

        if (resultsContainer) {
            resultsContainer.innerHTML = emptyHistorycalResultHtml(
                payload.materialName
                    ? 'No quotation data found for this material.'
                    : 'Select the material above to view your historical price trend.',
                payload.materialName ? 'warning' : 'card'
            );
        }
        document.querySelector('.card-header.bg-white.py-3.d-flex.justify-content-between.align-items-center').innerHTML = `<h6 class="mb-0 fw-bold">Search Filters</h6>`;
        return;
    }

    if (!document.getElementById('historicalChart') && resultsContainer) {
        resultsContainer.innerHTML = historicalResultShellHtml(payload.materialName);
    } else {
         const exportUrl = new URL(@json(route('supplier.price-history.export')), window.location.origin);
         const formData = new FormData(document.getElementById('historicalFilterForm'));
         for (const [key, value] of formData.entries()) {
             if (value.trim() !== '') exportUrl.searchParams.set(key, value.trim());
         }

         document.querySelector('.card-header.bg-white.py-3.d-flex.justify-content-between.align-items-center').innerHTML = `
             <h6 class="mb-0 fw-bold">Search Filters</h6>
             <a href="${exportUrl.toString()}" class="btn btn-sm btn-success" data-async-export>
                 <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
             </a>
         `;
    }

    renderHistorycalChart(payload);
    renderSummary(payload.summary || {});
    renderTable(payload);
    document.getElementById('historicalChartTitle').innerHTML =
        `<i class="bi bi-graph-up me-1"></i> Tren Price "${escapeHtml(payload.materialName)}"`;
}

window.loadHistorycalPayloadFromFilters = async function () {
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const filterForm = document.getElementById('historicalFilterForm');

    if (!materialSelect.value) {
        return;
    }

    const url = new URL(historicalDataUrl, window.location.origin);
    const formData = new FormData(filterForm);
    for (const [key, value] of formData.entries()) {
        if (value.trim() !== '') {
            url.searchParams.set(key, value.trim());
        }
    }
    url.searchParams.set('view', 'json');

    try {
        const response = await fetch(url.toString(), {
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
        });

        if (!response.ok) {
            throw new Error('Failed to load historical data');
        }

        const payload = await response.json();
        renderPayload(payload);

        url.searchParams.delete('view');
        window.history.replaceState(null, '', url.toString());
    } catch (error) {
        const resultsContainer = document.getElementById('historicalResults');
        if (resultsContainer) {
            resultsContainer.innerHTML = emptyHistorycalResultHtml('Failed to load historical data. Try selecting filters again.', 'warning');
        }
    }
};

if(initialHistorycalPayload && initialHistorycalPayload.chartData) {
    renderHistorycalChart(initialHistorycalPayload);
}
</script>
@endif
@endpush
