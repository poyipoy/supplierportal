@extends('layouts.app')

@section('title', 'Purchase Order List - ADASI Portal')
@section('page-title', 'Purchase Order')

@section('content')
<div class="tw-grid tw-gap-6">

    {{-- Page Header --}}
    <x-ui.page-header
        title="Purchase Orders"
        description="Track supplier, reference PRs, arrival targets, and workflow status."
        eyebrow="Purchasing"
    />

    {{-- Purchase Order Data Table --}}
    <x-ui.data-table
        title="Purchase Order List"
        description="Use the filters to narrow server-side results without changing the workflow state."
    >

        {{-- Toolbar --}}
        <x-slot:toolbar>
            <x-ui.button
                :href="route('purchasing.export.purchase-orders')"
                variant="secondary"
                size="sm"
                class="tw-inline-flex tw-items-center tw-gap-2"
                data-async-export
                id="exportPurchaseOrdersBtn"
                :data-export-url="route('purchasing.export.purchase-orders')"
            >
                <x-ui.icon name="file-spreadsheet" />

                <span>Export Excel</span>
            </x-ui.button>
        </x-slot:toolbar>

        {{-- Filters --}}
        <x-slot:filters>
            <div class="tw-grid tw-w-full tw-gap-3 md:tw-grid-cols-2 xl:tw-grid-cols-4">

                {{-- PO Number --}}
                <div>
                    <label
                        for="filter_po_number"
                        class="form-label small fw-medium"
                    >
                        PO No.
                    </label>

                    <div class="input-group input-group-sm">
                        <input
                            type="text"
                            id="filter_po_number"
                            class="form-control"
                            placeholder="PO/MM/YYYY/XXX"
                            autocomplete="off"
                        >

                        <button
                            type="button"
                            class="btn btn-primary"
                            id="searchPoBtn"
                            aria-label="Search PO number"
                        >
                            <x-ui.icon name="search" />
                        </button>
                    </div>
                </div>

                {{-- Status --}}
                <div>
                    <label
                        for="filter_status"
                        class="form-label small fw-medium"
                    >
                        Status
                    </label>

                    <select
                        id="filter_status"
                        class="form-select form-select-sm"
                    >
                        <option value="">All Status</option>
                        <option value="active">Active</option>
                        <option value="waiting_qc">Waiting QC</option>
                        <option value="claim_needed">Claim Needed</option>
                        <option value="overdue">Overdue</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                {{-- Supplier --}}
                <div>
                    <label
                        for="filter_supplier"
                        class="form-label small fw-medium"
                    >
                        Supplier
                    </label>

                    <select
                        id="filter_supplier"
                        class="form-select form-select-sm"
                    >
                        <option value="">All Supplier</option>

                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}">
                                {{ $supplier->name }}
                            </option>
                        @endforeach
                    </select>
                </div>

                {{-- Reset Filter --}}
                <div class="tw-flex tw-items-end">
                    <x-ui.button
                        type="button"
                        variant="ghost"
                        size="sm"
                        class="tw-w-full tw-inline-flex tw-items-center tw-justify-center tw-gap-2"
                        id="resetFilter"
                    >
                        <x-ui.icon name="rotate-ccw" />

                        <span>Reset</span>
                    </x-ui.button>
                </div>

            </div>
        </x-slot:filters>

        {{-- Active Filter Chips --}}
        <div
            id="filterChips"
            class="d-flex flex-wrap gap-2 mb-3 d-none"
            aria-live="polite"
        >
            {{-- Filter chips will be rendered here by JavaScript --}}
        </div>

        {{-- Purchase Order Table --}}
        <table
            class="table table-hover align-middle"
            id="poTable"
        >
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
    $(document).ready(function () {

        /*
        |--------------------------------------------------------------------------
        | DataTable
        |--------------------------------------------------------------------------
        */

        var table = $('#poTable').DataTable({
            processing: true,
            serverSide: true,

            ajax: {
                url: '{{ route("purchasing.purchase-orders.index") }}',

                data: function (d) {
                    d.po_number = $('#filter_po_number').val();
                    d.status = $('#filter_status').val();
                    d.supplier_id = $('#filter_supplier').val();
                }
            },

            columns: [
                {
                    data: 'po_number_display',
                    name: 'po_number',
                    className: 'fw-bold'
                },
                {
                    data: 'supplier_name',
                    name: 'supplier_name',
                    orderable: false
                },
                {
                    data: 'period_name',
                    name: 'period_name',
                    orderable: false
                },
                {
                    data: 'pr_reference',
                    name: 'pr_reference',
                    orderable: false
                },
                {
                    data: 'remark_display',
                    name: 'remark_display',
                    orderable: false
                },
                {
                    data: 'total_idr',
                    name: 'total_idr',
                    className: 'text-end fw-medium',
                    orderable: false,
                    searchable: false
                },
                {
                    data: 'status_badge',
                    name: 'status',
                    className: 'text-center'
                },
                {
                    data: 'estimated_date',
                    name: 'estimated_arrival'
                },
                {
                    data: 'action',
                    name: 'action',
                    orderable: false,
                    searchable: false,
                    className: 'text-end'
                }
            ],

            language: {},

            pageLength: 25,

            order: []
        });


        /*
        |--------------------------------------------------------------------------
        | Export Purchase Orders
        |--------------------------------------------------------------------------
        */

        $('#exportPurchaseOrdersBtn').on('click', function () {

            const exportUrl = new URL(
                this.dataset.exportUrl,
                window.location.origin
            );

            const poNumber = $('#filter_po_number').val().trim();
            const status = $('#filter_status').val();
            const supplierId = $('#filter_supplier').val();
            const search = table.search().trim();

            if (poNumber) {
                exportUrl.searchParams.set(
                    'po_number',
                    poNumber
                );
            }

            if (status) {
                exportUrl.searchParams.set(
                    'status',
                    status
                );
            }

            if (supplierId) {
                exportUrl.searchParams.set(
                    'supplier_id',
                    supplierId
                );
            }

            if (search) {
                exportUrl.searchParams.set(
                    'search',
                    search
                );
            }

            this.href = exportUrl.toString();
        });


        /*
        |--------------------------------------------------------------------------
        | PO Number Search
        |--------------------------------------------------------------------------
        */

        var poSearchTimer;

        function reloadPoTablePreservingCursor() {

            var input = document.getElementById(
                'filter_po_number'
            );

            var shouldRestoreCursor =
                document.activeElement === input;

            var cursorStart =
                shouldRestoreCursor
                    ? input.selectionStart
                    : null;

            var cursorEnd =
                shouldRestoreCursor
                    ? input.selectionEnd
                    : null;

            table.ajax.reload(function () {

                updateFilterChips();

                if (!shouldRestoreCursor) {
                    return;
                }

                input.focus({
                    preventScroll: true
                });

                if (
                    typeof input.setSelectionRange ===
                    'function'
                ) {
                    input.setSelectionRange(
                        cursorStart,
                        cursorEnd
                    );
                }
            });
        }


        /*
        |--------------------------------------------------------------------------
        | Filter Chips
        |--------------------------------------------------------------------------
        */

        function updateFilterChips() {

            const poText =
                $('#filter_po_number')
                    .val()
                    .trim();

            const statusText =
                $('#filter_status option:selected').val()
                    ? $('#filter_status option:selected')
                        .text()
                        .trim()
                    : null;

            const supplierText =
                $('#filter_supplier option:selected').val()
                    ? $('#filter_supplier option:selected')
                        .text()
                        .trim()
                    : null;

            const chips = [];


            /*
            |----------------------------------------------------------------------
            | PO Number Chip
            |----------------------------------------------------------------------
            */

            if (poText) {
                chips.push(`
                    <span
                        class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal"
                    >
                        No. PO: ${escapeHtml(poText)}

                        <button
                            type="button"
                            class="btn btn-link p-0 ms-1 text-white text-decoration-none"
                            data-filter-remove="po_number"
                            aria-label="Remove PO number filter"
                        >
                            <x-ui.icon name="x-circle" />
                        </button>
                    </span>
                `);
            }


            /*
            |----------------------------------------------------------------------
            | Status Chip
            |----------------------------------------------------------------------
            */

            if (statusText) {
                chips.push(`
                    <span
                        class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal"
                    >
                        Status: ${escapeHtml(statusText)}

                        <button
                            type="button"
                            class="btn btn-link p-0 ms-1 text-white text-decoration-none"
                            data-filter-remove="status"
                            aria-label="Remove status filter"
                        >
                            <x-ui.icon name="x-circle" />
                        </button>
                    </span>
                `);
            }


            /*
            |----------------------------------------------------------------------
            | Supplier Chip
            |----------------------------------------------------------------------
            */

            if (supplierText) {
                chips.push(`
                    <span
                        class="badge bg-primary rounded-pill d-flex align-items-center gap-1 px-3 py-2 fw-normal"
                    >
                        Supplier: ${escapeHtml(supplierText)}

                        <button
                            type="button"
                            class="btn btn-link p-0 ms-1 text-white text-decoration-none"
                            data-filter-remove="supplier"
                            aria-label="Remove supplier filter"
                        >
                            <x-ui.icon name="x-circle" />
                        </button>
                    </span>
                `);
            }


            const $container =
                $('#filterChips');

            const $resetBtn =
                $('#resetFilter');


            /*
            |----------------------------------------------------------------------
            | Show / Hide Filter Chips
            |----------------------------------------------------------------------
            */

            if (chips.length > 0) {

                $container
                    .html(chips.join(''))
                    .removeClass('d-none');

                $resetBtn
                    .removeClass('btn-light')
                    .addClass(
                        'btn-danger text-white'
                    );

            } else {

                $container
                    .empty()
                    .addClass('d-none');

                $resetBtn
                    .removeClass(
                        'btn-danger text-white'
                    )
                    .addClass('btn-light');
            }
        }


        /*
        |--------------------------------------------------------------------------
        | Basic HTML Escaping for Dynamic Chip Text
        |--------------------------------------------------------------------------
        */

        function escapeHtml(value) {

            return $('<div>')
                .text(value ?? '')
                .html();
        }


        /*
        |--------------------------------------------------------------------------
        | Remove Individual Filter Chips
        |--------------------------------------------------------------------------
        */

        $('#filterChips').on(
            'click',
            '[data-filter-remove]',
            function () {

                const filter =
                    this.dataset.filterRemove;

                switch (filter) {

                    case 'po_number':

                        $('#filter_po_number')
                            .val('');

                        reloadPoTablePreservingCursor();

                        break;


                    case 'status':

                        $('#filter_status')
                            .val('')
                            .trigger('change');

                        break;


                    case 'supplier':

                        $('#filter_supplier')
                            .val('')
                            .trigger('change');

                        break;
                }
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Status / Supplier Filter
        |--------------------------------------------------------------------------
        */

        $('#filter_status, #filter_supplier')
            .on('change', function () {

                updateFilterChips();

                table.ajax.reload();
            });


        /*
        |--------------------------------------------------------------------------
        | Search Button
        |--------------------------------------------------------------------------
        */

        $('#searchPoBtn').on(
            'mousedown',
            function (event) {

                event.preventDefault();
            }
        );

        $('#searchPoBtn').on(
            'click',
            function () {

                clearTimeout(poSearchTimer);

                reloadPoTablePreservingCursor();
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Debounced PO Number Search
        |--------------------------------------------------------------------------
        */

        $('#filter_po_number').on(
            'input',
            function () {

                clearTimeout(poSearchTimer);

                poSearchTimer = setTimeout(
                    reloadPoTablePreservingCursor,
                    500
                );
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Enter Key Search
        |--------------------------------------------------------------------------
        */

        $('#filter_po_number').on(
            'keydown',
            function (event) {

                if (
                    event.key === 'Enter' ||
                    event.which === 13
                ) {

                    event.preventDefault();

                    clearTimeout(poSearchTimer);

                    reloadPoTablePreservingCursor();
                }
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Reset Filters
        |--------------------------------------------------------------------------
        */

        $('#resetFilter').on(
            'click',
            function () {

                clearTimeout(poSearchTimer);

                $('#filter_po_number')
                    .val('');

                $('#filter_status')
                    .val('');

                $('#filter_supplier')
                    .val('');

                updateFilterChips();

                table.ajax.reload();
            }
        );


        /*
        |--------------------------------------------------------------------------
        | Initial State
        |--------------------------------------------------------------------------
        */

        updateFilterChips();
    });
</script>
@endpush