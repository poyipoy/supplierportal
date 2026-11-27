@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('purchasing.copy.purchase_order_list_adasi_portal'))
@section('page-title', __('purchasing.copy.purchase_orders'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        :title="__('purchasing.copy.purchase_orders')"
        :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.track_supplier_orders_reference_requisitions_arrival_targets_and_workflow_statuses')"
    >
        <x-slot:actions>
            <x-export.advanced-modal export-key="purchasing.po" :action="route('purchasing.export.purchase-orders')" :suppliers="$suppliers"
                :filter-selectors="['search' => '#filter_po_number', 'status' => '#filter_status', 'supplier_id' => '#filter_supplier']" table="#poTable" trigger-id="exportPurchaseOrdersBtn" />
            <x-ui.button :href="route('purchasing.purchase-orders.consolidate-awards')" size="sm">{{ __('purchasing.copy.consolidate_selected_items') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Filter Bar --}}
    <x-ui.filter-bar
        id="poFilterBar"
        mode="live"
        :sticky="true"
        reset-id="resetFilter"
        :reset-label="__('purchasing.copy.reset')"
    >
        <x-slot:hidden>
            <input type="hidden" id="filter_start_date" value="{{ request('start_date') }}">
            <input type="hidden" id="filter_end_date" value="{{ request('end_date') }}">
        </x-slot:hidden>

        <x-slot:search>
            <label for="filter_po_number" class="tw:sr-only">{{ __('purchasing.copy.search_po_supplier_or_pr') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                id="filter_po_number"
                class="ui-input ui-input--sm ui-filter-bar__search-input"
                placeholder="{{ __('purchasing.copy.e_g_po_05_2026_001_or_supplier_name') }}"
                autocomplete="off"
                aria-label="{{ __('purchasing.copy.search_po_supplier_or_pr') }}"
                value="{{ request('search', request('po_number')) }}"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="filter_status" class="tw:sr-only">{{ __('purchasing.copy.filter_by_status') }}</label>
            <select id="filter_status" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('purchasing.copy.filter_by_status') }}">
                <option value="">{{ __('purchasing.copy.all_statuses') }}</option>
                <option value="active" @selected(request('status') === 'active')>{{ __('purchasing.copy.active') }}</option>
                <option value="waiting_qc" @selected(request('status') === 'waiting_qc')>{{ __('purchasing.copy.waiting_qc') }}</option>
                <option value="claim_needed" @selected(request('status') === 'claim_needed')>{{ __('purchasing.copy.claim_needed') }}</option>
                <option value="overdue" @selected(request('status') === 'overdue')>{{ __('purchasing.copy.overdue') }}</option>
                <option value="completed" @selected(request('status') === 'completed')>{{ __('purchasing.copy.completed') }}</option>
                <option value="cancelled" @selected(request('status') === 'cancelled')>{{ __('purchasing.copy.cancelled') }}</option>
            </select>

            <label for="filter_supplier" class="tw:sr-only">{{ __('purchasing.copy.filter_by_supplier') }}</label>
            <select id="filter_supplier" class="ui-select ui-select--sm tw:w-full tw:min-w-0 tw:sm:w-48" aria-label="{{ __('purchasing.copy.filter_by_supplier') }}">
                <option value="">{{ __('purchasing.copy.all_suppliers_3a7a53') }}</option>
                @foreach($suppliers as $supplier)
                    <option value="{{ $supplier->getRouteKey() }}" @selected((string) request('supplier_id') === (string) $supplier->getRouteKey())>{{ $supplier->name }}</option>
                @endforeach
            </select>
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips id="filterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- 3. Multi Main Table Workspace (Master + Satellite Preview) --}}
    <x-ui.mmt-workspace
        id="poMmtWorkspace"
        satellite-id="po-mmt-satellite"
        :master-label="__('purchasing.mmt.master_label')"
        :satellite-title="__('purchasing.mmt.satellite_title')"
        :satellite-empty-title="__('purchasing.mmt.satellite_empty_title')"
        :satellite-empty="__('purchasing.mmt.satellite_empty')"
        :master-frameless="true"
    >
        <x-ui.data-table density="compact">
            <table class="ui-table ui-table--hover tw:align-middle tw:mb-0 tw:text-ui-sm tw:w-full" id="poTable">
                <thead>
                    <tr>
                        <th scope="col">{{ __('purchasing.copy.po_number') }}</th>
                        <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                        <th scope="col">{{ __('purchasing.copy.period') }}</th>
                        <th scope="col">{{ __('purchasing.copy.reference_pr') }}</th>
                        <th scope="col">{{ __('purchasing.copy.remark') }}</th>
                        <th scope="col" class="tw:text-end">{{ __('purchasing.copy.total_idr') }}</th>
                        <th scope="col" class="tw:text-center">{{ __('purchasing.copy.status') }}</th>
                        <th scope="col">{{ __('purchasing.copy.estimated_arrival') }}</th>
                        <th scope="col" class="tw:text-end" style="width: 96px;">{{ __('common.table.action') }}</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </x-ui.data-table>
    </x-ui.mmt-workspace>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const bootPurchasingPoIndex = () => {
        const tableEl = document.getElementById('poTable');
        if (!tableEl || typeof window.DataTable !== 'function') return;

        const table = new DataTable(tableEl, {
            processing: true,
            serverSide: true,
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            search: { search: @json((string) request('search', request('po_number', ''))) },
            ajax: {
                url: '{{ route("purchasing.purchase-orders.index") }}',
                data: function (d) {
                    d.status = document.getElementById('filter_status')?.value ?? '';
                    d.supplier_id = document.getElementById('filter_supplier')?.value ?? '';
                    d.start_date = document.getElementById('filter_start_date')?.value || null;
                    d.end_date = document.getElementById('filter_end_date')?.value || null;
                }
            },
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'tw:font-bold tw:text-on-surface' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
                { data: 'period_name', name: 'period_name', orderable: false },
                { data: 'pr_reference', name: 'pr_reference', orderable: false },
                { data: 'remark_display', name: 'remark_display', orderable: false, className: 'tw:text-on-surface-variant' },
                { data: 'total_idr', name: 'total_idr', className: 'tw:text-end tw:font-semibold tw:text-on-surface ui-tabular-nums', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'tw:text-center' },
                { data: 'estimated_date', name: 'estimated_arrival', className: 'tw:text-on-surface-variant' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'tw:text-end' }
            ],
            language: {},

            order: []
        });

        table.on('draw.dt', function () {
            window.AdasiServerTabs?.clearMmtSelection?.('#poMmtWorkspace');
        });

        // Filter bar live bindings
        window.AdasiFilterBar.bindLive('#poFilterBar', {
            fields: [
                { key: 'search', label: @json(__('purchasing.copy.search')), selector: '#filter_po_number', type: 'search' },
                { key: 'status', label: @json(__('purchasing.copy.status')), selector: '#filter_status', type: 'select' },
                { key: 'supplier_id', label: @json(__('purchasing.copy.supplier')), selector: '#filter_supplier', type: 'select' },
                { key: 'date_range', label: @json(__('purchasing.copy.date_created')), type: 'date-range', start: '#filter_start_date', end: '#filter_end_date' },
            ],
            onFilterChange: () => {
                const startVal = document.getElementById('filter_start_date')?.value || '';
                const endVal = document.getElementById('filter_end_date')?.value || '';
                if (window.history?.replaceState) {
                    const nextUrl = new URL(window.location.href);
                    if (startVal) nextUrl.searchParams.set('start_date', startVal); else nextUrl.searchParams.delete('start_date');
                    if (endVal) nextUrl.searchParams.set('end_date', endVal); else nextUrl.searchParams.delete('end_date');
                    window.history.replaceState(window.history.state, '', nextUrl.toString());
                }
                table.search(document.getElementById('filter_po_number')?.value || '').draw();
            },
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootPurchasingPoIndex, { once: true });
    } else {
        bootPurchasingPoIndex();
    }
})();
</script>
@endpush
