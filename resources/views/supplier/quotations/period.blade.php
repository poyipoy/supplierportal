@extends('layouts.app')

@section('title', 'Requisition List: ' . $period->name . ' - ADASI Portal')
@section('page-title', 'Purchase Requisition' . ': ' . $period->name)

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header :title="'Purchase Requisitions — ' . $period->name" description="Review requisitions and continue only the quotations assigned to your supplier account." eyebrow="Supplier Portal">
        <x-slot:actions><x-ui.button :href="route('supplier.quotations.index')" variant="ghost" size="sm"><i class="bi bi-arrow-left"></i> Back to Period List</x-ui.button></x-slot:actions>
    </x-ui.page-header>

<x-ui.data-table title="Purchase Requisition List" description="Search and filter this period's supplier-visible requisitions.">
    <x-slot:toolbar><x-ui.button :href="route('supplier.export.quotations', ['period_id' => $period->id])" variant="secondary" size="sm" data-async-export id="exportSupplierQuotationsBtn" :data-export-url="route('supplier.export.quotations')"><i class="bi bi-file-earmark-excel"></i> Export Excel</x-ui.button></x-slot:toolbar>
    <x-slot:filters>
        <div class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] md:tw-items-end">
            <div>
                <label class="form-label small fw-bold">Number PR</label>
                <input type="text" id="filter_pr_number" class="form-control form-control-sm" placeholder="Search PR number... (REQ/MM/YYYY/XXX)">
            </div>
            <div>
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
            <div class="tw-flex tw-gap-2">
                <x-ui.button type="button" size="sm" icon-only label="Apply filters" id="applyFilter"><i class="bi bi-search"></i></x-ui.button>
                <x-ui.button type="button" variant="ghost" size="sm" id="resetFilter">Reset</x-ui.button>
            </div>
        </div>
    </x-slot:filters>

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
</x-ui.data-table>
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

        $('#exportSupplierQuotationsBtn').on('click', function(event) {
            const exportUrl = new URL(this.dataset.exportUrl, window.location.origin);
            const prNumber = $('#filter_pr_number').val().trim();
            const status = $('#filter_status').val();
            const search = table.search().trim();

            exportUrl.searchParams.set('period_id', @json($period->id));
            if (prNumber) exportUrl.searchParams.set('pr_number', prNumber);
            if (status) exportUrl.searchParams.set('status', status);
            if (search) exportUrl.searchParams.set('search', search);

            this.href = exportUrl.toString();
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
