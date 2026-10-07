@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('supplier.copy.purchase_order_list_adasi_portal'))
@section('page-title', __('supplier.copy.my_purchase_orders'))

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('supplier.dashboard'),
        __('purchasing.breadcrumbs.purchase_orders') => null,
    ]" />

    <x-ui.page-header
        :title="__('supplier.copy.my_purchase_orders')"
        :eyebrow="__('supplier.copy.supplier_orders')"
        :description="__('supplier.copy.monitor_active_purchase_orders_delivery_milestones_and_quality_inspection_statuses_issued_to_your_co')"
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('supplier.export.purchase-orders')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportSupplierPurchaseOrdersBtn"
                :data-export-url="route('supplier.export.purchase-orders')"
                data-export-source-singular="{{ __('exports.sources.purchase_order') }}"
                data-export-source-plural="{{ __('exports.sources.purchase_orders') }}"
                data-export-count-table="#poTable"
                data-export-row-label="{{ __('supplier.copy.purchase_order_rows') }}"
                data-export-row-explanation="{{ __('supplier.copy.each_purchase_order_will_be_written_as_one_excel_row') }}"
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>{{ __('supplier.copy.export_excel') }}</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Orders DataTable --}}
    <x-ui.data-table
        :title="__('supplier.copy.received_purchase_orders')"
        :description="__('supplier.copy.search_export_and_inspect_order_details_without_exposing_other_suppliers_data')"
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="poTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">{{ __('supplier.copy.po_number') }}</th>
                    <th scope="col">{{ __('supplier.copy.period') }}</th>
                    <th scope="col">{{ __('supplier.copy.reference_no_pr') }}</th>
                    <th scope="col">{{ __('supplier.copy.remark') }}</th>
                    <th scope="col" class="text-end">{{ __('supplier.copy.total_amount_idr') }}</th>
                    <th scope="col" class="text-center">{{ __('supplier.copy.status') }}</th>
                    <th scope="col">{{ __('supplier.copy.estimated_arrival') }}</th>
                    <th scope="col" class="text-end" style="width: 110px;">{{ __('supplier.copy.action') }}</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var table = $('#poTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.purchase-orders.index") }}',
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'period_name', name: 'period_name', orderable: false, className: 'tw-text-on-surface-variant' },
                { data: 'pr_reference', name: 'pr_reference', orderable: false },
                { data: 'remark_display', name: 'remark_display', orderable: false, className: 'tw-text-on-surface-variant tw-text-ui-xs' },
                { data: 'total_idr', name: 'total_idr', className: 'text-end fw-bold text-primary ui-tabular-nums', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'estimated_date', name: 'estimated_arrival', className: 'ui-tabular-nums tw-text-on-surface-variant' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},

            order: []
        });

        $('#exportSupplierPurchaseOrdersBtn').on('click', function() {
            const exportUrl = new URL(this.dataset.exportUrl, window.location.origin);
            const search = table.search().trim();

            if (search) {
                exportUrl.searchParams.set('search', search);
            } else {
                exportUrl.searchParams.delete('search');
            }

            this.href = exportUrl.toString();
        });
    });
</script>
@endpush
