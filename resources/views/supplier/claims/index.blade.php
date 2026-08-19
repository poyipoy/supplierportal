@extends('layouts.app')

@section('title', 'Material Claim - ADASI Portal')
@section('page-title', 'Material Claim')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Material Claims" description="Review and respond to NG material claims assigned to your supplier account." eyebrow="Supplier Portal" />
    <x-ui.alert tone="warning">Claims with <strong>PENDING</strong> status require your response before the deadline.</x-ui.alert>
    <x-ui.data-table title="Claims from ADASI" description="The list is scoped to your supplier account and its purchase orders.">
            <table class="table table-hover align-middle w-100" id="claimTable">
                <thead class="table-light">
                    <tr>
                        <th>Claim ID</th>
                        <th>Number PO</th>
                        <th>Date Submitted</th>
                        <th>
                            Deadline
                            <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Deadline for supplier to respond to material claims."></i>
                        </th>
                        <th class="text-center">Status</th>
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
        $('#claimTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.claims.index") }}',
            columns: [
                { data: 'claim_id', name: 'id', className: 'fw-medium' },
                { data: 'po_number', name: 'po_number', className: 'fw-bold', orderable: false },
                { data: 'created_date', name: 'created_at' },
                { data: 'deadline_display', name: 'deadline' },
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
