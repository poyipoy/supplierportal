@extends('layouts.app')

@section('title', 'Material Claim - ADASI Portal')
@section('page-title', 'Material Claim')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Material Claim List from ADASI</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning small mb-4">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> The list below contains NG (Not Good) material claims submitted by the ADASI Purchasing team. Please immediately respond to claims with status <strong>PENDING</strong> before the deadline.
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="claimTable" style="width: 100%;">
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
        </div>
    </div>
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
