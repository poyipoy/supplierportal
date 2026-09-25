@php
    $formatPct = function ($value) {
        if ($value === null) return '-';

        return ($value > 0 ? '+' : '') . \App\Support\NumberFormat::maxDecimals($value) . '%';
    };

    $changeBadge = function ($value) {
        if ($value === null) {
            return '<span class="tw-text-on-surface-variant">-</span>';
        }

        if ($value > 0) {
            return '<span class="tw-font-semibold tw-text-error">+' . \App\Support\NumberFormat::maxDecimals($value) . '%</span>';
        }

        if ($value < 0) {
            return '<span class="tw-font-semibold tw-text-success">&minus;' . \App\Support\NumberFormat::maxDecimals(abs($value)) . '%</span>';
        }

        return '<span class="tw-font-semibold tw-text-on-surface-variant">0%</span>';
    };
@endphp

<x-ui.toolbar class="tw-mb-0">
    <form method="GET" action="{{ route('purchasing.comparison.historical') }}" class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-12 xl:tw-items-end" id="historicalFilterForm" data-server-tabs-form data-managed-submit>
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
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-d-inner">Inner Diam. (mm)</label>
                    <input type="number" step="0.01" name="d_inner" id="purchasing-history-d-inner" class="form-control form-control-sm historical-filter-input" value="{{ request('d_inner') }}">
                </div>
                <div class="dimension-field" data-dim="d_outer">
                    <label class="form-label tw-mb-1 tw-text-ui-xs tw-font-medium tw-text-on-surface-variant" for="purchasing-history-d-outer">Outer Diam. (mm)</label>
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
    <section class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface" aria-labelledby="historicalChartTitle">
        <header class="tw-border-b tw-border-outline-variant tw-bg-surface-container tw-px-4 tw-py-3">
            <h2 class="tw-m-0 tw-flex tw-items-center tw-gap-2 tw-text-ui-sm tw-font-semibold tw-text-on-surface" id="historicalChartTitle">
                <x-ui.icon name="chart-no-axes-combined" class="tw-text-primary" />
                <span>Price Trend: {{ $selectedMaterialName }} — {{ $payload['supplierName'] ?? '' }}</span>
            </h2>
        </header>
        <div class="tw-h-72 tw-p-4">
            <canvas id="historicalChart" role="img" aria-label="Historical material price trend">Historical material price trend chart.</canvas>
        </div>
    </section>

    <section class="tw-grid tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface-container sm:tw-grid-cols-2 sm:tw-divide-x sm:tw-divide-outline-variant" id="historicalSummary" aria-label="Historical price summary">
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
                            <td class="text-end text-primary fw-bold ui-tabular-nums">Rp {{ \App\Support\NumberFormat::maxDecimals($row['price_idr']) }}</td>
                            <td class="text-end ui-tabular-nums">Rp {{ \App\Support\NumberFormat::maxDecimals($row['min_idr']) }}</td>
                            <td class="text-end ui-tabular-nums">Rp {{ \App\Support\NumberFormat::maxDecimals($row['max_idr']) }}</td>
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
                                {{ \App\Support\NumberFormat::maxDecimals($row['price_per_kg'], 4) }}
                                <span class="ui-status-chip ui-status-chip--neutral tw-ms-1">{{ $row['currency'] }}</span>
                            </td>
                            <td class="text-end text-primary fw-bold ui-tabular-nums">{{ $row['total_idr'] ? 'Rp '.\App\Support\NumberFormat::maxDecimals($row['total_idr']) : '-' }}</td>
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
        <div
            id="historicalPagination"
            class="tw-flex tw-flex-wrap tw-items-center tw-justify-between tw-gap-3 tw-border-t tw-border-outline-variant tw-px-4 tw-py-3 {{ $tablePagination ? '' : 'd-none' }}"
        >
            <span id="historicalPaginationSummary" class="tw-text-ui-xs tw-text-on-surface-variant">
                @if($tablePagination)
                    Showing {{ $tablePagination['from'] ?? 0 }}-{{ $tablePagination['to'] ?? 0 }} of {{ $tablePagination['total'] }} records
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
                    Previous
                </x-ui.button>
                <span id="historicalPaginationPage" class="tw-min-w-20 tw-text-center tw-text-ui-xs tw-font-semibold tw-text-on-surface">
                    @if($tablePagination)
                        Page {{ $tablePagination['current_page'] }} of {{ $tablePagination['last_page'] }}
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
                    Next
                </x-ui.button>
            </div>
        </div>
    </x-ui.data-table>
@elseif($selectedSupplierId && $selectedMaterialName)
    <x-ui.alert tone="warning" title="No matching price history">No quotation data was found for this supplier and material combination.</x-ui.alert>
@else
    <div class="tw-rounded-ui-md tw-border tw-border-outline tw-bg-surface">
        <x-ui.empty-state icon="chart-no-axes-combined" title="Select a supplier and material" description="Choose the primary filters above to review the historical price trend." />
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
