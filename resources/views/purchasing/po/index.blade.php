@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('purchasing.copy.purchase_order_list_adasi_portal'))
@section('page-title', __('purchasing.copy.purchase_orders'))

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
        :title="__('purchasing.copy.purchase_orders')"
        :eyebrow="__('purchasing.copy.purchasing')"
        :description="__('purchasing.copy.track_supplier_orders_reference_requisitions_arrival_targets_and_workflow_statuses')"
    >
        <x-slot:actions>
            <x-ui.button :href="route('purchasing.purchase-orders.consolidate-awards')" size="sm">{{ __('purchasing.copy.consolidate_selected_items') }}</x-ui.button>
            <x-export.advanced-modal export-key="purchasing.po" :action="route('purchasing.export.purchase-orders')" :suppliers="$suppliers"
                :filter-selectors="['po_number' => '#filter_po_number', 'status' => '#filter_status', 'supplier_id' => '#filter_supplier']" table="#poTable" trigger-id="exportPurchaseOrdersBtn" />
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
                    placeholder="{{ __('purchasing.copy.e_g_po_05_2026_001_or_supplier_name') }}"
                    autocomplete="off"
                    aria-label="{{ __('purchasing.copy.search_purchase_order_number') }}"
                >
                <x-ui.button type="button" size="sm" id="searchPoBtn" aria-label="{{ __('purchasing.copy.search_po_number') }}">
                    {{ __('purchasing.copy.search') }}
                </x-ui.button>
            </div>
        </x-slot:search>

        <x-slot:filters>
            <div class="d-flex flex-wrap align-items-center gap-2">
                <div style="min-width: 150px;">
                    <select id="filter_status" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_status') }}">
                        <option value="">{{ __('purchasing.copy.all_statuses') }}</option>
                        <option value="active">{{ __('purchasing.copy.active') }}</option>
                        <option value="waiting_qc">{{ __('purchasing.copy.waiting_qc') }}</option>
                        <option value="claim_needed">{{ __('purchasing.copy.claim_needed') }}</option>
                        <option value="overdue">{{ __('purchasing.copy.overdue') }}</option>
                        <option value="completed">{{ __('purchasing.copy.completed') }}</option>
                        <option value="cancelled">{{ __('purchasing.copy.cancelled') }}</option>
                    </select>
                </div>

                <div style="min-width: 180px;">
                    <select id="filter_supplier" class="form-select form-select-sm" aria-label="{{ __('purchasing.copy.filter_by_supplier') }}">
                        <option value="">{{ __('purchasing.copy.all_suppliers_3a7a53') }}</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->getRouteKey() }}">{{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>

                <x-ui.button type="button" variant="ghost" size="sm" id="resetFilter" class="po-filter-reset">
                    <x-ui.icon name="rotate-ccw" />
                    <span>{{ __('purchasing.copy.reset') }}</span>
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
                    <th scope="col">{{ __('purchasing.copy.po_number') }}</th>
                    <th scope="col">{{ __('purchasing.copy.supplier') }}</th>
                    <th scope="col">{{ __('purchasing.copy.period') }}</th>
                    <th scope="col">{{ __('purchasing.copy.reference_pr') }}</th>
                    <th scope="col">{{ __('purchasing.copy.remark') }}</th>
                    <th scope="col" class="text-end">{{ __('purchasing.copy.total_idr') }}</th>
                    <th scope="col" class="text-center">{{ __('purchasing.copy.status') }}</th>
                    <th scope="col">{{ __('purchasing.copy.estimated_arrival') }}</th>
                    <th scope="col" class="text-end" style="width: 80px;">{{ __('purchasing.copy.action') }}</th>
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
            search: { search: new URL(window.location.href).searchParams.get('search') || '' },
            ajax: {
                url: '{{ route("purchasing.purchase-orders.index") }}',
                data: function (d) {
                    d.po_number = $('#filter_po_number').val();
                    d.status = $('#filter_status').val();
                    d.supplier_id = $('#filter_supplier').val();
                    d.start_date = new URL(window.location.href).searchParams.get('start_date');
                    d.end_date = new URL(window.location.href).searchParams.get('end_date');
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
                    'aria-label': window.AdasiI18n.t('js.filters.remove', { label }),
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
                chips.push(createChip(@js(__('common.final_copy.status')) + ' ' + statusText, () => {
                    $('#filter_status').val('');
                    updateFilterChips();
                    table.draw();
                }));
            }
            if (supplierText) {
                chips.push(createChip(window.AdasiI18n.t('js.filters.supplier', { supplier: supplierText }), () => {
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

    });
</script>
@endpush
