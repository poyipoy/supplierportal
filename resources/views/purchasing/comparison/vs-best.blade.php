@extends('layouts.app')
@section('uses-datatables', true)
@section('title', 'vs Best Price - ADASI Portal')
@section('page-title', 'Price Comparison')

@php
    $formatRupiah = fn ($value) => $value !== null ? 'Rp ' . number_format($value, 0, ',', '.') : '-';
    $formatNumber = fn ($value, $decimals = 1) => $value !== null ? \App\Support\NumberFormat::maxDecimals($value, $decimals) : '-';
@endphp

@section('content')
<div class="tw-grid tw-gap-4">
    <x-ui.page-header
        title="Current vs Best Price"
        description="Prioritize price gaps against the historical best after exchange-rate conversion."
        eyebrow="Purchasing"
    />
    <x-purchasing.comparison-tabs active="vs-best" />

    {{-- Filter Toolbar --}}
    <x-ui.toolbar>
        <x-slot:filters>
            <form method="GET" action="{{ route('purchasing.comparison.vs-best') }}" class="d-flex flex-wrap align-items-center gap-2 w-100" id="vsBestFilterForm">
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
                <x-ui.button type="submit" variant="secondary" size="sm" data-calendar-native-submit>
                    <x-ui.icon name="filter" />
                    <span>Apply</span>
                </x-ui.button>
                <x-ui.button :href="route('purchasing.comparison.vs-best')" variant="ghost" size="sm">
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
</div>
@endsection

@push('scripts')
<script>
$(document).ready(function() {
    const summaryFallback = @json($summary);
    const filterParams = {!! json_encode([
        'date_from' => $dateFromInput,
        'date_to' => $dateToInput,
    ]) !!};

    function formatRupiah(value) {
        if (value === null || value === undefined || value === '') return '-';
        return 'Rp ' + Number(value).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    }

    function formatInteger(value) {
        return Number(value || 0).toLocaleString('id-ID');
    }

    function updateSummary(summary) {
        const data = summary || summaryFallback;
        $('#vsBestTotalRows').text(formatInteger(data.total_rows));
        $('#vsBestCompetitiveCount').text(formatInteger(data.competitive_count));
        $('#vsBestAboveCount').text(formatInteger(data.above_count));
        $('#vsBestPotentialTotal').text(formatRupiah(data.total_potential_difference_idr));
    }

    const table = $('#vsBestTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: {
            url: '{{ route("purchasing.comparison.vs-best.data") }}',
            data: function(d) {
                Object.assign(d, filterParams);
            },
            dataSrc: function(json) {
                updateSummary(json.summary);
                return json.data || [];
            }
        },
        columns: [
            { data: 'material_display', name: 'current_pr_items.material_name', className: 'text-start' },
            { data: 'current_price_display', name: 'current_price_idr', className: 'text-end', searchable: false },
            { data: 'best_price_display', name: 'best_price_idr', className: 'text-end', searchable: false },
            { data: 'diff_display', name: 'diff_idr_per_kg', className: 'text-center', searchable: false },
            { data: 'potential_difference_display', name: 'potential_difference_idr', className: 'text-end', searchable: false },
            { data: 'status_badge', name: 'diff_percent', className: 'text-center', searchable: false },
            { data: 'action', name: 'action', className: 'text-center', orderable: false, searchable: false }
        ],
        language: {},
        pageLength: 25,
        order: [[4, 'desc']]
    });

    document.getElementById('vsBestMonthRange')?.addEventListener('adasi:date-range-commit', (event) => {
        filterParams.date_from = event.detail.start;
        filterParams.date_to = event.detail.end;
        const url = new URL(document.getElementById('vsBestFilterForm').action, window.location.origin);
        if (event.detail.start) url.searchParams.set('date_from', event.detail.start);
        if (event.detail.end) url.searchParams.set('date_to', event.detail.end);
        window.history.replaceState({}, '', url);
        table.ajax.reload();
    });
});
</script>
@endpush
