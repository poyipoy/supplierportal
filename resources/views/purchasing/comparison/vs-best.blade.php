@extends('layouts.app')
@section('title', 'vs Best Price - ADASI Portal')
@section('page-title', 'Price Comparison')

@php
    $formatRupiah = fn ($value) => $value !== null ? 'Rp ' . number_format($value, 0, ',', '.') : '-';
    $formatNumber = fn ($value, $decimals = 1) => $value !== null ? number_format($value, $decimals, ',', '.') : '-';
@endphp

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header
        title="Current vs Best Price"
        description="Prioritize price gaps against the historical best after exchange-rate conversion."
        eyebrow="Purchasing"
    />
    <x-purchasing.comparison-tabs active="vs-best" />

<x-ui.card
    title="Current Price vs Historical Best Price"
    :description="'Comparison uses IDR/kg after exchange-rate conversion. Competitive is within ' . $formatNumber($competitiveThreshold) . '%.'"
>
    <x-slot:actions>
        <form method="GET" action="{{ route('purchasing.comparison.vs-best') }}" class="tw-flex tw-flex-wrap tw-items-end tw-gap-3">
            <x-ui.input type="month" name="date_from" label="From Month" :value="$dateFromInput" />
            <x-ui.input type="month" name="date_to" label="To Month" :value="$dateToInput" />
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="submit" size="sm"><i class="bi bi-filter"></i> Apply Filter</x-ui.button>
                <x-ui.button :href="route('purchasing.comparison.vs-best')" variant="ghost" size="sm"><i class="bi bi-arrow-counterclockwise"></i> Reset</x-ui.button>
            </div>
        </form>
    </x-slot:actions>

    <div class="tw-grid tw-gap-4 sm:tw-grid-cols-2 xl:tw-grid-cols-4">
        <x-ui.metric-card label="Total Compared Data" :value="$summary['total_rows']" icon="bi-list-check" tone="neutral" value-id="vsBestTotalRows" />
        <x-ui.metric-card label="Competitive / Safe" :value="$summary['competitive_count']" icon="bi-shield-check" tone="success" value-id="vsBestCompetitiveCount" />
        <x-ui.metric-card label="Above History" :value="$summary['above_count']" icon="bi-graph-up-arrow" tone="warning" value-id="vsBestAboveCount" />
        <x-ui.metric-card label="Potential Total Difference" :value="$formatRupiah($summary['total_potential_difference_idr'])" icon="bi-cash-stack" tone="primary" value-id="vsBestPotentialTotal" />
    </div>
</x-ui.card>

<x-ui.data-table title="Price Benchmark Details" description="Server-side results are ordered by the largest potential difference.">
            <table class="table table-hover align-middle mb-0 w-100 tw-text-ui-sm" id="vsBestTable">
                <thead class="table-light text-center">
                    <tr>
                        <th class="text-start">Material</th>
                        <th>Current Price</th>
                        <th>Historical Best Price</th>
                        <th>Difference IDR/kg</th>
                        <th>Potential Total Difference</th>
                        <th>Status</th>
                        <th>Action</th>
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
        return 'Rp ' + Number(value).toLocaleString('id-ID', { maximumFractionDigits: 0 });
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

    $('#vsBestTable').DataTable({
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
});
</script>
@endpush
