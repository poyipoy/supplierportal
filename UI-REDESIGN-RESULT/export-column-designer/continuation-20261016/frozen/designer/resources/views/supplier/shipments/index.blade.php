@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('shipments.copy.shipments_deliveries_adasi_portal'))
@section('page-title', __('shipments.titles.deliveries', []))

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
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.shipments') => null,
    ]" />

    <x-ui.page-header
        :title="__('shipments.copy.my_shipments_deliveries')"
        :eyebrow="__('shipments.copy.logistics_fulfillment')"
        :description="__('shipments.copy.create_and_manage_physical_consignments_consolidate_deliveries_across_multiple_pos_and_track_shippin')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('supplier.shipments.create')" variant="primary" size="sm">
                <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                <span>{{ __('shipments.copy.new_shipment') }}</span>
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
                    placeholder="{{ __('shipments.copy.search_shipment_number_or_po') }}"
                    autocomplete="off"
                    aria-label="{{ __('shipments.copy.search_shipment_history') }}"
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

                <div style="min-width: 220px;">
                    <x-ui.date-range-picker
                        id="supplierShipmentDateRange"
                        start-name="date_from"
                        start-id="supplierShipmentDateFrom"
                        :start-label="__('shipments.copy.from')"
                        start-value="{{ request('date_from') }}"
                        end-name="date_to"
                        end-id="supplierShipmentDateTo"
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
        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="supplierShipmentTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('shipments.copy.shipment_no') }}</th>
                    <th scope="col">{{ __('shipments.copy.po_references') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.items') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.total_qty') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.actual_weight') }}</th>
                    <th scope="col">{{ __('shipments.copy.shipment_date') }}</th>
                    <th scope="col">{{ __('shipments.copy.est_arrival') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.status') }}</th>
                    <th scope="col" class="text-end" style="width: 140px;">{{ __('shipments.copy.action') }}</th>
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
                        <td class="text-center ui-tabular-nums">{{ $regionalFormatter->number((string) $shp->items->count(), 'plain') }}</td>
                        <td class="text-center fw-bold text-primary ui-tabular-nums">{{ $regionalFormatter->number(number_format($totalQty), 'international') }} pcs</td>
                        <td class="text-center ui-tabular-nums tw-text-on-surface-variant">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalKg), 'decimal') }} Kg</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $shp->shipment_date ? $regionalFormatter->date($shp->shipment_date, 'human') : '-' }}</td>
                        <td class="tw-text-on-surface-variant ui-tabular-nums">{{ $shp->estimated_arrival_date ? $regionalFormatter->date($shp->estimated_arrival_date, 'human') : '-' }}</td>
                        <td class="text-center">
                            {!! \App\Support\StatusHelper::badge(\App\Support\StatusHelper::shipmentBadge($shp->status), \App\Support\StatusHelper::shipmentLabel($shp->status)) !!}
                        </td>
                        <td class="text-end">
                            @if($isOwner && $shp->status === 'draft')
                                <div class="d-inline-flex align-items-center justify-content-end gap-1">
                                    <form action="{{ route('supplier.shipments.submit', $shp) }}" method="POST" class="draft-submit-form tw-m-0">
                                        @csrf
                                        <button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-submit-draft" aria-label="{{ __('shipments.a11y.submit_draft', ['shipment' => $shp->shipment_number]) }}">
                                            {{ __('shipments.copy.submit') }}
                                        </button>
                                    </form>
                                    <div class="dropdown">
                                        <button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" aria-label="{{ __('shipments.a11y.more_actions', ['shipment' => $shp->shipment_number]) }}">
                                            {{ __('shipments.copy.more') }}
                                        </button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li><a href="{{ route('supplier.shipments.show', $shp) }}" class="dropdown-item">{{ __('shipments.copy.view_details') }}</a></li>
                                            <li><a href="{{ route('supplier.shipments.edit', $shp) }}" class="dropdown-item">{{ __('shipments.copy.edit_draft') }}</a></li>
                                            <li>
                                                <form action="{{ route('supplier.shipments.cancel', $shp) }}" method="POST" class="cancel-form">
                                                    @csrf
                                                    <button type="button" class="dropdown-item text-danger btn-cancel-shipment btn-delete">
                                                        {{ __('shipments.copy.cancel_shipment') }}
                                                    </button>
                                                </form>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            @else
                                <div class="d-inline-flex justify-content-end">
                                    <a href="{{ route('supplier.shipments.show', $shp) }}" class="ui-data-action ui-data-action--primary ui-focus-ring" aria-label="{{ __('shipments.copy.view_shipment', ['number' => $shp->shipment_number]) }}">{{ __('shipments.copy.details') }}</a>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="9" class="text-center py-4 tw-text-on-surface-variant">{{ __('shipments.js.empty_supplier_shipments', ['action' => __('shipments.copy.new_shipment')]) }}</td>
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
                { data: 'shipment_date_display', name: 'shipment_date', className: 'tw-text-on-surface-variant' },
                { data: 'estimated_arrival', name: 'estimated_arrival_date', className: 'tw-text-on-surface-variant' },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],

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
            if (dateFrom || dateTo) {
                const label = (dateFrom && dateTo)
                    ? window.AdasiI18n.t('js.filters.date_range', { from: dateFrom, to: dateTo })
                    : (dateFrom ? window.AdasiI18n.t('js.filters.from', { date: dateFrom }) : window.AdasiI18n.t('js.filters.to', { date: dateTo }));
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
                    title: @json(__('shipments.copy.cancel_this_shipment')),
                    text: @json(__('shipments.copy.are_you_sure_you_want_to_cancel_this_consignment_any_reserved_quantities_will_be_returned_to_the_pur')),
                    confirmText: @js(__('shipments.confirmations.cancel.confirm')),
                    cancelText: @json(__('shipments.copy.cancel'))
                }).then((result) => {
                    if (result.isConfirmed) {
                        form.submit();
                    }
                });
            } else if (confirm(@js(__('shipments.confirmations.cancel.title')."\n\n".__('shipments.confirmations.cancel.body')))) {
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
                    title: @js(__('shipments.confirmations.submit.title')),
                    text: @js(__('shipments.confirmations.submit.body')),
                    confirmText: @json(__('shipments.copy.yes_submit')),
                    cancelText: @json(__('shipments.copy.cancel'))
                }).then((result) => {
                    draftSubmitConfirmationOpen = false;

                    if (result.isConfirmed) {
                        window.AdasiButton?.startLoading($button[0]);
                        form.submit();
                    }
                });
            } else if (confirm(@js(__('shipments.confirmations.submit.title')."\n\n".__('shipments.confirmations.submit.body')))) {
                window.AdasiButton?.startLoading($button[0]);
                form.submit();
            } else {
                draftSubmitConfirmationOpen = false;
            }
        });
    });
</script>
@endpush
