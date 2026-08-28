@extends('layouts.app')
@section('title', 'Inter-Supplier Comparison - ADASI Portal')
@section('page-title', 'Price Comparison')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Price Comparison"
        description="Compare supplier offers for a PR, inspect historical movement, and benchmark current prices."
        eyebrow="Purchasing"
    />
    <x-purchasing.comparison-tabs active="inter-supplier" />

    <x-ui.toolbar class="tw-mb-0">
        <form method="GET" action="{{ route('purchasing.comparison.inter-supplier') }}" class="tw-grid tw-w-full tw-gap-4 md:tw-grid-cols-[minmax(0,1fr)_auto] md:tw-items-end" id="interSupplierFilterForm">
            <div>
                <label for="comparisonPrSearch" class="form-label small fw-bold">Purchase Requisition</label>
                <div class="position-relative">
                    <input type="hidden" name="pr_id" id="comparisonPrId" value="{{ $selectedPrOption['id'] ?? '' }}">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text tw-bg-surface"><x-ui.icon name="search" /></span>
                        <input type="text"
                               class="form-control"
                               id="comparisonPrSearch"
                               value="{{ $selectedPrOption['label'] ?? '' }}"
                               placeholder="Type a PR number or period..."
                               autocomplete="off">
                        <x-ui.icon-button
                            icon="x"
                            label="Clear PR selection"
                            size="sm"
                            id="comparisonPrClear"
                            class="tw-rounded-none {{ $selectedPrOption ? '' : 'd-none' }}"
                        />
                    </div>
                    <div class="list-group position-absolute w-100 shadow-sm d-none tw-z-[1050] tw-max-h-[260px] tw-overflow-y-auto"
                         id="comparisonPrSuggestions"></div>
                </div>
                <div class="form-text">Type to display PR options, then select one option.</div>
            </div>
            <div>
                <x-ui.button type="submit" size="sm" class="tw-w-full md:tw-w-auto">
                    <x-slot:leading><x-ui.icon name="search" /></x-slot:leading>
                    Compare
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
            title="Commercial Overview"
            description="Ranked supplier totals and line-item price leadership for the selected PR."
        >
            <table class="table table-hover align-middle mb-0 tw-text-ui-xs">
                <thead class="table-light">
                    <tr>
                        <th scope="col">Supplier</th>
                        <th scope="col" class="text-end">Estimated Total</th>
                        <th scope="col" class="text-center">Lowest-Price Items</th>
                        <th scope="col">Position</th>
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
                                    <x-ui.status-chip tone="success" icon="star">Best Total</x-ui.status-chip>
                                @else
                                    <x-ui.status-chip tone="neutral">Alternative</x-ui.status-chip>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-ui.data-table>
    @endif

    {{-- Grafik Batang --}}
    <x-ui.card title="Price Comparison Chart per Material (IDR/Kg)">
        <x-slot:actions>
            <div class="tw-min-w-60">
                <label class="form-label small fw-bold mb-1" for="comparisonMaterialFilter">Material</label>
                <select class="form-select form-select-sm" id="comparisonMaterialFilter">
                    <option value="">All Material</option>
                    @foreach($materialOptions as $material)
                        <option value="{{ $material->id }}">{{ $material->material_name }}</option>
                    @endforeach
                </select>
            </div>
        </x-slot:actions>
        <div class="tw-min-h-[280px]">
            <canvas id="comparisonChart" height="280" role="img" aria-label="Supplier quotation price comparison">Supplier quotation price comparison chart.</canvas>
        </div>
    </x-ui.card>

    {{-- Side-by-side comparison table --}}
    <x-ui.data-table :title="'Comparison Table - ' . $selectedPr->pr_number" description="Lowest converted price per material is highlighted for fast review.">
                <table class="table table-bordered table-hover align-middle mb-0 tw-text-ui-xs">
                    <thead class="table-light text-center">
                        <tr>
                            <th scope="col" rowspan="2" class="align-middle">Material</th>
                            <th scope="col" rowspan="2" class="align-middle">Qty</th>
                            <th scope="col" rowspan="2" class="align-middle">Weight/Unit (Kg)</th>
                            <th scope="col" rowspan="2" class="align-middle">Total Weight (Kg)</th>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="colgroup" colspan="3" class="text-center">
                                    {{ $sup['name'] }}
                                    <div class="tw-mt-1">
                                        <x-ui.status-chip :tone="$sup['status'] === 'accepted' ? 'success' : ($sup['status'] === 'rejected' ? 'error' : 'info')" size="sm">
                                            {{ strtoupper($sup['status']) }}
                                        </x-ui.status-chip>
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                        <tr>
                            @foreach($comparison['suppliers'] as $sup)
                                <th scope="col" class="text-center small">Price/Kg ({{ $sup['currency'] }})</th>
                                <th scope="col" class="text-center small">Price/Kg (IDR)</th>
                                <th scope="col" class="text-center small">Offer Amount</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($comparison['matrix'] as $row)
                            @php
                                $idrPrices = collect($row['prices'])->pluck('price_idr')->filter()->values();
                                $minIdr = $idrPrices->count() > 0 ? $idrPrices->min() : null;
                            @endphp
                            <tr data-comparison-row data-material-id="{{ $row['item']->id }}" class="{{ ($row['spread_pct'] ?? 0) > 15 ? 'bg-warning bg-opacity-10' : '' }}">
                                <td class="fw-medium">
                                    {{ $row['item']->material_name }}
                                    @if(($row['spread_pct'] ?? 0) > 15)
                                        <div class="small text-danger mt-1" data-bs-toggle="tooltip" title="High price spread (>15%)">
                                            <x-ui.icon name="triangle-alert" class="me-1" />Spread {{ number_format($row['spread_pct'], 1) }}%
                                        </div>
                                    @endif
                                </td>
                                <td class="text-center">{{ number_format($row['item']->quantity_value, 0) }}</td>
                                <td class="text-center">{{ \App\Support\NumberFormat::maxDecimals($row['item']->weight_needed) }}</td>
                                <td class="text-center fw-medium text-primary">{{ \App\Support\NumberFormat::maxDecimals($row['item']->total_weight) }}</td>
                                @foreach($comparison['suppliers'] as $sup)
                                    @php $p = $row['prices'][$sup['quotation_id']] ?? null; @endphp
                                    @if($p && !$p['is_available'])
                                        <td class="text-center" colspan="3">
                                            <span class="ui-status-chip ui-status-chip--error">Not Available</span>
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" label="Open quotation details" size="sm" class="tw-ms-1" />
                                            @endif
                                        </td>
                                    @elseif($p && $p['price_per_kg'])
                                        <td class="text-end">
                                            {{ \App\Support\NumberFormat::maxDecimals($p['price_per_kg']) }}
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs tw-mt-0.5">
                                                Qty {{ $p['available_qty'] ?? '-' }} · {{ \App\Support\NumberFormat::maxDecimals($p['offered_total_weight']) }} kg
                                                @if($p['is_estimated_weight']) · Est Weight @endif
                                            </div>
                                        </td>
                                        <td class="text-end fw-bold {{ ($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr) ? 'text-success bg-success bg-opacity-10' : '' }}">
                                            Rp {{ \App\Support\NumberFormat::maxDecimals($p['price_idr']) }}
                                            @if($p['price_idr'] && $minIdr && $p['price_idr'] <= $minIdr)
                                                <x-ui.icon name="circle-check" class="ms-1" />
                                            @endif
                                            @if($p['detail_url'])
                                                <x-ui.icon-button :href="$p['detail_url']" icon="external-link" label="Open quotation details" size="sm" class="tw-ms-1" />
                                            @endif
                                        </td>
                                        <td class="text-end fw-semibold ui-tabular-nums">
                                            {{ \App\Support\NumberFormat::maxDecimals($p['offer_amount']) }} {{ $p['currency'] }}
                                            <div class="tw-text-on-surface-variant tw-text-ui-xs">Rp {{ \App\Support\NumberFormat::maxDecimals($p['offer_amount_idr']) }}</div>
                                        </td>
                                    @else
                                        <td class="text-center text-muted" colspan="3">- no quotation -</td>
                                    @endif
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
    </x-ui.data-table>
