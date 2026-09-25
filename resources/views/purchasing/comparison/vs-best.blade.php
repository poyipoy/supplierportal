@extends('layouts.app')
@section('uses-datatables', true)
@section('title', 'vs Best Price - ADASI Portal')
@section('page-title', 'Price Comparison')

@section('content')
<div id="purchasingComparisonContainer" class="tw-grid tw-gap-6 tw-min-w-0 tw-max-w-full" data-server-tabs-container>
    <x-ui.page-header
        title="Current vs Best Price"
        description="Prioritize price gaps against the historical best after exchange-rate conversion."
        eyebrow="Purchasing"
    />
    <x-purchasing.comparison-tabs active="vs-best" />

    <div id="purchasingComparisonContent" data-server-tabs-content>
        @include('purchasing.comparison._vs_best_content')
    </div>
</div>
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
@include('purchasing.comparison._scripts')
<script>
if (typeof AdasiServerTabs !== 'undefined') {
    AdasiServerTabs.init('#purchasingComparisonContainer');
} else {
    document.addEventListener('DOMContentLoaded', function() {
        if (typeof AdasiServerTabs !== 'undefined') {
            AdasiServerTabs.init('#purchasingComparisonContainer');
        }
    });
}
</script>
@endpush
