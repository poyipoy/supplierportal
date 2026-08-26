@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Purchase Order List - ADASI Portal')
@section('page-title', 'My Purchase Orders')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Purchase Orders' => null,
    ]" />

    <x-ui.page-header
        title="My Purchase Orders"
        eyebrow="Supplier Orders"
        description="Monitor active purchase orders, delivery milestones, and quality inspection statuses issued to your company."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('supplier.export.purchase-orders')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportSupplierPurchaseOrdersBtn"
                :data-export-url="route('supplier.export.purchase-orders')"
                data-export-source-singular="purchase order"
                data-export-source-plural="purchase orders"
                data-export-count-table="#poTable"
                data-export-row-label="purchase order rows"
                data-export-row-explanation="Each purchase order will be written as one Excel row."
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Orders DataTable --}}
    <x-ui.data-table
        title="Received Purchase Orders"
        description="Search, export, and inspect order details without exposing other suppliers' data."
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="poTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">PO Number</th>
                    <th scope="col">Period</th>
                    <th scope="col">Reference (No. PR)</th>
                    <th scope="col">Remark</th>
                    <th scope="col" class="text-end">Total Amount (IDR)</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col">Estimated Arrival</th>
                    <th scope="col" class="text-end" style="width: 110px;">Action</th>
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
            pageLength: 25,
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
