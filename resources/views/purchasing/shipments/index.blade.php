@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('shipments.copy.shipments_logistics_adasi_portal'))
@section('page-title', __('shipments.titles.logistics', []))

@push('styles')
<style>
    .shipment-filter-reset--active {
        background: var(--md-error-container) !important;
        color: var(--md-on-error-container) !important;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
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
            <x-ui.button
                :href="route('purchasing.export.shipments')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportShipmentsBtn"
                :data-export-url="route('purchasing.export.shipments')"
                data-export-source-singular="{{ __('exports.sources.shipment') }}"
                data-export-source-plural="{{ __('exports.sources.shipments') }}"
                data-export-count-table="#shipmentTable"
                data-export-row-label="{{ __('shipments.copy.shipment_rows') }}"
                data-export-row-explanation="{{ __('shipments.copy.each_shipment_will_be_exported_with_consolidated_pos_and_fulfillment_metrics') }}"
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('shipments.copy.export_excel') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Toolbar / Filters --}}
    <x-ui.toolbar :sticky="true">
        <x-slot:search>
            <div class="input-group input-group-sm">
                <span class="input-group-text tw-bg-surface border-end-0 tw-text-outline">
                    <x-ui.icon name="search" size="sm" />
                </span>
                <input
                    type="text"
                    id="filter_search"
                    class="form-control border-start-0 ps-0"
                    placeholder="{{ __('shipments.copy.search_shipment_po_or_supplier') }}"
                    autocomplete="off"
                    aria-label="{{ __('shipments.copy.search_shipment_registry') }}"
                    value="{{ request('search') }}"
                >
                <x-ui.button type="button" size="sm" id="searchShipmentBtn" aria-label="{{ __('shipments.copy.search_shipments') }}">
                    {{ __('shipments.copy.search') }}
                </x-ui.button>
            </div>
        </x-slot:search>

        <x-slot:filters>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div style="min-width: 170px;">
                    <select id="filter_status" class="form-select form-select-sm" aria-label="{{ __('shipments.copy.filter_by_status') }}">
                        <option value="">{{ __('shipments.copy.all_statuses') }}</option>
                        <option value="draft" @selected(request('status') === 'draft')>{{ __('shipments.copy.draft') }}</option>
                        <option value="submitted" @selected(request('status') === 'submitted')>{{ __('shipments.copy.in_transit') }}</option>
                        <option value="arrived" @selected(request('status') === 'arrived')>{{ __('shipments.copy.arrived_at_plant') }}</option>
                        <option value="cancelled" @selected(request('status') === 'cancelled')>{{ __('shipments.copy.cancelled') }}</option>
                    </select>
                </div>

                <div style="min-width: 200px;">
                    <select id="filter_supplier" class="form-select form-select-sm" aria-label="{{ __('shipments.copy.filter_by_supplier') }}">
                        <option value="">{{ __('shipments.copy.all_suppliers') }}</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}" @selected((string) request('supplier_id') === (string) $supplier->getRouteKey())>
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                <div style="min-width: 220px;">
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

                <x-ui.button type="button" variant="ghost" size="sm" id="resetFilter" class="shipment-filter-reset">
                    <x-ui.icon name="rotate-ccw" />
                    <span>{{ __('shipments.copy.reset') }}</span>
                </x-ui.button>
            </div>
            <div id="filterChips" class="d-none flex-wrap tw-gap-1.5 align-items-center ms-2" aria-live="polite"></div>
        </x-slot:filters>
    </x-ui.toolbar>

    {{-- 3. Balanced Data Table --}}
    <x-ui.data-table density="compact">
        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="shipmentTable">
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
                    <th scope="col" class="text-center">{{ __('shipments.copy.status') }}</th>
                    <th scope="col" class="text-end" style="width: 80px;">{{ __('shipments.copy.action') }}</th>
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
                        <td class="fw-bold tw-text-on-surface">
                            <a href="{{ route('purchasing.shipments.show', $shp) }}" class="text-primary text-decoration-none">
                                {{ $shp->shipment_number }}
                            </a>
                        </td>
                        <td class="fw-semibold">{{ $shp->supplier->company_name ?? $shp->supplier->name ?? '-' }}</td>
                        <td>
                            @if($pos->isEmpty())
                                <span class="tw-text-outline">-</span>
                            @else
                                @foreach($pos as $po)
                                    <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $po->po_number }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="text-center ui-tabular-nums">{{ $regionalFormatter->number((string) $shp->items->count(), 'plain') }}</td>
                        <td class="text-center fw-bold text-primary ui-tabular-nums">{{ $regionalFormatter->number(number_format($totalQty), 'international') }} pcs</td>
                        <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalKg), 'decimal') }} Kg</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $shp->shipment_date ? $regionalFormatter->date($shp->shipment_date, 'human') : '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $shp->estimated_arrival_date ? $regionalFormatter->date($shp->estimated_arrival_date, 'human') : '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">
                            @if($shp->actual_arrival_date)
                                <span class="text-success fw-bold">{{ $regionalFormatter->date($shp->actual_arrival_date, 'human') }}</span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            {!! \App\Support\StatusHelper::badge(\App\Support\StatusHelper::shipmentBadge($shp->status), \App\Support\StatusHelper::shipmentLabel($shp->status)) !!}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('purchasing.shipments.show', $shp) }}" class="ui-data-action ui-data-action--primary">{{ __('shipments.copy.details') }}</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center py-4 tw-text-on-surface-variant">{{ __('shipments.copy.no_shipments_matching_filter_criteria') }}</td>
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
            ajax: {
                url: '{{ route("purchasing.shipments.index") }}',
                data: function (d) {
                    d.search = $('#filter_search').val();
                    d.status = $('#filter_status').val();
                    d.supplier_id = $('#filter_supplier').val();
                    d.date_from = $('#shipmentDateFrom').val();
                    d.date_to = $('#shipmentDateTo').val();
                }
            },
            columns: [
                { data: 'shipment_number_display', name: 'shipment_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'supplier_name', name: 'supplier.name', orderable: false },
                { data: 'consolidated_pos', name: 'consolidated_pos', orderable: false },
                { data: 'items_count', name: 'items_count', className: 'text-center', orderable: false, searchable: false },
                { data: 'total_qty', name: 'total_qty', className: 'text-center', orderable: false, searchable: false },
                { data: 'actual_weight', name: 'actual_weight', className: 'text-center', orderable: false, searchable: false },
                { data: 'shipment_date_display', name: 'shipment_date', className: 'tw-text-on-surface-variant' },
                { data: 'estimated_arrival', name: 'estimated_arrival_date', className: 'tw-text-on-surface-variant' },
                { data: 'actual_arrival', name: 'actual_arrival_date', className: 'tw-text-on-surface-variant' },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],

            order: []
        });

        // Filter event listeners
        $('#filter_status, #filter_supplier').on('change', function () {
            updateFilterChips();
            table.draw();
        });

        document.getElementById('shipmentDateRange')?.addEventListener('adasi:date-range-commit', function () {
            updateFilterChips();
            table.draw();
        });

        $('#searchShipmentBtn').on('click', function () {
            updateFilterChips();
            table.draw();
        });

        $('#filter_search').on('keyup', function (e) {
            if (e.key === 'Enter') {
                updateFilterChips();
                table.draw();
            }
        });

        $('#resetFilter').on('click', function () {
            $('#filter_search').val('');
            $('#filter_status').val('');
            $('#filter_supplier').val('');
            $('#shipmentDateFrom').val('');
            $('#shipmentDateTo').val('');
            document.getElementById('shipmentDateRange')?.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
            updateFilterChips();
            table.draw();
        });

        function updateFilterChips() {
            const search = $('#filter_search').val().trim();
            const statusText = $('#filter_status option:selected').val() ? $('#filter_status option:selected').text().trim() : null;
            const supplierText = $('#filter_supplier option:selected').val() ? $('#filter_supplier option:selected').text().trim() : null;
            const dateFrom = $('#shipmentDateFrom').val();
            const dateTo = $('#shipmentDateTo').val();

            const createChip = (label, clearCallback) => {
                const $chip = $('<span>', {
                    class: 'ui-status-chip ui-status-chip--info'
                });
                const $remove = $('<button>', {
                    type: 'button',
                    class: 'ui-focus-ring tw-inline-flex tw-h-5 tw-w-5 tw-items-center tw-justify-center tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-p-0 tw-text-primary hover:tw-bg-primary/10',
                    'aria-label': window.AdasiI18n.t('js.filters.remove', { label }),
                    text: '×'
                });

                $remove.on('click', clearCallback);
                $chip.append(document.createTextNode(label), $remove);

                return $chip;
            };

            const chips = [];
            if (search) {
                chips.push(createChip(window.AdasiI18n.t('js.filters.search', { search }), () => {
                    $('#filter_search').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (statusText) {
                chips.push(createChip(@js(__('common.final_copy.status')) + ' ' + statusText, () => {
                    $('#filter_status').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (supplierText) {
                chips.push(createChip(window.AdasiI18n.t('js.filters.supplier', { supplier: supplierText }), () => {
                    $('#filter_supplier').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (dateFrom || dateTo) {
                const label = (dateFrom && dateTo)
                    ? window.AdasiI18n.t('js.filters.date_range', { from: dateFrom, to: dateTo })
                    : (dateFrom ? window.AdasiI18n.t('js.filters.from', { date: dateFrom }) : window.AdasiI18n.t('js.filters.to', { date: dateTo }));
                chips.push(createChip(label, () => {
                    $('#shipmentDateFrom').val('');
                    $('#shipmentDateTo').val('');
                    document.getElementById('shipmentDateRange')?.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
                    updateFilterChips();
                    table.draw();
                }));
            }

            const $container = $('#filterChips');
            $container.empty();
            if (chips.length > 0) {
                chips.forEach(c => $container.append(c));
                $container.removeClass('d-none').addClass('d-flex');
                $('#resetFilter').addClass('shipment-filter-reset--active');
            } else {
                $container.removeClass('d-flex').addClass('d-none');
                $('#resetFilter').removeClass('shipment-filter-reset--active');
            }
        }

        // Initialize filter chips if initial server query had values
        updateFilterChips();
    });
</script>
@endpush
