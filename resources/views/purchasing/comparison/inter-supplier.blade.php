@extends('layouts.app')
@section('uses-datatables', true)
@section('title', __('purchasing.copy.inter_supplier_comparison_adasi_portal'))
@section('page-title', __('purchasing.copy.price_comparison'))

@section('content')
<div id="purchasingComparisonContainer" class="tw-grid tw-gap-6 tw-min-w-0 tw-max-w-full" data-server-tabs-container>
    <x-ui.page-header
        :title="__('purchasing.copy.price_comparison')"
        :description="__('purchasing.copy.compare_supplier_offers_for_a_pr_inspect_historical_movement_and_benchmark_current_prices')"
        :eyebrow="__('purchasing.copy.purchasing')"
    />
    <x-purchasing.comparison-tabs active="inter-supplier" />

    <div id="purchasingComparisonContent" data-server-tabs-content>
        @include('purchasing.comparison._inter_supplier_content')
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