@elseif(request('pr_id'))
    <x-ui.alert tone="warning">No data found for the selected PR.</x-ui.alert>
@else
    <x-ui.card padding="none">
        <x-ui.empty-state icon="chart-no-axes-combined" title="Select a PR to compare" description="Choose an eligible purchase requisition above to compare supplier prices." />
    </x-ui.card>
@endif
</div>
@endsection

@push('scripts')
<script>
const eligiblePrOptions = @json($eligiblePrOptions);
const comparisonFilterForm = document.getElementById('interSupplierFilterForm');
const comparisonPrId = document.getElementById('comparisonPrId');
const comparisonPrSearch = document.getElementById('comparisonPrSearch');
const comparisonPrSuggestions = document.getElementById('comparisonPrSuggestions');
const comparisonPrClear = document.getElementById('comparisonPrClear');

const normalizeComparisonKeyword = (value) => String(value || '').toLowerCase().trim();

const hideComparisonPrSuggestions = () => {
    comparisonPrSuggestions.classList.add('d-none');
};

const toggleComparisonClear = () => {
    comparisonPrClear.classList.toggle('d-none', comparisonPrSearch.value.trim() === '');
};

const selectComparisonPr = (option) => {
    comparisonPrId.value = option.id;
    comparisonPrSearch.value = option.label;
    comparisonPrSearch.classList.remove('is-invalid');
    toggleComparisonClear();
    hideComparisonPrSuggestions();
    comparisonFilterForm.submit();
};

