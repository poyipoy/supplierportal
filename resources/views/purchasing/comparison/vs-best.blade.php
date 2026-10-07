@extends('layouts.app')
@section('uses-datatables', true)
@section('title', __('purchasing.copy.vs_best_price_adasi_portal'))
@section('page-title', __('purchasing.copy.price_comparison'))

@section('content')
<div id="purchasingComparisonContainer" class="tw-grid tw-gap-6 tw-min-w-0 tw-max-w-full" data-server-tabs-container>
    <x-ui.page-header
        :title="__('purchasing.copy.current_vs_best_price')"
        :description="__('purchasing.copy.prioritize_price_gaps_against_the_historical_best_after_exchange_rate_conversion')"
        :eyebrow="__('purchasing.copy.purchasing')"
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
