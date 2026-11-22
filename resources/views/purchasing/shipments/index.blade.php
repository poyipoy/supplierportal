@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('shipments.copy.shipments_logistics_adasi_portal'))
@section('page-title', __('shipments.titles.logistics', []))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- 1. Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('purchasing.dashboard'),
        __('purchasing.breadcrumbs.shipments') => null,
    ]" />

    <x-ui.page-header
        :title="__('shipments.copy.physical_shipments_deliveries')"
        :eyebrow="__('shipments.copy.logistics_management')"
        :description="__('shipments.copy.monitor_physical_deliveries_across_suppliers_verify_shipping_documentation_sets_and_confirm_port_war')"
    >
        <x-slot:actions>
<x-export.advanced-modal export-key="purchasing.shipments" :action="route('purchasing.export.shipments')" :suppliers="$suppliers" :filter-selectors="['search'=>'#filter_search','status'=>'#filter_status','supplier_id'=>'#filter_supplier','start_date'=>'#shipmentDateFrom','end_date'=>'#shipmentDateTo']" table="#shipmentTable" trigger-id="exportShipmentsBtn" />
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Filter Bar --}}
    @php
        $shipmentInitialAdvCount = (request()->filled('supplier_id') ? 1 : 0)
            + ((request()->filled('date_from') || request()->filled('date_to')) ? 1 : 0);
    @endphp
    <x-ui.filter-bar
        id="purchasingShipmentsFilterBar"
        mode="live"
        :sticky="true"
        advanced-id="purchasingShipmentsMoreFilters"
        :advanced-count="$shipmentInitialAdvCount"
        :advanced-open="$shipmentInitialAdvCount > 0"
        reset-id="resetFilter"
        :reset-label="__('shipments.copy.reset')"
    >
        <x-slot:search>
            <label for="filter_search" class="tw:sr-only">{{ __('shipments.copy.search_shipment_registry') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                id="filter_search"
                class="form-control form-control-sm ui-filter-bar__search-input"
                placeholder="{{ __('shipments.copy.search_shipment_po_or_supplier') }}"
                autocomplete="off"
                aria-label="{{ __('shipments.copy.search_shipment_registry') }}"
                value="{{ request('search') }}"
            >
        </x-slot:search>

        <x-slot:filters>
            <label for="filter_status" class="tw:sr-only">{{ __('shipments.copy.filter_by_status') }}</label>
            <select id="filter_status" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-44" aria-label="{{ __('shipments.copy.filter_by_status') }}">
                <option value="">{{ __('shipments.copy.all_statuses') }}</option>
                <option value="draft" @selected(request('status') === 'draft')>{{ __('shipments.copy.draft') }}</option>
                <option value="submitted" @selected(request('status') === 'submitted')>{{ __('shipments.copy.in_transit') }}</option>
                <option value="arrived" @selected(request('status') === 'arrived')>{{ __('shipments.copy.arrived_at_plant') }}</option>
                <option value="cancelled" @selected(request('status') === 'cancelled')>{{ __('shipments.copy.cancelled') }}</option>
            </select>
        </x-slot:filters>

        <x-slot:advanced>
            <div>
                <label for="filter_supplier" class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('shipments.copy.supplier') }}</label>
                <select id="filter_supplier" class="form-select form-select-sm tw:w-full" aria-label="{{ __('shipments.copy.filter_by_supplier') }}">
                    <option value="">{{ __('shipments.copy.all_suppliers') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->getRouteKey() }}" @selected((string) request('supplier_id') === (string) $supplier->getRouteKey())>
                            {{ $supplier->name }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="tw:block tw:text-ui-xs tw:font-semibold tw:text-on-surface tw:mb-1.5">{{ __('shipments.copy.shipment_date') }}</label>
                <x-ui.date-range-picker
                    id="shipmentDateRange"
                    start-name="date_from"
                    start-id="shipmentDateFrom"
                    :start-label="__('shipments.copy.from')"
                    start-value="{{ request('date_from') }}"
                    end-name="date_to"
                    end-id="shipmentDateTo"
                    :end-label="__('shipments.copy.to')"
                    end-value="{{ request('date_to') }}"
                    compact
                />
            </div>
        </x-slot:advanced>

        <x-slot:chips>
            <x-ui.filter-chips id="filterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- 3. Balanced Data Table --}}
    <x-ui.data-table density="compact">
        <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="shipmentTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('shipments.copy.shipment_no') }}</th>
                    <th scope="col">{{ __('shipments.copy.supplier') }}</th>
                    <th scope="col">{{ __('shipments.copy.consolidated_pos') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.items') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.total_qty') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.actual_weight') }}</th>
                    <th scope="col">{{ __('shipments.copy.shipment_date') }}</th>
                    <th scope="col">{{ __('shipments.copy.est_arrival') }}</th>
                    <th scope="col">{{ __('shipments.copy.actual_arrival') }}</th>
                    <th scope="col">{{ __('shipments.copy.missing_documents') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.status') }}</th>
                    <th scope="col" class="tw:text-right text-end" style="width: 96px;">{{ __('common.table.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipments as $shp)
                    @php
                        $pos = $shp->purchaseOrders();
                        $totalQty = (int) $shp->items->sum('shipped_qty');
                        $totalKg = (float) $shp->items->sum('actual_weight_kg');
                    @endphp
                    <tr>
                        <td class="fw-bold tw:text-on-surface">
                            <a href="{{ route('purchasing.shipments.show', $shp) }}" class="text-primary text-decoration-none">
                                {{ $shp->shipment_number }}
                            </a>
                        </td>
                        <td class="fw-semibold">{{ $shp->supplier->company_name ?? $shp->supplier->name ?? '-' }}</td>
                        <td>
                            @if($pos->isEmpty())
                                <span class="tw:text-on-surface-variant">-</span>
                            @else
                                @foreach($pos as $po)
                                    <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $po->po_number }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-center ui-tabular-nums">{{ $regionalFormatter->number((string) $shp->items->count(), 'plain') }}</td>
                        <td class="text-center fw-bold text-primary ui-tabular-nums">{{ $regionalFormatter->number(number_format($totalQty), 'international') }} pcs</td>
                        <td class="text-center ui-tabular-nums tw:text-on-surface-variant">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalKg), 'decimal') }} Kg</td>
                        <td class="tw:text-on-surface-variant ui-tabular-nums">{{ $shp->shipment_date ? $regionalFormatter->date($shp->shipment_date, 'human') : '-' }}</td>
                        <td class="tw:text-on-surface-variant ui-tabular-nums">{{ $shp->estimated_arrival_date ? $regionalFormatter->date($shp->estimated_arrival_date, 'human') : '-' }}</td>
                        <td class="tw:text-on-surface-variant ui-tabular-nums">
                            @if($shp->actual_arrival_date)
                                <span class="text-success fw-bold">{{ $regionalFormatter->date($shp->actual_arrival_date, 'human') }}</span>
                            @else
                                <span class="tw:text-on-surface-variant">-</span>
                            @endif
                        </td>
                        <td>{!! \App\Support\StatusHelper::shipmentMissingDocumentsHtml($shp) !!}</td>
                        <td class="text-center">
                            {!! \App\Support\StatusHelper::badge(\App\Support\StatusHelper::shipmentBadge($shp->status), \App\Support\StatusHelper::shipmentLabel($shp->status)) !!}
                        </td>
                        <td class="tw:text-right text-end">
                            <x-ui.row-action
                                :href="route('purchasing.shipments.show', $shp)"
                                :context="$shp->shipment_number"
                            />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="text-center py-4 tw:text-on-surface-variant">{{ __('shipments.copy.no_shipments_matching_filter_criteria') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function () {
        var table = $('#shipmentTable').DataTable({
            processing: true,
            serverSide: true,
            dom: 'rtip',
            search: { search: @json((string) request('search', '')) },
            ajax: {
                url: '{{ route("purchasing.shipments.index") }}',
                data: function (d) {
                    d.status = $('#filter_status').val();
                    d.supplier_id = $('#filter_supplier').val();
                    d.date_from = $('#shipmentDateFrom').val();
                    d.date_to = $('#shipmentDateTo').val();
                }
            },
            columns: [
                { data: 'shipment_number_display', name: 'shipment_number', className: 'fw-bold tw:text-on-surface' },
                { data: 'supplier_name', name: 'supplier.name', orderable: false },
                { data: 'consolidated_pos', name: 'consolidated_pos', orderable: false },
                { data: 'items_count', name: 'items_count', className: 'text-center', orderable: false, searchable: false },
                { data: 'total_qty', name: 'total_qty', className: 'text-center', orderable: false, searchable: false },
                { data: 'actual_weight', name: 'actual_weight', className: 'text-center', orderable: false, searchable: false },
                { data: 'shipment_date_display', name: 'shipment_date', className: 'tw:text-on-surface-variant' },
                { data: 'estimated_arrival', name: 'estimated_arrival_date', className: 'tw:text-on-surface-variant' },
                { data: 'actual_arrival', name: 'actual_arrival_date', className: 'tw:text-on-surface-variant' },
                { data: 'missing_documents', name: 'missing_documents', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],

            order: []
        });

        // Filter bar live bindings
        window.AdasiFilterBar.bindLive('#purchasingShipmentsFilterBar', {
            fields: [
                { key: 'search', label: @json(__('shipments.copy.search')), selector: '#filter_search', type: 'search' },
                { key: 'status', label: @json(__('shipments.copy.status')), selector: '#filter_status', type: 'select' },
                { key: 'supplier_id', label: @json(__('shipments.copy.supplier')), selector: '#filter_supplier', type: 'select', advanced: true },
                { key: 'date_range', label: @json(__('shipments.copy.shipment_date')), type: 'date-range', picker: '#shipmentDateRange', start: '#shipmentDateFrom', end: '#shipmentDateTo', advanced: true },
            ],
            onFilterChange: () => table.search($('#filter_search').val() || '').draw(),
        });
    });
</script>
@endpush
