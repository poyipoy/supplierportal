@php
    $formatRupiah = fn ($value) => $value !== null ? 'Rp ' . number_format($value, 0, ',', '.') : '-';
    $formatNumber = fn ($value, $decimals = 1) => $value !== null ? \App\Support\NumberFormat::maxDecimals($value, $decimals) : '-';
@endphp

{{-- Filter Toolbar --}}
<x-ui.toolbar>
    <x-slot:filters>
        <form method="GET" action="{{ route('purchasing.comparison.vs-best') }}" class="d-flex flex-wrap align-items-center gap-2 w-100" id="vsBestFilterForm" data-server-tabs-form>
            <div style="min-width: 250px;">
                <x-ui.date-range-picker
                    id="vsBestMonthRange"
                    granularity="month"
                    start-name="date_from"
                    start-id="vsBestDateFrom"
                    start-label="From month"
                    :start-value="$dateFromInput"
                    end-name="date_to"
                    end-id="vsBestDateTo"
                    end-label="To month"
                    :end-value="$dateToInput"
                    compact
                />
            </div>
            <x-ui.button type="submit" variant="primary" size="sm" data-calendar-native-submit>
                <x-ui.icon name="filter" />
                <span>Apply</span>
            </x-ui.button>
            <x-ui.button :href="route('purchasing.comparison.vs-best')" variant="ghost" size="sm" data-server-tab data-tab-name="vs-best">
                <x-ui.icon name="rotate-ccw" />
                <span>Reset</span>
            </x-ui.button>
        </form>
    </x-slot:filters>
</x-ui.toolbar>

{{-- Summary Metrics Strip --}}
<div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2 xl:tw-grid-cols-4" aria-label="Historical benchmark summary">
    <x-ui.metric-card flat label="Total Compared Data" :value="$summary['total_rows']" icon="list-check" tone="neutral" value-id="vsBestTotalRows" />
    <x-ui.metric-card flat label="Competitive / Safe" :value="$summary['competitive_count']" icon="shield-check" tone="success" value-id="vsBestCompetitiveCount" />
    <x-ui.metric-card flat label="Above History" :value="$summary['above_count']" icon="trending-up" tone="{{ $summary['above_count'] > 0 ? 'warning' : 'neutral' }}" value-id="vsBestAboveCount" />
    <x-ui.metric-card flat label="Potential Total Difference" :value="$formatRupiah($summary['total_potential_difference_idr'])" icon="banknote" tone="primary" value-id="vsBestPotentialTotal" />
</div>

<x-ui.data-table title="Price Benchmark Details" description="Server-side results are ordered by the largest potential difference.">
    <table class="table table-hover align-middle mb-0 w-100 tw-text-ui-sm" id="vsBestTable">
        <thead class="table-light text-center">
            <tr>
                <th scope="col" class="text-start">Material</th>
                <th scope="col">Current Price</th>
                <th scope="col">Historical Best Price</th>
                <th scope="col">Difference IDR/kg</th>
                <th scope="col">Potential Total Difference</th>
                <th scope="col">Status</th>
                <th scope="col">Action</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</x-ui.data-table>

<script id="vsBestConfig" type="application/json">
{
    "summaryFallback": @json($summary),
    "filterParams": {
        "date_from": @json($dateFromInput),
        "date_to": @json($dateToInput)
    },
    "tableDataUrl": @json(route('purchasing.comparison.vs-best.data'))
}
</script>
