@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Shipments & Logistics - ADASI Portal')
@section('page-title', 'Shipments & Logistics')

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
        'Dashboard' => route('purchasing.dashboard'),
        'Shipments' => null,
    ]" />

    <x-ui.page-header
        title="Physical Shipments & Deliveries"
        eyebrow="Logistics Management"
        description="Monitor physical deliveries across suppliers, verify shipping documentation sets, and confirm port/warehouse arrivals."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('purchasing.export.shipments')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportShipmentsBtn"
                :data-export-url="route('purchasing.export.shipments')"
                data-export-source-singular="shipment"
                data-export-source-plural="shipments"
                data-export-count-table="#shipmentTable"
                data-export-row-label="shipment rows"
                data-export-row-explanation="Each shipment will be exported with consolidated POs and fulfillment metrics."
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
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
                    placeholder="Search shipment, PO, or supplier..."
                    autocomplete="off"
                    aria-label="Search shipment registry"
                    value="{{ request('search') }}"
                >
                <x-ui.button type="button" size="sm" id="searchShipmentBtn" aria-label="Search shipments">
                    Search
                </x-ui.button>
            </div>
        </x-slot:search>

        <x-slot:filters>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div style="min-width: 170px;">
                    <select id="filter_status" class="form-select form-select-sm" aria-label="Filter by Status">
                        <option value="">All Statuses</option>
                        <option value="draft" @selected(request('status') === 'draft')>Draft</option>
                        <option value="submitted" @selected(request('status') === 'submitted')>In Transit</option>
                        <option value="arrived" @selected(request('status') === 'arrived')>Arrived at Plant</option>
                        <option value="cancelled" @selected(request('status') === 'cancelled')>Cancelled</option>
                    </select>
                </div>

                <div style="min-width: 200px;">
                    <select id="filter_supplier" class="form-select form-select-sm" aria-label="Filter by Supplier">
                        <option value="">All Suppliers</option>
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
                        start-label="From"
                        start-value="{{ request('date_from') }}"
                        end-name="date_to"
                        end-id="shipmentDateTo"
                        end-label="To"
                        end-value="{{ request('date_to') }}"
                        compact
                    />
                </div>

                <x-ui.button type="button" variant="ghost" size="sm" id="resetFilter" class="shipment-filter-reset">
                    <x-ui.icon name="rotate-ccw" />
                    <span>Reset</span>
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
                    <th scope="col">Shipment No.</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Consolidated POs</th>
                    <th scope="col" class="text-center">Items</th>
                    <th scope="col" class="text-center">Total Qty</th>
                    <th scope="col" class="text-center">Actual Weight</th>
                    <th scope="col">Shipment Date</th>
                    <th scope="col">Est. Arrival</th>
                    <th scope="col">Actual Arrival</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-end" style="width: 80px;">Action</th>
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
                        <td class="text-center ui-tabular-nums">{{ $shp->items->count() }}</td>
                        <td class="text-center fw-bold text-primary ui-tabular-nums">{{ number_format($totalQty) }} pcs</td>
                        <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ \App\Support\NumberFormat::maxDecimals($totalKg) }} Kg</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $shp->shipment_date ? $shp->shipment_date->format('d M Y') : '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $shp->estimated_arrival_date ? $shp->estimated_arrival_date->format('d M Y') : '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">
                            @if($shp->actual_arrival_date)
                                <span class="text-success fw-bold">{{ $shp->actual_arrival_date->format('d M Y') }}</span>
                            @else
                                <span class="tw-text-outline">-</span>
                            @endif
                        </td>
                        <td class="text-center">
                            {!! \App\Support\StatusHelper::badge(\App\Support\StatusHelper::shipmentBadge($shp->status), \App\Support\StatusHelper::shipmentLabel($shp->status)) !!}
                        </td>
                        <td class="text-end">
                            <a href="{{ route('purchasing.shipments.show', $shp) }}" class="ui-data-action ui-data-action--primary">Details</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center py-4 tw-text-on-surface-variant">No shipments matching filter criteria.</td>
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
                { data: 'shipment_date', name: 'shipment_date', className: 'tw-text-on-surface-variant' },
                { data: 'estimated_arrival', name: 'estimated_arrival_date', className: 'tw-text-on-surface-variant' },
                { data: 'actual_arrival', name: 'actual_arrival_date', className: 'tw-text-on-surface-variant' },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            pageLength: 25,
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
                    'aria-label': `Remove ${label} filter`,
                    text: '×'
                });

                $remove.on('click', clearCallback);
                $chip.append(document.createTextNode(label), $remove);

                return $chip;
            };

            const chips = [];
            if (search) {
                chips.push(createChip(`Search: ${search}`, () => {
                    $('#filter_search').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (statusText) {
                chips.push(createChip(`Status: ${statusText}`, () => {
                    $('#filter_status').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (supplierText) {
                chips.push(createChip(`Supplier: ${supplierText}`, () => {
                    $('#filter_supplier').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (dateFrom || dateTo) {
                const label = (dateFrom && dateTo)
                    ? `Date: ${dateFrom} to ${dateTo}`
                    : (dateFrom ? `From: ${dateFrom}` : `To: ${dateTo}`);
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
