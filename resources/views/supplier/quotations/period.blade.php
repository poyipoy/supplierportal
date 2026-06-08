@extends('layouts.app')

@section('title', 'Requisition List: ' . $period->name . ' - ADASI Portal')
@section('page-title', 'Purchase Requisition' . ': ' . $period->name)

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.quotations.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Back to Period List
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Purchase Requisition List</h5>
    </div>
    <div class="card-body">
        <div class="row g-3 align-items-end mb-4">
            <div class="col-md-5">
                <label class="form-label small fw-bold">Number PR</label>
                <input type="text" id="filter_pr_number" class="form-control form-control-sm" placeholder="Search PR number... (REQ/MM/YYYY/XXX)">
            </div>
            <div class="col-md-4">
                <label class="form-label small fw-bold">Quotation Status</label>
                <select id="filter_status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="unresponded">Not Responded</option>
                    <option value="draft">Draft</option>
                    <option value="revision_requested">Needs Revision</option>
                    <option value="submitted">Submitted</option>
                    <option value="accepted">Accepted</option>
                    <option value="rejected">Rejected</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="button" class="btn btn-sm btn-primary flex-fill" style="background-color: var(--adasi-blue);" id="applyFilter">
                    <i class="bi bi-search"></i>
                </button>
                <button type="button" class="btn btn-sm btn-light border flex-fill" id="resetFilter">Reset</button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th>Number PR</th>
                        <th>Date Submitted</th>
                        <th>Amount Item</th>
                        <th>My Quotation Status</th>
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
        var table = $('#prTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: {
                url: '{{ route("supplier.quotations.period", $period->id) }}',
                data: function(d) {
                    d.pr_number = $('#filter_pr_number').val();
                    d.status = $('#filter_status').val();
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false },
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-medium' },
                { data: 'updated_date', name: 'updated_at' },
                { data: 'item_count', name: 'item_count', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},
            pageLength: 25,
            order: []
        });

        $('#filter_status').on('change', function() { table.ajax.reload(); });
        $('#applyFilter').on('click', function() { table.ajax.reload(); });
        $('#filter_pr_number').on('keypress', function(e) {
            if (e.which === 13) table.ajax.reload();
        });
        $('#resetFilter').on('click', function() {
            $('#filter_pr_number').val('');
            $('#filter_status').val('');
            table.ajax.reload();
        });
    });
</script>
@endpush
