@extends('layouts.app')
@section('title', 'Price History - ADASI Portal')
@section('page-title', 'Price History Material')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Purchase Price History" description="Monitor only material prices attached to your supplier purchase orders." eyebrow="Supplier Portal" />
    <x-supplier.price-history-tabs active="overview" />

<div class="tw-grid tw-gap-4 sm:tw-grid-cols-2">
    <x-ui.metric-card label="Materials Offered" :value="number_format($stats['total_materials'] ?? 0, 0, ',', '.')" icon="package" />
    <x-ui.metric-card label="Quotation Items" :value="number_format($stats['total_quotations'] ?? 0, 0, ',', '.')" icon="file-check" tone="success" />
</div>

<x-ui.data-table title="Materials and Latest Price" description="Server-side results include your latest converted price, range, latest PO date, and quotation status.">
            <table class="table table-hover align-middle mb-0 w-100 tw-text-ui-sm" id="overviewTable">
                <thead class="table-light">
                    <tr>
                        <th>Material</th>
                        <th>Total Quotations</th>
                        <th>Latest Price (IDR) &amp; Price Range</th>
                        <th>Last PO Date</th>
                        <th>Latest Status</th>
                        <th class="text-center tw-w-[120px]">Action</th>
                    </tr>
                </thead>
            </table>
</x-ui.data-table>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#overviewTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route('supplier.price-history.index') }}',
            columns: [
                { data: 'material_name', name: 'material_name', className: 'fw-bold' },
                { data: 'total_quotations', name: 'total_quotations', searchable: false },
                { data: 'price_info', name: 'price_info', orderable: false, searchable: false },
                { 
                    data: 'last_submitted_at', 
                    name: 'last_submitted_at',
                    render: function(data) {
                        return data ? new Date(data).toLocaleDateString('id-ID', {day: '2-digit', month: 'short', year: 'numeric'}) : '-';
                    }
                },
                { data: 'latest_status_badge', name: 'latest_status_badge', orderable: false, searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
            ],
            order: [[0, 'asc']],
            
        });
    });
</script>
@endpush
