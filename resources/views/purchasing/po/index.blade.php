@extends('layouts.app')

@section('title', 'Purchase Order List - ADASI Portal')
@section('page-title', 'Purchase Order')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Purchase Orders" description="Track supplier, reference PRs, arrival targets, and workflow status." eyebrow="Purchasing" />

    <x-ui.data-table title="Purchase Order List" description="Use the filters to narrow server-side results without changing the workflow state.">
        <x-slot:toolbar>
            <x-ui.button :href="route('purchasing.export.purchase-orders')" variant="secondary" size="sm" data-async-export id="exportPurchaseOrdersBtn" :data-export-url="route('purchasing.export.purchase-orders')">
                <x-slot:leading><i class="bi bi-file-earmark-excel"></i></x-slot:leading>Export Excel
            </x-ui.button>
        </x-slot:toolbar>
        <x-slot:filters>
        <div class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-4">
            <div>
                <label class="form-label small fw-medium">PO No.</label>
                <div class="input-group input-group-sm">
                    <input type="text" id="filter_po_number" class="form-control" placeholder="PO/MM/YYYY/XXX">
                    <button type="button" class="btn btn-primary" id="searchPoBtn" aria-label="Search PO number">
                        <i class="bi bi-search"></i>
                    </button>
                </div>
            </div>
            <div>
                <label class="form-label small fw-medium">Status</label>
                <select id="filter_status" class="form-select form-select-sm">
                    <option value="">All Status</option>
                    <option value="active">Active</option>
                    <option value="waiting_qc">Waiting QC</option>
                    <option value="claim_needed">Claim Needed</option>
                    <option value="overdue">Overdue</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            <div>
                <label class="form-label small fw-medium">Supplier</label>
                <select id="filter_supplier" class="form-select form-select-sm">
                    <option value="">All Supplier</option>
                    @foreach($suppliers as $s)
                        <option value="{{ $s->getRouteKey() }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="tw-flex tw-items-end">
                <x-ui.button type="button" variant="ghost" size="sm" class="tw-w-full" id="resetFilter"><x-slot:leading><i class="bi bi-arrow-counterclockwise"></i></x-slot:leading>Reset</x-ui.button>
            </div>
        </div>
        </x-slot:filters>

        <div id="filterChips" class="d-flex flex-wrap gap-2 mb-3 d-none">
            {{-- Filter chips will be rendered here by JS --}}
        </div>

            <table class="table table-hover align-middle" id="poTable">
                <thead class="table-light">
                    <tr>
                        <th>Number PO</th>
                        <th>Supplier</th>
                        <th>Period</th>
                        <th>Reference (No. PR)</th>
                        <th>Remark</th>
                        <th class="text-end">Total IDR</th>
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
            ajax: {
                url: '{{ route("purchasing.purchase-orders.index") }}',
                data: function(d) {
                    d.po_number = $('#filter_po_number').val();
                    d.status = $('#filter_status').val();
                    d.supplier_id = $('#filter_supplier').val();
                }
            },
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
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

        $('#exportPurchaseOrdersBtn').on('click', function(event) {
            const exportUrl = new URL(this.dataset.exportUrl, window.location.origin);
            const poNumber = $('#filter_po_number').val().trim();
            const status = $('#filter_status').val();
            const supplierId = $('#filter_supplier').val();
            const search = table.search().trim();

            if (poNumber) exportUrl.searchParams.set('po_number', poNumber);
            if (status) exportUrl.searchParams.set('status', status);
            if (supplierId) exportUrl.searchParams.set('supplier_id', supplierId);
            if (search) exportUrl.searchParams.set('search', search);

            this.href = exportUrl.toString();
        });

        var poSearchTimer;

        function reloadPoTablePreservingCursor() {
            var input = document.getElementById('filter_po_number');
            var shouldRestoreCursor = document.activeElement === input;
            var cursorStart = shouldRestoreCursor ? input.selectionStart : null;
            var cursorEnd = shouldRestoreCursor ? input.selectionEnd : null;

            table.ajax.reload(function() {
                updateFilterChips();
                if (!shouldRestoreCursor) return;

                input.focus({ preventScroll: true });
                if (typeof input.setSelectionRange === 'function') {
                    input.setSelectionRange(cursorStart, cursorEnd);
                }
            });
        }

        function updateFilterChips() {
            const poText = $('#filter_po_number').val().trim();
            const statusText = $('#filter_status option:selected').val() ? $('#filter_status option:selected').text().trim() : null;
            const supplierText = $('#filter_supplier option:selected').val() ? $('#filter_supplier option:selected').text().trim() : null;
            
            const chips = [];
            if (poText) chips.push(`<span class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal">No. PO: ${poText} <i class="bi bi-x-circle ms-1" style="cursor:pointer" onclick="$('#filter_po_number').val(''); reloadPoTablePreservingCursor();"></i></span>`);
            if (statusText) chips.push(`<span class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal">Status: ${statusText} <i class="bi bi-x-circle ms-1" style="cursor:pointer" onclick="$('#filter_status').val('').trigger('change')"></i></span>`);
            if (supplierText) chips.push(`<span class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal">Supplier: ${supplierText} <i class="bi bi-x-circle ms-1" style="cursor:pointer" onclick="$('#filter_supplier').val('').trigger('change')"></i></span>`);
            
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

        $('#filter_status, #filter_supplier').on('change', function() {
            updateFilterChips();
            table.ajax.reload();
        });

        $('#searchPoBtn').on('mousedown', function(e) {
            e.preventDefault();
        });

        $('#searchPoBtn').on('click', function() {
            clearTimeout(poSearchTimer);
            reloadPoTablePreservingCursor();
        });

        $('#filter_po_number').on('input', function() {
            clearTimeout(poSearchTimer);
            poSearchTimer = setTimeout(reloadPoTablePreservingCursor, 500);
        });

        $('#filter_po_number').on('keydown', function(e) {
            if (e.key === 'Enter' || e.which === 13) {
                e.preventDefault();
                clearTimeout(poSearchTimer);
                reloadPoTablePreservingCursor();
            }
        });

        $('#resetFilter').on('click', function() {
            $('#filter_po_number').val('');
            $('#filter_status').val('');
            $('#filter_supplier').val('');
            updateFilterChips();
            table.ajax.reload();
        });

        updateFilterChips();
    });
</script>
@endpush
