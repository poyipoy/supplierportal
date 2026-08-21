@extends('layouts.app')
@section('title', 'Price History - ADASI Portal')
@section('page-title', 'Price Comparison')

@section('content')
@php
    $formatPct = function ($value) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . number_format($value, 2, ',', '.') . '%';
    };

    $changeBadge = function ($value) {
        if ($value === null) {
            return '<span class="tw-text-on-surface-variant">-</span>';
        }

        if ($value > 0) {
            return '<span class="tw-font-semibold tw-text-error">+' . number_format($value, 2, ',', '.') . '%</span>';
        }

        if ($value < 0) {
            return '<span class="tw-font-semibold tw-text-success">&minus;' . number_format(abs($value), 2, ',', '.') . '%</span>';
        }

        return '<span class="tw-font-semibold tw-text-on-surface-variant">0%</span>';
    };
@endphp

<div class="tw-grid tw-gap-4">
    <x-ui.page-header
        title="Historical Price Analysis"
        description="Trace supplier price movement by material, period, and matching dimensions."
        eyebrow="Purchasing"
    />
    <x-purchasing.comparison-tabs active="historical" />

    <x-ui.toolbar class="tw-mb-0">
        <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-12 xl:tw-items-end" id="historicalFilterForm">
            <div class="xl:tw-col-span-3">
                <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="historicalSupplierSelect">Supplier</label>
                <select name="supplier_id" class="form-select form-select-sm" id="historicalSupplierSelect" required>
                    <option value="">Select Supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->getRouteKey() }}" {{ $selectedSupplierId === $supplier->getRouteKey() ? 'selected' : '' }}>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="xl:tw-col-span-3">
                <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="historicalMaterialSelect">Material</label>
                <select name="material_name" class="form-select form-select-sm" id="historicalMaterialSelect" required {{ $selectedSupplierId ? '' : 'disabled' }}>
                    <option value="">{{ $selectedSupplierId ? 'Select Material' : 'Select Supplier first' }}</option>
                    @foreach($materials as $material)
                        <option value="{{ $material['name'] }}" data-shape="{{ $material['shape'] ?? '' }}" {{ $selectedMaterialName === $material['name'] ? 'selected' : '' }}>{{ $material['name'] }}</option>
                    @endforeach
                </select>
            </div>
            <div class="xl:tw-col-span-2">
                <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface" for="historicalRangeSelect">Time Period</label>
                <select name="range" class="form-select form-select-sm" id="historicalRangeSelect">
                    @foreach($rangeOptions as $value => $label)
                        <option value="{{ $value }}" {{ $range === $value ? 'selected' : '' }}>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <fieldset class="tw-m-0 tw-min-w-0 tw-border-0 tw-p-0 xl:tw-col-span-2">
                <legend class="form-label tw-mb-1 tw-text-ui-xs tw-font-semibold tw-text-on-surface">Aggregation</legend>
                <div class="btn-group btn-group-sm w-100" role="group" aria-label="Historical price aggregation interval">
                    <input type="radio" class="btn-check" name="period_view" id="periodViewMonthly" value="monthly" {{ $periodView === 'monthly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewMonthly">Monthly</label>

                    <input type="radio" class="btn-check" name="period_view" id="periodViewYearly" value="yearly" {{ $periodView === 'yearly' ? 'checked' : '' }}>
                    <label class="btn btn-outline-primary" for="periodViewYearly">Yearly</label>
                </div>
            </fieldset>

            <div class="tw-flex xl:tw-col-span-2">
                <x-ui.button type="submit" size="sm" class="tw-w-full tw-justify-center">
                    <x-slot:leading><x-ui.icon name="search" /></x-slot:leading>
                    Apply Filters
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
                    <x-ui.icon name="sliders-horizontal" /> More Filters
                </a>
            </div>
            <div class="collapse md:tw-col-span-2 xl:tw-col-span-12 {{ request()->hasAny(['thickness', 'd_inner', 'd_outer', 'width', 'length']) ? 'show' : '' }}" id="dimensionFilters">
                <div class="tw-grid tw-gap-3 tw-border-t tw-border-outline-variant tw-pt-3 sm:tw-grid-cols-2 lg:tw-grid-cols-5">
                    <div class="dimension-field" data-dim="thickness">
                        <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-thickness">Thickness (mm)</label>
                        <input type="number" step="0.01" name="thickness" id="purchasing-history-thickness" class="form-control form-control-sm historical-filter-input" value="{{ request('thickness') }}">
                    </div>
                    <div class="dimension-field" data-dim="d_inner">
                        <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-d-inner">D-Inner (mm)</label>
                        <input type="number" step="0.01" name="d_inner" id="purchasing-history-d-inner" class="form-control form-control-sm historical-filter-input" value="{{ request('d_inner') }}">
                    </div>
                    <div class="dimension-field" data-dim="d_outer">
                        <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-d-outer">D-Outer (mm)</label>
                        <input type="number" step="0.01" name="d_outer" id="purchasing-history-d-outer" class="form-control form-control-sm historical-filter-input" value="{{ request('d_outer') }}">
                    </div>
                    <div class="dimension-field" data-dim="width">
                        <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-width">Width (mm)</label>
                        <input type="number" step="0.01" name="width" id="purchasing-history-width" class="form-control form-control-sm historical-filter-input" value="{{ request('width') }}">
                    </div>
                    <div class="dimension-field" data-dim="length">
                        <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-length">Length (mm)</label>
                        <input type="number" step="0.01" name="length" id="purchasing-history-length" class="form-control form-control-sm historical-filter-input" value="{{ request('length') }}">
                    </div>
                </div>
            </div>
        </form>
    </x-ui.toolbar>

    <div id="historicalResults" class="tw-grid tw-gap-4" aria-live="polite" aria-busy="false">
    @if($chartData)
        <section class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="historicalChartTitle">
            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                <h2 class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface" id="historicalChartTitle">
                    <x-ui.icon name="chart-no-axes-combined" class="tw-text-primary" />
                    <span>Price Trend: {{ $selectedMaterialName }} — {{ $suppliers->firstWhere('id', (int) $selectedSupplierId)->name ?? '' }}</span>
                </h2>
            </header>
            <div class="tw-h-72 tw-p-4">
                <canvas id="historicalChart" role="img" aria-label="Historical material price trend">Historical material price trend chart.</canvas>
            </div>
        </section>

        <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant" id="historicalSummary" aria-label="Historical price summary">
            <div class="tw-p-4">
                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Average Change per Period</div>
                <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold {{ ($summary['average_change_pct'] ?? null) > 0 ? 'tw-text-error' : ((($summary['average_change_pct'] ?? null) < 0) ? 'tw-text-success' : 'tw-text-on-surface-variant') }}" id="averageChangeValue">
                    {{ $formatPct($summary['average_change_pct'] ?? null) }}
                </div>
            </div>
            <div class="tw-border-t tw-border-outline-variant tw-p-4 sm:tw-border-t-0">
                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Total Change, Initial to Latest</div>
                <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold {{ ($summary['total_change_pct'] ?? null) > 0 ? 'tw-text-error' : ((($summary['total_change_pct'] ?? null) < 0) ? 'tw-text-success' : 'tw-text-on-surface-variant') }}" id="totalChangeValue">
                    {{ $formatPct($summary['total_change_pct'] ?? null) }}
                </div>
            </div>
        </section>

        <x-ui.data-table title="Supporting Data" description="Quotation values and period changes for the selected supplier and exact material specification.">
            <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                <thead class="table-light text-center" id="historicalTableHead">
                    @if($periodView === 'yearly')
                        <tr>
                            <th scope="col">Year</th>
                            <th scope="col">Average IDR/Kg</th>
                            <th scope="col">Lowest Price</th>
                            <th scope="col">Highest Price</th>
                            <th scope="col">Change from Previous Period</th>
                        </tr>
                    @else
                        <tr>
                            <th scope="col">PR Number</th>
                            <th scope="col">Supplier</th>
                            <th scope="col">Price/Kg</th>
                            <th scope="col">Total Price IDR</th>
                            <th scope="col">PO Date</th>
                            <th scope="col">Change</th>
                        </tr>
                    @endif
                </thead>
                <tbody id="historicalTableBody">
                    @foreach($tableData as $row)
                        @if($periodView === 'yearly')
                            <tr>
                                <td class="text-center fw-medium">{{ $row['period'] }}</td>
                                <td class="text-end text-primary fw-bold ui-tabular-nums">Rp {{ number_format($row['price_idr'], 0, ',', '.') }}</td>
                                <td class="text-end ui-tabular-nums">Rp {{ number_format($row['min_idr'], 0, ',', '.') }}</td>
                                <td class="text-end ui-tabular-nums">Rp {{ number_format($row['max_idr'], 0, ',', '.') }}</td>
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
                                    {{ number_format($row['price_per_kg'], 2, ',', '.') }}
                                    <span class="ui-status-chip ui-status-chip--neutral tw-ms-1">{{ $row['currency'] }}</span>
                                </td>
                                <td class="text-end text-primary fw-bold ui-tabular-nums">{{ $row['total_idr'] ? 'Rp ' . number_format($row['total_idr'], 0, ',', '.') : '-' }}</td>
                                <td class="text-center">
                                    @if(!empty($row['purchase_order_at_display']))
                                        {{ $row['purchase_order_at_display'] }}
                                    @else
                                        <span class="ui-status-chip ui-status-chip--neutral">Draft</span>
                                    @endif
                                </td>
                                <td class="text-center">{!! $changeBadge($row['change_pct'] ?? null) !!}</td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </x-ui.data-table>
    @elseif($selectedSupplierId && $selectedMaterialName)
        <x-ui.alert tone="warning" title="No matching price history">No quotation data was found for this supplier and material combination.</x-ui.alert>
    @else
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface">
            <x-ui.empty-state icon="chart-no-axes-combined" title="Select a supplier and material" description="Choose the primary filters above to review the historical price trend." />
        </div>
    @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const filterForm = document.getElementById('historicalFilterForm');
    const supplierSelect = document.getElementById('historicalSupplierSelect');
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const rangeSelect = document.getElementById('historicalRangeSelect');
    const resultsContainer = document.getElementById('historicalResults');
    const periodViewInputs = document.querySelectorAll('input[name="period_view"]');
    const materialsUrl = @json(route('purchasing.comparison.historical.materials'));
    const rangeOptionSets = {
        monthly: @json($monthlyRangeOptions),
        yearly: @json($yearlyRangeOptions),
    };
    const rangeAliases = {
        monthly: { '1y': '12m', '2y': '24m' },
        yearly: { '3m': '1y', '6m': '1y', '12m': '1y', '24m': '2y' },
    };

    if (!filterForm || !supplierSelect || !materialSelect || !rangeSelect || periodViewInputs.length === 0) {
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

    function renderMaterialOptions(materials, selectedValue = '') {
        const placeholder = !supplierSelect.value
            ? 'Select Supplier first'
            : (materials.length > 0 ? 'Select Material' : 'No historical material');

        materialSelect.innerHTML = [
            `<option value="">${escapeOptionText(placeholder)}</option>`,
            ...materials.map((material) => {
                const name = typeof material === 'string' ? material : (material.name || '');
                const shape = typeof material === 'string' ? '' : (material.shape || '');
                const selected = name === selectedValue ? ' selected' : '';
                return `<option value="${escapeOptionText(name)}" data-shape="${escapeOptionText(shape)}"${selected}>${escapeOptionText(name)}</option>`;
            }),
        ].join('');
        materialSelect.disabled = !supplierSelect.value || materials.length === 0;
    }

    function clearHistorycalResults(message) {
        if (!resultsContainer) return;

        resultsContainer.innerHTML = `
            <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface">
                <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-px-4 tw-py-8 tw-text-center">
                    <x-ui.icon name="chart-no-axes-combined" size="lg" class="tw-text-on-surface-variant" />
                    <p class="tw-m-0 tw-mt-3 tw-text-ui-sm tw-text-on-surface-variant">${escapeOptionText(message)}</p>
                </div>
            </div>
        `;
    }

    async function loadMaterialsForSupplier() {
        const supplierId = supplierSelect.value;
        const previousMaterial = materialSelect.value;

        renderMaterialOptions([], '');

        if (!supplierId) {
            clearHistorycalResults('Select a supplier and material above to view the historical price trend.');
            return;
        }

        materialSelect.innerHTML = '<option value="">Loading materials...</option>';
        materialSelect.disabled = true;
        clearHistorycalResults('Select a material from the selected supplier to view the historical price trend.');
        resultsContainer?.setAttribute('aria-busy', 'true');

        try {
            const url = new URL(materialsUrl, window.location.origin);
            url.searchParams.set('supplier_id', supplierId);

            const response = await fetch(url.toString(), {
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('Failed to load material');
            }

            const data = await response.json();
            const materials = data.materials || [];
            const selectedMaterial = materials.includes(previousMaterial) ? previousMaterial : '';
            renderMaterialOptions(materials, selectedMaterial);

            if (selectedMaterial && typeof window.loadHistorycalPayloadFromFilters === 'function') {
                window.loadHistorycalPayloadFromFilters();
            } else if (selectedMaterial) {
                filterForm.submit();
            }
        } catch (error) {
            renderMaterialOptions([], '');
            clearHistorycalResults('Failed to load material list. Try selecting a supplier again.');
        } finally {
            resultsContainer?.setAttribute('aria-busy', 'false');
        }
    }

    periodViewInputs.forEach((input) => {
        input.addEventListener('change', () => {
            renderRangeOptions(input.value, rangeSelect.value);
            if (materialSelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
                window.loadHistorycalPayloadFromFilters();
            } else {
                filterForm.submit();
            }
        });
    });

    supplierSelect.addEventListener('change', loadMaterialsForSupplier);

    materialSelect.addEventListener('change', () => {
        if (materialSelect.value) {
            if (typeof window.loadHistorycalPayloadFromFilters === 'function') {
                window.loadHistorycalPayloadFromFilters();
            } else {
                filterForm.submit();
            }
        } else {
            clearHistorycalResults('Select a material from the selected supplier to view the historical price trend.');
        }
    });

    rangeSelect.addEventListener('change', () => {
        if (materialSelect.value && typeof window.loadHistorycalPayloadFromFilters === 'function') {
            window.loadHistorycalPayloadFromFilters();
        } else {
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
const historicalDataUrl = @json(route('purchasing.comparison.historical'));
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
    if (value === null || value === undefined) return '<span class="tw-text-on-surface-variant">-</span>';
    const numberValue = Number(value);

    if (numberValue > 0) {
        return `<span class="tw-font-semibold tw-text-error">+${formatNumber(numberValue)}%</span>`;
    }

    if (numberValue < 0) {
        return `<span class="tw-font-semibold tw-text-success">&minus;${formatNumber(Math.abs(numberValue))}%</span>`;
    }

    return '<span class="tw-font-semibold tw-text-on-surface-variant">0%</span>';
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

function emptyHistorycalResultHtml(message, variant = 'empty') {
    if (variant === 'warning') {
        return `
            <div class="tw-flex tw-items-start tw-gap-3 tw-rounded-ui-sm tw-border-s-4 tw-border-warning tw-bg-warning-container tw-p-4 tw-text-warning-container-foreground" role="status">
                <x-ui.icon name="triangle-alert" size="sm" class="tw-mt-0.5 tw-shrink-0" />
                <div class="tw-text-ui-sm">${escapeHtml(message)}</div>
            </div>
        `;
    }

    return `
        <div class="tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface">
            <div class="tw-flex tw-flex-col tw-items-center tw-justify-center tw-px-4 tw-py-8 tw-text-center">
                <x-ui.icon name="chart-no-axes-combined" size="lg" class="tw-text-on-surface-variant" />
                <p class="tw-m-0 tw-mt-3 tw-text-ui-sm tw-text-on-surface-variant">${escapeHtml(message)}</p>
            </div>
        </div>
    `;
}

function historicalResultShellHtml() {
    return `
        <section class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface" aria-labelledby="historicalChartTitle">
            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                <h2 class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface" id="historicalChartTitle"></h2>
            </header>
            <div class="tw-h-72 tw-p-4">
                <canvas id="historicalChart" role="img" aria-label="Historical material price trend">Historical material price trend chart.</canvas>
            </div>
        </section>

        <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant" id="historicalSummary" aria-label="Historical price summary">
            <div class="tw-p-4">
                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Average Change per Period</div>
                <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold tw-text-on-surface-variant" id="averageChangeValue">-</div>
            </div>
            <div class="tw-border-t tw-border-outline-variant tw-p-4 sm:tw-border-t-0">
                <div class="tw-text-ui-xs tw-font-semibold tw-uppercase tw-tracking-wider tw-text-on-surface-variant">Total Change, Initial to Latest</div>
                <div class="ui-tabular-nums tw-mt-1 tw-text-ui-2xl tw-font-semibold tw-text-on-surface-variant" id="totalChangeValue">-</div>
            </div>
        </section>

        <section class="ui-data-table tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface tw-shadow-ui-1">
            <header class="tw-border-b tw-border-outline-variant tw-px-4 tw-py-3">
                <h2 class="tw-m-0 tw-text-sm tw-font-bold tw-text-on-surface">Supporting Data</h2>
                <p class="tw-m-0 tw-mt-0.5 tw-text-ui-xs tw-text-on-surface-variant">Quotation values and period changes for the selected supplier and exact material specification.</p>
            </header>
            <div class="ui-data-table__scroll tw-overflow-x-auto tw-w-full">
                <table class="table table-hover align-middle mb-0 tw-text-ui-sm">
                    <thead class="table-light text-center" id="historicalTableHead"></thead>
                    <tbody id="historicalTableBody"></tbody>
                </table>
            </div>
        </section>
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
                    label: 'Average Price/Kg (IDR)',
                    data: chartData.pricesIdr || [],
                    borderColor: historicalThemeColor('--md-primary'),
                    backgroundColor: historicalThemeRgba('--md-primary-rgb', .1),
                    borderWidth: 2,
                    fill: false,
                    tension: 0.2,
                    pointRadius: 3,
                    pointBackgroundColor: historicalThemeColor('--md-primary'),
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
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
                                    'Highest: ' + formatRupiah(chartData.maxIdr?.[index]),
                                    'Lowest: ' + formatRupiah(chartData.minIdr?.[index]),
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
                    borderWidth: 2,
                    fill: false,
                    tension: 0.2,
                    pointRadius: 3,
                    pointBackgroundColor: historicalThemeColor('--md-primary'),
                    yAxisID: 'y',
                },
                {
                    label: 'Price/Kg (IDR)',
                    data: chartData.pricesIdr || [],
                    borderColor: historicalThemeColor('--md-error'),
                    backgroundColor: historicalThemeRgba('--md-error-rgb', .1),
                    borderWidth: 2,
                    fill: false,
                    tension: 0.2,
                    pointRadius: 3,
                    pointBackgroundColor: historicalThemeColor('--md-error'),
                    yAxisID: 'y1',
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
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
    element.classList.remove('tw-text-error', 'tw-text-success', 'tw-text-on-surface-variant');
    element.classList.add(value > 0 ? 'tw-text-error' : (value < 0 ? 'tw-text-success' : 'tw-text-on-surface-variant'));
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
                <th scope="col">Year</th>
                <th scope="col">Average IDR/Kg</th>
                <th scope="col">Lowest Price</th>
                <th scope="col">Highest Price</th>
                <th scope="col">Change from Previous Period</th>
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
            <th scope="col">PR Number</th>
            <th scope="col">Supplier</th>
            <th scope="col">Price/Kg</th>
            <th scope="col">Total Price IDR</th>
            <th scope="col">PO Date</th>
            <th scope="col">Change</th>
        </tr>
    `;
    body.innerHTML = rows.map((row) => `
        <tr>
            <td class="text-center fw-medium">
                ${row.pr_url
                    ? `<a href="${escapeHtml(row.pr_url)}" class="text-primary text-decoration-none hover:tw-underline">${escapeHtml(row.pr_number || '-')}<x-ui.icon name="arrow-right" class="ms-1" /></a>`
                    : escapeHtml(row.pr_number || '-')}
            </td>
            <td class="text-center">${escapeHtml(row.supplier || '-')}</td>
            <td class="text-end ui-tabular-nums">${formatNumber(row.price_per_kg)} <span class="ui-status-chip ui-status-chip--neutral tw-ms-1">${escapeHtml(row.currency)}</span></td>
            <td class="text-end text-primary fw-bold ui-tabular-nums">${formatRupiah(row.total_idr)}</td>
            <td class="text-center">${row.purchase_order_at_display ? escapeHtml(row.purchase_order_at_display) : '<span class="ui-status-chip ui-status-chip--neutral">Draft</span>'}</td>
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
                    ? 'No quotation data found for this supplier and material combination.'
                    : 'Select a supplier and material above to view the historical price trend.',
                payload.materialName ? 'warning' : 'empty'
            );
        }
        return;
    }

    if (!document.getElementById('historicalChart') && resultsContainer) {
        resultsContainer.innerHTML = historicalResultShellHtml();
    }

    renderHistorycalChart(payload);
    renderSummary(payload.summary || {});
    renderTable(payload);
    document.getElementById('historicalChartTitle').innerHTML =
        `<x-ui.icon name="chart-no-axes-combined" class="tw-text-primary" /><span>Price Trend: ${escapeHtml(payload.materialName)} — ${escapeHtml(payload.supplierName)}</span>`;
}

window.loadHistorycalPayloadFromFilters = async function () {
    const supplierSelect = document.getElementById('historicalSupplierSelect');
    const materialSelect = document.getElementById('historicalMaterialSelect');
    const filterForm = document.getElementById('historicalFilterForm');
    const resultsContainer = document.getElementById('historicalResults');

    if (!supplierSelect.value || !materialSelect.value) {
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
    resultsContainer?.setAttribute('aria-busy', 'true');

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
        if (resultsContainer) {
            resultsContainer.innerHTML = emptyHistorycalResultHtml('Failed to load historical data. Try selecting filters again.', 'warning');
        }
    } finally {
        resultsContainer?.setAttribute('aria-busy', 'false');
    }
};

renderHistorycalChart(initialHistorycalPayload);
</script>
@endif
@endpush
