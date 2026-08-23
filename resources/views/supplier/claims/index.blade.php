@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Material Claims - ADASI Portal')
@section('page-title', 'Material Claims')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Material Claims' => null,
    ]" />

    <x-ui.page-header
        title="Material Claims"
        eyebrow="Quality Management"
        description="Review and respond to NG quality discrepancy claims assigned to your supplier purchase orders."
    />

    <x-ui.alert tone="warning" title="Response required">Claims marked as <strong>Pending</strong> require your official response and proposed resolution before the stated deadline.</x-ui.alert>

    {{-- Claims DataTable --}}
    <x-ui.data-table
        title="Discrepancy Claims from ADASI"
        description="The list is scoped strictly to purchase orders issued to your company."
    >
        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="claimTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">Claim ID</th>
                    <th scope="col">PO Number</th>
                    <th scope="col">Date Submitted</th>
                    <th scope="col">Response Deadline</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col" class="text-end" style="width: 120px;">Action</th>
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
        $('#claimTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.claims.index") }}',
            columns: [
                { data: 'claim_id', name: 'id', className: 'fw-semibold tw-text-on-surface' },
                { data: 'po_number', name: 'po_number', className: 'fw-bold tw-text-on-surface', orderable: false },
                { data: 'created_date', name: 'created_at', className: 'ui-tabular-nums tw-text-on-surface-variant' },
                { data: 'deadline_display', name: 'deadline', className: 'ui-tabular-nums' },
                { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},
            pageLength: 25,
            order: [],
            drawCallback: function() {
                window.initAdasiTooltips?.(document.getElementById('claimTable'));
            }
        });
    });
</script>
@endpush
