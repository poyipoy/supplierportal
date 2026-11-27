@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('supplier.copy.price_history_adasi_portal'))
@section('page-title', __('supplier.copy.material_price_history'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.price_history') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.copy.purchase_price_history')"
        :eyebrow="__('supplier.copy.commercial_intelligence')"
        :description="__('supplier.copy.monitor_original_quoted_prices_by_material_and_transaction_currency_for_your_company')"
    />

    {{-- Tabs --}}
    <x-supplier.price-history-tabs active="overview" />

    {{-- 2 Metrics Strip --}}
    <div class="tw:grid tw:gap-px tw:overflow-hidden tw:rounded-ui-md tw:border tw:border-outline tw:bg-outline-variant tw:shadow-ui-surface tw:sm:grid-cols-2">
        <x-ui.metric-card
            flat
            :label="__('supplier.copy.materials_offered')"
            :value="number_format($stats['total_materials'] ?? 0, 0, ',', '.')"
            icon="package"
            tone="primary"
        />
        <x-ui.metric-card
            flat
            :label="__('supplier.copy.total_quotation_items')"
            :value="number_format($stats['total_quotations'] ?? 0, 0, ',', '.')"
            icon="receipt"
            tone="success"
        />
    </div>

    {{-- DataTable --}}
    <x-ui.data-table
            :title="__('supplier.copy.materials_and_latest_pricing')"
        :description="__('supplier.copy.original_price_ranges_grouped_by_material_and_transaction_currency')"
    >
        <table class="ui-table ui-table--hover tw:align-middle tw:m-0 tw:text-ui-xs tw:w-full" id="overviewTable">
            <thead>
                <tr>
                    <th scope="col">{{ __('supplier.copy.material_name') }}</th>
                    <th scope="col" class="tw:text-center">{{ __('supplier.copy.currency') }}</th>
                    <th scope="col" class="tw:text-center">{{ __('supplier.copy.total_offers') }}</th>
                    <th scope="col">{{ __('supplier.copy.latest_price_kg_range') }}</th>
                    <th scope="col">{{ __('supplier.copy.last_quoted_date') }}</th>
                    <th scope="col" class="tw:text-center">{{ __('supplier.copy.latest_status') }}</th>
                    <th scope="col" class="tw:text-end" style="width: 120px;">{{ __('common.table.action') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const bootSupplierPriceHistoryIndex = () => {
        const tableEl = document.getElementById('overviewTable');
        if (!tableEl || typeof window.DataTable !== 'function') return;

        new DataTable(tableEl, {
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.price-history.index") }}',
            columns: [
                { data: 'material_name', name: 'material_name', className: 'tw:font-bold tw:text-on-surface' },
                { data: 'currency', name: 'currency', className: 'tw:text-center tw:font-semibold ui-tabular-nums' },
                { data: 'total_quotations', name: 'total_quotations', searchable: false, className: 'tw:text-center tw:font-semibold ui-tabular-nums' },
                { data: 'price_info', name: 'price_info', orderable: false, searchable: false },
                {
                    data: 'last_submitted_at',
                    name: 'last_submitted_at',
                    className: 'ui-tabular-nums tw:text-on-surface-variant',
                    render: function(data) {
                        const dateLocale = window.AdasiI18n.locale === 'id' ? 'id-ID' : 'en-GB';
                        return data ? new Date(data).toLocaleDateString(dateLocale, {day: '2-digit', month: 'short', year: 'numeric'}) : '-';
                    }
                },
                { data: 'latest_status_badge', name: 'latest_status_badge', orderable: false, searchable: false, className: 'tw:text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'tw:text-end' }
            ],
            order: [[0, 'asc'], [1, 'asc']],
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootSupplierPriceHistoryIndex, { once: true });
    } else {
        bootSupplierPriceHistoryIndex();
    }
})();
</script>
@endpush
