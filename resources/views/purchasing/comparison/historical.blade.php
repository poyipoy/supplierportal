@extends('layouts.app')
@section('uses-datatables', true)
@section('title', 'Price History - ADASI Portal')
@section('page-title', 'Price Comparison')

@section('content')
<div id="purchasingComparisonContainer" class="tw-grid tw-gap-6 tw-min-w-0 tw-max-w-full" data-server-tabs-container>
    <x-ui.page-header
        title="Historical Price Analysis"
        description="Trace supplier price movement by material, period, and matching dimensions."
        eyebrow="Purchasing"
    />
    <x-purchasing.comparison-tabs active="historical" />

    <div id="purchasingComparisonContent" data-server-tabs-content>
        @include('purchasing.comparison._historical_content')
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
