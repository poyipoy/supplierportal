@extends('layouts.app')

@section('title', 'Purchase Order List - ADASI Portal')
@section('page-title', 'Purchase Order Saya')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="My Purchase Orders" description="Track only purchase orders issued to your supplier account." eyebrow="Supplier Portal" />
    <x-ui.data-table title="Received Purchase Orders" description="Search, export, and open order details without exposing other suppliers' data.">
        <x-slot:toolbar><x-ui.button :href="route('supplier.export.purchase-orders')" variant="secondary" size="sm" data-async-export id="exportSupplierPurchaseOrdersBtn" :data-export-url="route('supplier.export.purchase-orders')"><x-slot:leading><i class="bi bi-file-earmark-excel"></i></x-slot:leading>Export Excel</x-ui.button></x-slot:toolbar>
            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>Number PO</th>
                        <th>Period</th>
                        <th>Reference (No. PR)</th>
                        <th>Remark</th>
                        <th class="text-end">Total</th>
                        <th class="text-center">Status</th>
                        <th>Estimated Arrival</th>
                        <th class="text-end">Action</th>
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
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold' },
                { data: 'period_name', name: 'period_name', orderable: false },
                { data: 'pr_reference', name: 'pr_reference', orderable: false },
                { data: 'remark_display', name: 'remark_display', orderable: false },
                { data: 'total_idr', name: 'total_idr', className: 'text-end fw-medium', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'estimated_date', name: 'estimated_arrival' },
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
