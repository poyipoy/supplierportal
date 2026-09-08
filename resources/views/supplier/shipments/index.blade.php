@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Shipments & Deliveries - ADASI Portal')
@section('page-title', 'Shipments & Deliveries')

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
        'Dashboard' => route('supplier.dashboard'),
        'Shipments' => null,
    ]" />

    <x-ui.page-header
        title="My Shipments & Deliveries"
        eyebrow="Logistics & Fulfillment"
        description="Create and manage physical consignments, consolidate deliveries across multiple POs, and track shipping documentation."
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.shipments.create')" variant="primary" size="sm">
                <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                <span>New Shipment</span>
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
                    placeholder="Search shipment number or PO..."
                    autocomplete="off"
                    aria-label="Search shipment history"
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

                <div style="min-width: 220px;">
                    <x-ui.date-range-picker
                        id="supplierShipmentDateRange"
                        start-name="date_from"
                        start-id="supplierShipmentDateFrom"
                        start-label="From"
                        start-value="{{ request('date_from') }}"
                        end-name="date_to"
                        end-id="supplierShipmentDateTo"
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
        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="supplierShipmentTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">Shipment No.</th>
                    <th scope="col">PO References</th>
                    <th scope="col" class="text-center">Items</th>
                    <th scope="col" class="text-center">Total Qty</th>
                    <th scope="col" class="text-center">Actual Weight</th>
                    <th scope="col">Shipment Date</th>
                    <th scope="col">Est. Arrival</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-end" style="width: 140px;">Action</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipments as $shp)
                    @php
                        $pos = $shp->purchaseOrders();
                        $totalQty = (int) $shp->items->sum('shipped_qty');
                        $totalKg = (float) $shp->items->sum('actual_weight_kg');
                        $isOwner = (int) $shp->supplier_id === (int) auth()->id();
                    @endphp
                    <tr>
                        <td class="fw-bold tw-text-on-surface">
                            <a href="{{ route('supplier.shipments.show', $shp) }}" class="text-primary text-decoration-none">
                                {{ $shp->shipment_number }}
                            </a>
                        </td>
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
                        <td class="text-center">
                            {!! \App\Support\StatusHelper::badge(\App\Support\StatusHelper::shipmentBadge($shp->status), \App\Support\StatusHelper::shipmentLabel($shp->status)) !!}
                        </td>
                        <td class="text-end">
                            @if($isOwner && $shp->status === 'draft')
                                <div class="d-inline-flex align-items-center justify-content-end gap-1">
                                    <form action="{{ route('supplier.shipments.submit', $shp) }}" method="POST" class="draft-submit-form tw-m-0">
                                        @csrf
                                        <button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-submit-draft" aria-label="Submit draft {{ $shp->shipment_number }}">
                                            Submit
                                        </button>
                                    </form>
                                    <div class="dropdown">
                                        <button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="More actions for {{ $shp->shipment_number }}">
                                            More
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a href="{{ route('supplier.shipments.show', $shp) }}" class="dropdown-item">View details</a></li>
                                            <li><a href="{{ route('supplier.shipments.edit', $shp) }}" class="dropdown-item">Edit draft</a></li>
                                            <li>
                                                <form action="{{ route('supplier.shipments.cancel', $shp) }}" method="POST" class="cancel-form">
                                                    @csrf
                                                    <button type="button" class="dropdown-item text-danger btn-cancel-shipment btn-delete">
                                                        Cancel shipment
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            @else
                                <div class="d-inline-flex justify-content-end">
                                    <a href="{{ route('supplier.shipments.show', $shp) }}" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="View {{ $shp->shipment_number }}">Details</a>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center py-4 tw-text-on-surface-variant">No shipments created yet. Click "New Shipment" to initiate a delivery.</td>
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
        var table = $('#supplierShipmentTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("supplier.shipments.index") }}',
                data: function (d) {
                    d.search = $('#filter_search').val();
                    d.status = $('#filter_status').val();
                    d.date_from = $('#supplierShipmentDateFrom').val();
                    d.date_to = $('#supplierShipmentDateTo').val();
                }
            },
            columns: [
                { data: 'shipment_number_display', name: 'shipment_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'po_references', name: 'po_references', orderable: false },
                { data: 'items_count', name: 'items_count', className: 'text-center', orderable: false, searchable: false },
                { data: 'total_qty', name: 'total_qty', className: 'text-center', orderable: false, searchable: false },
                { data: 'actual_weight', name: 'actual_weight', className: 'text-center', orderable: false, searchable: false },
                { data: 'shipment_date', name: 'shipment_date', className: 'tw-text-on-surface-variant' },
                { data: 'estimated_arrival', name: 'estimated_arrival_date', className: 'tw-text-on-surface-variant' },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            pageLength: 25,
            order: []
        });

        $('#filter_status').on('change', function () {
            updateFilterChips();
            table.draw();
        });

        document.getElementById('supplierShipmentDateRange')?.addEventListener('adasi:date-range-commit', function () {
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
            $('#supplierShipmentDateFrom').val('');
            $('#supplierShipmentDateTo').val('');
            document.getElementById('supplierShipmentDateRange')?.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
            updateFilterChips();
            table.draw();
        });

        function updateFilterChips() {
            const search = $('#filter_search').val().trim();
            const statusText = $('#filter_status option:selected').val() ? $('#filter_status option:selected').text().trim() : null;
            const dateFrom = $('#supplierShipmentDateFrom').val();
            const dateTo = $('#supplierShipmentDateTo').val();

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
            if (dateFrom || dateTo) {
                const label = (dateFrom && dateTo)
                    ? `Date: ${dateFrom} to ${dateTo}`
                    : (dateFrom ? `From: ${dateFrom}` : `To: ${dateTo}`);
                chips.push(createChip(label, () => {
                    $('#supplierShipmentDateFrom').val('');
                    $('#supplierShipmentDateTo').val('');
                    document.getElementById('supplierShipmentDateRange')?.dispatchEvent(new CustomEvent('adasi:calendar-reset'));
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

        updateFilterChips();

        // ADASI Alert cancel confirmation
        $(document).on('click', '.btn-cancel-shipment, .btn-delete', function() {
            const form = $(this).closest('form');
            if (window.AdasiAlert) {
                AdasiAlert.confirmDanger({
                    title: @json('Cancel this Shipment?'),
                    text: @json('Are you sure you want to cancel this consignment? Any reserved quantities will be returned to the purchase order balance.'),
                    confirmText: @json('Yes, Cancel!'),
                    cancelText: @json('Cancel')
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            } else if (confirm(@json("Cancel this Shipment?\n\nAre you sure you want to cancel this consignment? Any reserved quantities will be returned to the purchase order balance."))) {
                form.submit();
            }
        });

        // ADASI Alert submit confirmation
        let draftSubmitConfirmationOpen = false;
        $(document).on('click', '.btn-submit-draft', function() {
            const $button = $(this);
            if (draftSubmitConfirmationOpen || $button.data('submitting')) {
                return;
            }

            const form = $button.closest('form');
            draftSubmitConfirmationOpen = true;

            if (window.AdasiAlert) {
                AdasiAlert.confirm({
                    title: @json('Submit Shipment Delivery?'),
                    text: @json('Submitting this shipment locks allocated quantities and notifies Purchasing and QC that goods are in transit.'),
                    confirmText: @json('Yes, Submit!'),
                    cancelText: @json('Cancel')
                }).then((result) => {
                    draftSubmitConfirmationOpen = false;

                    if (result.isConfirmed) {
                        window.AdasiButton?.startLoading($button[0]);
                        form.submit();
                    }
                });
            } else if (confirm(@json("Submit Shipment Delivery?\n\nSubmitting this shipment locks allocated quantities and notifies Purchasing and QC that goods are in transit."))) {
                window.AdasiButton?.startLoading($button[0]);
                form.submit();
            } else {
                draftSubmitConfirmationOpen = false;
            }
        });
    });
</script>
@endpush
