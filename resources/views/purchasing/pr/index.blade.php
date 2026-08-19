@extends('layouts.app')

@section('title', 'Material Requisition List - ADASI Portal')
@section('page-title', 'Purchase Requisition')

@push('styles')
<style>
    .action-button-grid {
        display: inline-grid;
        grid-template-columns: repeat(2, 2.25rem);
        gap: .35rem;
        justify-content: center;
    }

    .action-grid-form {
        margin: 0;
    }

    .action-button-grid .action-grid-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 2.25rem;
        height: 2.25rem;
        padding: 0;
    }
</style>
@endpush

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">Requisition List Material</h5>
        <div class="d-flex gap-2">
            <a href="{{ route('purchasing.export.requisitions') }}" class="btn btn-success btn-sm" data-async-export
                id="exportRequisitionsBtn" data-export-url="{{ route('purchasing.export.requisitions') }}">
                <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
            </a>
            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requisitions.create') }}" class="btn btn-primary btn-sm" style="background-color: var(--adasi-blue); border-color: var(--adasi-blue);">
                <i class="bi bi-plus-circle me-1"></i> Create New Requisition
            </a>
        </div>
    </div>
    <div class="card-body">
        
        {{-- Filter Form --}}
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <label for="period_id" class="form-label small fw-medium">Filter Period</label>
                <select name="period_id" id="period_id" class="form-select form-select-sm">
                    <option value="">All Period</option>
                    @foreach($periods as $period)
                        <option value="{{ $period->id }}">
                            {{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label small fw-medium">Filter Status</label>
                <select name="status" id="status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="draft">Draft</option>
                    <option value="submitted">Submitted</option>
                    <option value="rejected">Rejected</option>
                    <option value="bidding">Bidding</option>
                    <option value="completed">Completed</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <button type="button" class="btn btn-light btn-sm w-100" id="resetFilter">Reset Filter</button>
            </div>
        </div>

        <div id="filterChips" class="d-flex flex-wrap gap-2 mb-3 d-none">
            {{-- Filter chips will be rendered here by JS --}}
        </div>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="prTable">
                <thead class="table-light text-center">
                    <tr>
                        <th>No</th>
                        <th>PR No.</th>
                        <th>Period</th>
                        <th>Created By</th>
                        <th>Amount Supplier</th>
                        <th>Amount Item</th>
                        <th>Status</th>
                        <th>Date Created</th>
                        <th>Action</th>
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
                url: '{{ route("purchasing.requisitions.index") }}',
                data: function(d) {
                    d.period_id = $('#period_id').val();
                    d.status = $('#status').val();
                }
            },
            columns: [
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center' },
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-medium' },
                { data: 'period_name', name: 'period.name' },
                { data: 'creator_name', name: 'creator.name' },
                { data: 'supplier_count', name: 'invited_suppliers_count', searchable: false, className: 'text-center' },
                { data: 'item_count', name: 'item_count', searchable: false },
                { data: 'status_badge', name: 'status', searchable: false },
                { data: 'created_date', name: 'created_at' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-center' }
            ],
            language: {},
            pageLength: 25,
            order: []
        });

        $('#exportRequisitionsBtn').on('click', function(event) {
            const exportUrl = new URL(this.dataset.exportUrl, window.location.origin);
            const periodId = $('#period_id').val();
            const status = $('#status').val();
            const search = table.search().trim();

            if (periodId) exportUrl.searchParams.set('period_id', periodId);
            if (status) exportUrl.searchParams.set('status', status);
            if (search) exportUrl.searchParams.set('search', search);

            this.href = exportUrl.toString();
        });

        function updateFilterChips() {
            const periodText = $('#period_id option:selected').val() ? $('#period_id option:selected').text().trim() : null;
            const statusText = $('#status option:selected').val() ? $('#status option:selected').text().trim() : null;
            
            const chips = [];
            if (periodText) chips.push(`<span class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal">Period: ${periodText} <i class="bi bi-x-circle ms-1" style="cursor:pointer" onclick="$('#period_id').val('').trigger('change')"></i></span>`);
            if (statusText) chips.push(`<span class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal">Status: ${statusText} <i class="bi bi-x-circle ms-1" style="cursor:pointer" onclick="$('#status').val('').trigger('change')"></i></span>`);
            
            const $container = $('#filterChips');
            const $resetBtn = $('#resetFilter');
            
            if (chips.length > 0) {
                $container.html(chips.join('')).removeClass('d-none');
                $resetBtn.removeClass('btn-light').addClass('btn-danger text-white');
            } else {
                $container.empty().addClass('d-none');
                $resetBtn.removeClass('btn-danger text-white').addClass('btn-light');
            }
        }

        // Filter handlers
        $('#period_id, #status').on('change', function() {
            updateFilterChips();
            table.ajax.reload();
        });

        $('#resetFilter').on('click', function() {
            $('#period_id').val('');
            $('#status').val('');
            updateFilterChips();
            table.ajax.reload();
        });

        updateFilterChips();

        // ADASI Alert delete confirmation (delegated for dynamic rows)
        $(document).on('click', '.btn-delete', function() {
            const form = $(this).closest('form');
            AdasiAlert.confirmDanger({
                title: @json('Are you sure you want to delete?'),
                text: @json('This material requisition will be permanently deleted!'),
                confirmText: @json('Yes, delete!'),
                cancelText: @json('Cancel')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });

        let draftSubmitConfirmationOpen = false;
        $(document).on('click', '.btn-submit-draft', function() {
            const $button = $(this);
            if (draftSubmitConfirmationOpen || $button.data('submitting')) {
                return;
            }

            const form = $button.closest('form');
            draftSubmitConfirmationOpen = true;

            AdasiAlert.confirm({
                title: @json('Submit Requisition?'),
                text: @json('Status will change to Submitted and cannot be edited anymore.'),
                confirmText: @json('Yes, Submit!'),
                cancelText: @json('Cancel')
            }).then((result) => {
                draftSubmitConfirmationOpen = false;

                if (result.isConfirmed) {
                    $button.data('submitting', true).prop('disabled', true);
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
