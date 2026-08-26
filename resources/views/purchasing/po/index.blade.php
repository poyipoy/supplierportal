@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'Purchase Order List - ADASI Portal')
@section('page-title', 'Purchase Orders')

@push('styles')
<style>
    .po-filter-reset--active {
        background: var(--md-error-container) !important;
        color: var(--md-on-error-container) !important;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        title="Purchase Orders"
        eyebrow="Purchasing"
        description="Track supplier orders, reference requisitions, arrival targets, and workflow statuses."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('purchasing.export.purchase-orders')"
                variant="outline"
                size="sm"
                data-async-export
                id="exportPurchaseOrdersBtn"
                :data-export-url="route('purchasing.export.purchase-orders')"
                data-export-source-singular="purchase order"
                data-export-source-plural="purchase orders"
                data-export-count-table="#poTable"
                data-export-row-label="purchase order rows"
                data-export-row-explanation="Each purchase order will be written as one Excel row."
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>Export Excel</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- 2. Operational Toolbar --}}
    <x-ui.toolbar :sticky="true">
        <x-slot:search>
            <div class="input-group input-group-sm">
                <span class="input-group-text tw-bg-surface border-end-0 tw-text-outline">
                    <x-ui.icon name="search" size="sm" />
                </span>
                <input
                    type="text"
                    id="filter_po_number"
                    class="form-control border-start-0 ps-0"
                    placeholder="Search PO number (e.g. PO/05/2026/001)..."
                    autocomplete="off"
                    aria-label="Search purchase order number"
                >
                <x-ui.button type="button" size="sm" id="searchPoBtn" aria-label="Search PO number">
                    Search
                </x-ui.button>
            </div>
        </x-slot:search>

        <x-slot:filters>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div style="min-width: 150px;">
                    <select id="filter_status" class="form-select form-select-sm" aria-label="Filter by Status">
                        <option value="">All Statuses</option>
                        <option value="active">Active</option>
                        <option value="waiting_qc">Waiting QC</option>
                        <option value="claim_needed">Claim Needed</option>
                        <option value="overdue">Overdue</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>

                <div style="min-width: 180px;">
                    <select id="filter_supplier" class="form-select form-select-sm" aria-label="Filter by Supplier">
                        <option value="">All Suppliers</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.button type="button" variant="ghost" size="sm" id="resetFilter" class="po-filter-reset">
                    <x-ui.icon name="rotate-ccw" />
                    <span>Reset</span>
                </x-ui.button>
            </div>
            <div id="filterChips" class="d-none flex-wrap tw-gap-1.5 align-items-center ms-2" aria-live="polite"></div>
        </x-slot:filters>
    </x-ui.toolbar>

    {{-- 3. Balanced Data Table --}}
    <x-ui.data-table density="compact">
        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="poTable">
            <thead class="table-light">
                <tr>
                    <th scope="col">PO Number</th>
                    <th scope="col">Supplier</th>
                    <th scope="col">Period</th>
                    <th scope="col">Reference PR</th>
                    <th scope="col">Remark</th>
                    <th scope="col" class="text-end">Total IDR</th>
                    <th scope="col" class="text-center">Status</th>
                    <th scope="col">Estimated Arrival</th>
                    <th scope="col" class="text-end" style="width: 80px;">Action</th>
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
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
                { data: 'period_name', name: 'period_name', orderable: false },
                { data: 'pr_reference', name: 'pr_reference', orderable: false },
                { data: 'remark_display', name: 'remark_display', orderable: false, className: 'tw-text-on-surface-variant' },
                { data: 'total_idr', name: 'total_idr', className: 'text-end fw-semibold tw-text-on-surface ui-tabular-nums', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center' },
                { data: 'estimated_date', name: 'estimated_arrival', className: 'tw-text-on-surface-variant' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: {},
            pageLength: 25,
            order: []
        });

        // Filter event listeners
        $('#filter_status, #filter_supplier').on('change', function () {
            updateFilterChips();
            table.draw();
        });

        $('#searchPoBtn').on('click', function () {
            updateFilterChips();
            table.draw();
        });

        $('#filter_po_number').on('keyup', function (e) {
            if (e.key === 'Enter') {
                updateFilterChips();
                table.draw();
            }
        });

        $('#resetFilter').on('click', function () {
            $('#filter_po_number').val('');
            $('#filter_status').val('');
            $('#filter_supplier').val('');
            updateFilterChips();
            table.draw();
        });

        function updateFilterChips() {
            const poNumber = $('#filter_po_number').val().trim();
            const statusText = $('#filter_status option:selected').val() ? $('#filter_status option:selected').text().trim() : null;
            const supplierText = $('#filter_supplier option:selected').val() ? $('#filter_supplier option:selected').text().trim() : null;

            const createChip = (label, clearCallback) => {
                const $chip = $('<span>', {
                    class: 'ui-status-chip ui-status-chip--info'
                });
                const $remove = $('<button>', {
                    type: 'button',
                    class: 'ui-focus-ring tw-inline-flex tw-h-5 tw-w-5 tw-items-center tw-justify-center tw-rounded-ui-xs tw-border-0 tw-bg-transparent tw-p-0 tw-text-primary hover:tw-bg-primary/10',
                    'aria-label': `Remove ${label} filter`,
                    text: '×'
                });

                $remove.on('click', clearCallback);
                $chip.append(document.createTextNode(label), $remove);

                return $chip;
            };

            const chips = [];
            if (poNumber) {
                chips.push(createChip(`PO: ${poNumber}`, () => {
                    $('#filter_po_number').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (statusText) {
                chips.push(createChip(`Status: ${statusText}`, () => {
                    $('#filter_status').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (supplierText) {
                chips.push(createChip(`Supplier: ${supplierText}`, () => {
                    $('#filter_supplier').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }

            const $container = $('#filterChips');
            const $resetBtn = $('#resetFilter');

            if (chips.length > 0) {
                $container.empty().append(chips).removeClass('d-none').addClass('d-flex');
                $resetBtn.addClass('po-filter-reset--active');
            } else {
                $container.empty().addClass('d-none').removeClass('d-flex');
                $resetBtn.removeClass('po-filter-reset--active');
            }
        }

        $('#exportPurchaseOrdersBtn').on('click', function (event) {
            const exportUrl = new URL(this.dataset.exportUrl, window.location.origin);
            const poNumber = $('#filter_po_number').val();
            const status = $('#filter_status').val();
            const supplierId = $('#filter_supplier').val();
            const search = table.search().trim();

            if (poNumber) exportUrl.searchParams.set('po_number', poNumber);
            if (status) exportUrl.searchParams.set('status', status);
            if (supplierId) exportUrl.searchParams.set('supplier_id', supplierId);
            if (search) exportUrl.searchParams.set('search', search);

            this.href = exportUrl.toString();
        });
    });
</script>
@endpush