const renderComparisonPrSuggestions = () => {
    const keyword = normalizeComparisonKeyword(comparisonPrSearch.value);
    const matches = (keyword === ''
        ? eligiblePrOptions
        : eligiblePrOptions.filter((option) => option.search.includes(keyword))
    ).slice(0, 8);

    comparisonPrSuggestions.innerHTML = '';

    if (matches.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'list-group-item small text-muted';
        empty.textContent = 'No matching PR was found.';
        comparisonPrSuggestions.appendChild(empty);
        comparisonPrSuggestions.classList.remove('d-none');
        return;
    }

    matches.forEach((option) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'list-group-item list-group-item-action py-2';

        const title = document.createElement('div');
        title.className = 'fw-semibold small';
        title.textContent = option.prNumber;

        const meta = document.createElement('div');
        meta.className = 'text-muted';
        meta.style.fontSize = '.75rem';
        meta.textContent = `${option.period} - ${option.quotationCount} quotation(s)`;

        const preview = document.createElement('div');
        preview.className = 'text-secondary mt-1';
        preview.style.fontSize = '.7rem';
        preview.innerHTML = `<x-ui.icon name="package" class="me-1" />${option.previewMaterials}`;

        button.appendChild(title);
        button.appendChild(meta);
        button.appendChild(preview);
        button.addEventListener('mousedown', (event) => {
            event.preventDefault();
            selectComparisonPr(option);
        });

        comparisonPrSuggestions.appendChild(button);
    });

    comparisonPrSuggestions.classList.remove('d-none');
};

comparisonPrSearch.addEventListener('focus', renderComparisonPrSuggestions);
comparisonPrSearch.addEventListener('input', () => {
    comparisonPrId.value = '';
    comparisonPrSearch.classList.remove('is-invalid');
    toggleComparisonClear();
    renderComparisonPrSuggestions();
});

comparisonPrSearch.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        hideComparisonPrSuggestions();
    }
});

comparisonPrClear.addEventListener('click', () => {
    comparisonPrId.value = '';
    comparisonPrSearch.value = '';
    comparisonPrSearch.classList.remove('is-invalid');
    toggleComparisonClear();
    renderComparisonPrSuggestions();
    comparisonPrSearch.focus();
});

comparisonFilterForm.addEventListener('submit', (event) => {
    if (comparisonPrId.value) {
        return;
    }

    const keyword = normalizeComparisonKeyword(comparisonPrSearch.value);
    const exact = eligiblePrOptions.find((option) =>
        normalizeComparisonKeyword(option.label) === keyword
        || normalizeComparisonKeyword(option.prNumber) === keyword
    );

    if (exact) {
        comparisonPrId.value = exact.id;
        return;
    }

    event.preventDefault();
    comparisonPrSearch.classList.add('is-invalid');
    renderComparisonPrSuggestions();
});

document.addEventListener('click', (event) => {
    if (!comparisonPrSuggestions.contains(event.target) && event.target !== comparisonPrSearch) {
        hideComparisonPrSuggestions();
    }
});
</script>

@if($chartData)
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
const comparisonChartData = @json($chartData);
const comparisonMaterialIds = @json($chartMaterialIds);
const comparisonTheme = getComputedStyle(document.documentElement);
const comparisonColor = (token) => comparisonTheme.getPropertyValue(token).trim();
const comparisonPalette = [
    comparisonColor('--md-ref-primary-700'),
    comparisonColor('--md-ref-primary-500'),
    comparisonColor('--md-ref-primary-300'),
    comparisonColor('--md-ref-secondary-700'),
    comparisonColor('--md-ref-secondary-500'),
    comparisonColor('--md-ref-secondary-300'),
];
comparisonChartData.datasets.forEach((dataset, index) => {
    dataset.backgroundColor = comparisonPalette[index % comparisonPalette.length];
    dataset.borderColor = comparisonPalette[index % comparisonPalette.length];
    dataset.borderWidth = 1;
});
const comparisonChart = new Chart(document.getElementById('comparisonChart'), {
    type: 'bar',
    data: JSON.parse(JSON.stringify(comparisonChartData)),
    options: {
        responsive: true,
        maintainAspectRatio: false,
        animation: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: comparisonColor('--md-on-surface-variant'), boxWidth: 10, boxHeight: 10 },
            },
        },
        scales: {
            x: { grid: { display: false } },
            y: {
                beginAtZero: true,
                grid: { color: comparisonColor('--md-outline-variant') },
                ticks: {
                    color: comparisonColor('--md-on-surface-variant'),
                    callback: v => 'Rp ' + v.toLocaleString('id-ID'),
                },
            }
        }
    }
});

document.getElementById('comparisonMaterialFilter').addEventListener('change', function() {
    const materialId = this.value;
    document.querySelectorAll('[data-comparison-row]').forEach((row) => {
        row.classList.toggle('d-none', materialId !== '' && row.dataset.materialId !== materialId);
    });

    if (materialId === '') {
        comparisonChart.data = JSON.parse(JSON.stringify(comparisonChartData));
        comparisonChart.update();
        return;
    }

    const materialIndex = comparisonMaterialIds.indexOf(materialId);
    if (materialIndex === -1) return;

    comparisonChart.data.labels = [comparisonChartData.labels[materialIndex]];
    comparisonChart.data.datasets = comparisonChartData.datasets.map((dataset) => ({
        ...dataset,
        data: [dataset.data[materialIndex] ?? 0],
    }));
    comparisonChart.update();
});
</script>
@endif
@endpush
