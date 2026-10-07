@extends('layouts.app')
@section('uses-datatables', true)
@section('title', __('purchasing.copy.price_history_adasi_portal'))
@section('page-title', __('purchasing.copy.price_comparison'))

@section('content')
<div id="purchasingComparisonContainer" class="tw-grid tw-gap-6 tw-min-w-0 tw-max-w-full" data-server-tabs-container>
    <x-ui.page-header
        :title="__('purchasing.copy.historical_price_analysis')"
        :description="__('purchasing.copy.trace_supplier_price_movement_by_material_period_and_matching_dimensions')"
        :eyebrow="__('purchasing.copy.purchasing')"
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
