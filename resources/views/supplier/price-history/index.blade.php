@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Price History - ADASI Portal')
@section('page-title', 'Material Price History')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Price History' => null,
    ]" />

    <x-ui.page-header
        title="Purchase Price History"
        eyebrow="Commercial Intelligence"
        description="Monitor original quoted prices by material and transaction currency for your company."
    />

    {{-- Tabs --}}
    <x-supplier.price-history-tabs active="overview" />

    {{-- 2 Metrics Strip --}}
    <div class="tw-grid tw-gap-px tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-outline-variant sm:tw-grid-cols-2">
        <x-ui.metric-card
            flat
            label="Materials Offered"
            :value="number_format($stats['total_materials'] ?? 0, 0, ',', '.')"
            icon="package"
            tone="primary"
        />
        <x-ui.metric-card
            flat
            label="Total Quotation Items"
            :value="number_format($stats['total_quotations'] ?? 0, 0, ',', '.')"
            icon="receipt"
            tone="success"
        />
    </div>

    {{-- DataTable --}}
    <x-ui.data-table
            title="Materials and Latest Pricing"
        description="Original price ranges grouped by material and transaction currency."
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="overviewTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">Material Name</th>
                    <th scope="col" class="text-center">Currency</th>
                    <th scope="col" class="text-center">Total Offers</th>
                    <th scope="col">Latest Price/Kg &amp; Range</th>
                    <th scope="col">Last Quoted Date</th>
                    <th scope="col" class="text-center">Latest Status</th>
                    <th scope="col" class="text-center" style="width: 120px;">Action</th>
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
        $('#overviewTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.price-history.index") }}',
            columns: [
                { data: 'material_name', name: 'material_name', className: 'fw-bold tw-text-on-surface' },
                { data: 'currency', name: 'currency', className: 'text-center fw-semibold ui-tabular-nums' },
                { data: 'total_quotations', name: 'total_quotations', searchable: false, className: 'text-center fw-semibold ui-tabular-nums' },
                { data: 'price_info', name: 'price_info', orderable: false, searchable: false },
                { 
                    data: 'last_submitted_at', 
                    name: 'last_submitted_at',
                    className: 'ui-tabular-nums tw-text-on-surface-variant',
                    render: function(data) {
                        return data ? new Date(data).toLocaleDateString('en-GB', {day: '2-digit', month: 'short', year: 'numeric'}) : '-';
                    }
                },
                { data: 'latest_status_badge', name: 'latest_status_badge', orderable: false, searchable: false, className: 'text-center' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
            ],
            order: [[0, 'asc'], [1, 'asc']],
            pageLength: 25
        });
    });
</script>
@endpush
