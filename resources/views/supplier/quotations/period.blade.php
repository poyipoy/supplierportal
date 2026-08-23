@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Requisition List: ' . $period->display_label . ' - ADASI Portal')
@section('page-title', 'Purchase Requisitions — ' . $period->display_label)

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('supplier.dashboard'),
        'Quotation Periods' => route('supplier.quotations.index'),
        $period->display_label => null,
    ]" />

    <x-ui.page-header
        :title="'Purchase Requisitions — ' . $period->display_label"
        eyebrow="Supplier Opportunities"
        description="Review requisitions and manage quotations assigned to your supplier account."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('supplier.export.quotations', ['period_id' => $period->id])"
                variant="outline"
                size="sm"
                data-async-export
                id="exportSupplierQuotationsBtn"
                :data-export-url="route('supplier.export.quotations')"
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
            <x-ui.button :href="route('supplier.quotations.index')" variant="ghost" size="sm">
                <x-ui.icon name="arrow-left" />
                <span>Back to Periods</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Data Table with Integrated Toolbar --}}
    <x-ui.data-table
        title="Requisition List"
        description="Filter by PR number or your quotation status."
    >
        <x-slot:filters>
            <div class="row g-2 align-items-end">
                <div class="col-md-5 col-lg-4">
                    <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="filter_pr_number">PR Number</label>
                    <input type="text" id="filter_pr_number" class="form-control form-control-sm" placeholder="Search PR number... (REQ/MM/YYYY/XXX)">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label small fw-semibold tw-text-on-surface mb-1" for="filter_status">My Quotation Status</label>
                    <select id="filter_status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <option value="unresponded">Awaiting Quotation</option>
                        <option value="draft">Draft</option>
                        <option value="revision_requested">Revision Requested</option>
                        <option value="submitted">Submitted</option>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
                <div class="col-md-3 col-lg-2 d-flex tw-gap-1.5">
                    <x-ui.button type="button" size="sm" class="tw-flex-1" id="applyFilter">
                        <x-ui.icon name="search" size="sm" class="me-1" />Filter
                    </x-ui.button>
                    <x-ui.icon-button icon="rotate-ccw" label="Reset filters" size="sm" id="resetFilter" />
                </div>
            </div>
        </x-slot:filters>

        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="prTable">
            <thead class="table-light">
                <tr>
                    <th scope="col" style="width: 40px;">No</th>
                    <th scope="col">PR Number</th>
                    <th scope="col">Date Issued</th>
                    <th scope="col" class="text-center">Items</th>
                    <th scope="col" class="text-center">My Quotation Status</th>
                    <th scope="col" class="text-end" style="width: 140px;">Action</th>
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
                { data: 'DT_RowIndex', name: 'DT_RowIndex', orderable: false, searchable: false, className: 'text-center tw-text-on-surface-variant' },
                { data: 'pr_number_display', name: 'pr_number', className: 'fw-bold text-primary' },
                { data: 'updated_date', name: 'updated_at', className: 'ui-tabular-nums tw-text-on-surface-variant' },
                { data: 'item_count', name: 'item_count', orderable: false, searchable: false, className: 'text-center fw-medium ui-tabular-nums' },
                { data: 'status_badge', name: 'status', searchable: false, className: 'text-center' },
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
