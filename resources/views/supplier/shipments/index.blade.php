@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('shipments.copy.shipments_deliveries_adasi_portal'))
@section('page-title', __('shipments.titles.deliveries', []))

@section('content')
<div class="tw:grid tw:gap-4">
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
            <div class="tw:flex tw:flex-wrap tw:items-center tw:gap-2">
                <x-export.advanced-modal
                    export-key="supplier.shipments"
                    :action="route('supplier.export.shipments')"
                    :filter-selectors="['search'=>'#filter_search','status'=>'#filter_status','start_date'=>'#supplierShipmentDateFrom','end_date'=>'#supplierShipmentDateTo']"
                    table="#supplierShipmentTable"
                    trigger-id="exportSupplierShipmentsBtn"
                />
                <x-ui.button :href="route('supplier.goods-locations.index')" variant="outline" size="sm">
                    <x-slot:leading><x-ui.icon name="map-pin" size="sm" /></x-slot:leading>
                    <span>{{ __('shipments.preshipment.manage_locations') }}</span>
                </x-ui.button>
                <x-ui.button :href="route('supplier.shipments.create')" variant="primary" size="sm">
                    <x-slot:leading><x-ui.icon name="plus" size="sm" /></x-slot:leading>
                    <span>{{ __('shipments.copy.new_shipment') }}</span>
                </x-ui.button>
            </div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Filter Bar --}}
    <x-ui.filter-bar
        id="supplierShipmentsFilterBar"
        mode="live"
        :sticky="true"
        reset-id="resetFilter"
        :reset-label="__('shipments.copy.reset')"
    >
        <x-slot:search>
            <label for="filter_search" class="tw:sr-only">{{ __('shipments.copy.search_shipment_history') }}</label>
            <x-ui.icon name="search" size="sm" class="ui-filter-bar__search-icon" aria-hidden="true" />
            <input
                type="search"
                id="filter_search"
                class="form-control form-control-sm ui-filter-bar__search-input"
                placeholder="{{ __('shipments.copy.search_shipment_number_or_po') }}"
                autocomplete="off"
                aria-label="{{ __('shipments.copy.search_shipment_history') }}"
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

            <div class="tw:w-full tw:min-w-0 tw:sm:w-64">
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
        </x-slot:filters>

        <x-slot:chips>
            <x-ui.filter-chips id="filterChips" />
        </x-slot:chips>
    </x-ui.filter-bar>

    {{-- 3. Balanced Data Table --}}
    <x-ui.data-table density="compact" :sticky="true" :scroll-label="__('shipments.copy.my_shipments_deliveries')">
        <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="supplierShipmentTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('shipments.copy.shipment_no') }}</th>
                    <th scope="col">{{ __('shipments.copy.po_references') }}</th>
                    <th scope="col">{{ __('shipments.copy.supplier_reference') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.items') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.total_qty') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.actual_weight') }}</th>
                    <th scope="col">{{ __('shipments.copy.shipment_date') }}</th>
                    <th scope="col">{{ __('shipments.copy.est_arrival') }}</th>
                    <th scope="col">{{ __('shipments.copy.missing_documents') }}</th>
                    <th scope="col" class="text-center">{{ __('shipments.copy.status') }}</th>
                    <th scope="col" class="tw:text-right text-end ui-sticky-col-right" style="width: 140px;">{{ __('common.table.action') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse($shipments as $shp)
                    @php
                        $pos = $shp->purchaseOrders();
                        $totalQty = (int) $shp->items->sum('shipped_qty');
                        $totalKg = (float) $shp->items->sum('actual_weight_kg');
                        $isOwner = (int) $shp->supplier_id === (int) auth()->id();
                        $invoiceDoc = $shp->documents->firstWhere('doc_type', \App\Models\ShipmentDocument::DOC_TYPE_INVOICE);
                        $invoiceMissing = \App\Support\Procurement\ProcurementFeatures::requireCommercialInvoiceForShipment()
                            && ($invoiceDoc === null || $invoiceDoc->latestAttachment === null);
                    @endphp
                    <tr>
                        <td class="fw-bold tw:text-on-surface">
                            <a href="{{ route('supplier.shipments.show', $shp) }}" class="text-primary text-decoration-none">
                                {{ $shp->shipment_number }}
                            </a>
                        </td>
                        <td>
                            @if($pos->isEmpty())
                                <span class="tw:text-on-surface-variant">-</span>
                            @else
                                @foreach($pos as $po)
                                    <span class="ui-status-chip ui-status-chip--neutral me-1">{{ $po->po_number }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="tw:text-on-surface-variant">{{ $shp->supplier_reference ?: '-' }}</td>
                        <td class="text-center ui-tabular-nums">{{ $regionalFormatter->number((string) $shp->items->count(), 'plain') }}</td>
                        <td class="text-center fw-bold text-primary ui-tabular-nums">{{ $regionalFormatter->number(number_format($totalQty), 'international') }} pcs</td>
                        <td class="text-center ui-tabular-nums tw:text-on-surface-variant">{{ $regionalFormatter->number(\App\Support\NumberFormat::maxDecimals($totalKg), 'decimal') }} Kg</td>
                        <td class="tw:text-on-surface-variant ui-tabular-nums">{{ $shp->shipment_date ? $regionalFormatter->date($shp->shipment_date, 'human') : '-' }}</td>
                        <td class="tw:text-on-surface-variant ui-tabular-nums">{{ $shp->estimated_arrival_date ? $regionalFormatter->date($shp->estimated_arrival_date, 'human') : '-' }}</td>
                        <td>{!! \App\Support\StatusHelper::shipmentMissingDocumentsHtml($shp) !!}</td>
                        <td class="text-center">
                            {!! \App\Support\StatusHelper::badge(\App\Support\StatusHelper::shipmentBadge($shp->status), \App\Support\StatusHelper::shipmentLabel($shp->status)) !!}
                        </td>
                        <td class="text-end ui-sticky-col-right">
                            @if($isOwner && $shp->status === 'draft')
                                <div class="d-inline-flex align-items-center justify-content-end gap-1">
                                    <form action="{{ route('supplier.shipments.submit', $shp) }}" method="POST" class="draft-submit-form tw:m-0">
                                        @csrf
                                        <button type="button" class="ui-data-action ui-data-action--primary ui-focus-ring btn-submit-draft" data-invoice-missing="{{ $invoiceMissing ? '1' : '0' }}" aria-label="{{ __('shipments.a11y.submit_draft', ['shipment' => $shp->shipment_number]) }}">
                                            {{ __('shipments.copy.submit') }}
                                        </button>
                                    </form>
                                    <div class="dropdown">
                                        <button type="button" class="ui-data-action ui-focus-ring dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false" aria-label="{{ __('shipments.a11y.more_actions', ['shipment' => $shp->shipment_number]) }}">
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
                                    <x-ui.row-action
                                        :href="route('supplier.shipments.show', $shp)"
                                        :context="$shp->shipment_number"
                                    />
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="11" class="text-center py-4 tw:text-on-surface-variant">{{ __('shipments.js.empty_supplier_shipments', ['action' => __('shipments.copy.new_shipment')]) }}</td>
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
            layout: { topStart: null, topEnd: null, bottomStart: 'info', bottomEnd: 'paging' },
            search: { search: @json((string) request('search', '')) },
            ajax: {
                url: '{{ route("supplier.shipments.index") }}',
                data: function (d) {
                    d.status = $('#filter_status').val();
                    d.date_from = $('#supplierShipmentDateFrom').val();
                    d.date_to = $('#supplierShipmentDateTo').val();
                }
            },
            columns: [
                { data: 'shipment_number_display', name: 'shipment_number', className: 'fw-bold tw:text-on-surface' },
                { data: 'po_references', name: 'po_references', orderable: false },
                { data: 'supplier_reference_display', name: 'supplier_reference' },
                { data: 'items_count', name: 'items_count', className: 'text-center', orderable: false, searchable: false },
                { data: 'total_qty', name: 'total_qty', className: 'text-center', orderable: false, searchable: false },
                { data: 'actual_weight', name: 'actual_weight', className: 'text-center', orderable: false, searchable: false },
                { data: 'shipment_date_display', name: 'shipment_date', className: 'tw:text-on-surface-variant' },
                { data: 'estimated_arrival', name: 'estimated_arrival_date', className: 'tw:text-on-surface-variant' },
                { data: 'missing_documents', name: 'missing_documents', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end ui-sticky-col-right' }
            ],

            order: []
        });

        window.AdasiFilterBar.bindLive('#supplierShipmentsFilterBar', {
            fields: [
                { key: 'search', label: @json(__('shipments.copy.search')), selector: '#filter_search', type: 'search' },
                { key: 'status', label: @json(__('shipments.copy.status')), selector: '#filter_status', type: 'select' },
                { key: 'date_range', label: @json(__('shipments.copy.shipment_date')), type: 'date-range', picker: '#supplierShipmentDateRange', start: '#supplierShipmentDateFrom', end: '#supplierShipmentDateTo' },
            ],
            onFilterChange: () => table.search($('#filter_search').val() || '').draw(),
        });

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
            if ($button.attr('data-invoice-missing') === '1') {
                const invoiceTitle = @js(__('shipments.copy.commercial_invoice_required_title'));
                const invoiceText = @js(__('shipments.copy.commercial_invoice_required_before_submit'));
                if (window.AdasiAlert) {
                    AdasiAlert.warning({ title: invoiceTitle, text: invoiceText });
                } else {
                    alert(`${invoiceTitle}\n\n${invoiceText}`);
                }
                return;
            }
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
