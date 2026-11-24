@extends('layouts.app')
@section('uses-datatables', true)

@section('title', __('qc.copy.qc_inspections_adasi_portal'))
@section('page-title', __('qc.copy.qc_inspections'))

@section('content')
<div class="tw:grid tw:gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        __('purchasing.breadcrumbs.dashboard') => route('qc.dashboard'),
        __('purchasing.breadcrumbs.qc_inspections') => null,
    ]" />

    <x-ui.page-header
        :title="__('qc.copy.qc_inspections')"
        :eyebrow="__('qc.copy.quality_control')"
        :description="__('qc.copy.process_arrived_shipments_waiting_for_quality_evaluation_and_review_completed_inspection_history')"
    >
        <x-slot:actions>
            <div id="inspectionExportLink" class="d-none"><x-export.advanced-modal export-key="qc.inspections" table="#historyTable" :action="route('qc.export.inspections')" :filter-selectors="['status' => '#historyStatusFilter']" /></div>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Queue Tabs --}}
    <x-ui.tab-list
        :label="__('qc.copy.inspection_queues')"
        active="waiting"
        :tabs="[
            ['id' => 'waiting', 'label' => __('qc.copy.waiting_for_inspection'), 'icon' => 'clock', 'count' => $waitingCount, 'tone' => 'warning'],
            ['id' => 'history', 'label' => __('qc.copy.inspection_history'), 'icon' => 'history', 'count' => $historyCount],
        ]"
    />

    {{-- Queue Panes --}}
    <div class="tab-content" id="inspectionTabsContent">
        {{-- Tab 1: Waiting for Inspection --}}
        <div class="tab-pane fade show active" id="waiting" role="tabpanel" aria-labelledby="waiting-tab" tabindex="0">
            <div class="tw:grid tw:gap-3">
                <x-ui.alert tone="info">{{ __('qc.copy.shipments_in_this_queue_have_arrived_at_the_warehouse_and_require_physical_qc_inspection') }}</x-ui.alert>

                <x-ui.data-table>
                    <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="waitingTable">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('qc.copy.po_number') }}</th>
                                <th scope="col">{{ __('qc.copy.supplier_name') }}</th>
                                <th scope="col">{{ __('qc.copy.date_material_arrived') }}</th>
                                <th scope="col" class="text-center">{{ __('qc.copy.total_items') }}</th>
                                <th scope="col" class="tw:text-right text-end" style="width: 140px;">{{ __('common.table.action') }}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </x-ui.data-table>
            </div>
        </div>

        {{-- Tab 2: Inspection History --}}
        <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
            <div class="tw:grid tw:gap-3">
                {{-- Filter Bar --}}
                <x-ui.filter-bar
                    id="qcHistoryFilterBar"
                    mode="live"
                    variant="embedded"
                    reset-id="resetHistoryStatusFilter"
                    :reset-label="__('qc.copy.reset_filter')"
                >
                    <x-slot:filters>
                        <label for="historyStatusFilter" class="tw:sr-only">{{ __('qc.copy.inspection_outcome_status') }}</label>
                        <select id="historyStatusFilter" class="form-select form-select-sm tw:w-full tw:min-w-0 tw:sm:w-56">
                            <option value="">{{ __('qc.copy.all_statuses_ok_ng') }}</option>
                            <option value="ok" @selected(request('status') === 'ok')>{{ __('qc.copy.ok_status_label') }}</option>
                            <option value="ng" @selected(request('status') === 'ng')>{{ __('qc.copy.ng_status_label') }}</option>
                        </select>
                    </x-slot:filters>

                    <x-slot:chips>
                        <x-ui.filter-chips id="historyFilterChips" />
                    </x-slot:chips>
                </x-ui.filter-bar>

                <x-ui.data-table>
                    <table class="table table-hover align-middle mb-0 tw:text-ui-sm w-100" id="historyTable">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">{{ __('qc.copy.po_number') }}</th>
                                <th scope="col">{{ __('qc.copy.supplier_name') }}</th>
                                <th scope="col">{{ __('qc.copy.inspection_date') }}</th>
                                <th scope="col" class="text-center">{{ __('qc.copy.status') }}</th>
                                <th scope="col">{{ __('qc.copy.inspected_by') }}</th>
                                <th scope="col" class="tw:text-right text-end" style="width: 110px;">{{ __('common.table.action') }}</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </x-ui.data-table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
(() => {
    const bootQcInspectionsIndex = () => {
        const waitingTableEl = document.getElementById('waitingTable');
        if (!waitingTableEl || typeof window.DataTable !== 'function') return;

        const dtLang = {};
        const dtOpts = { order: [] };

        new DataTable(waitingTableEl, Object.assign({}, dtOpts, {
            processing: true,
            serverSide: true,
            ajax: '{{ route("qc.inspections.data-waiting") }}',
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold tw:text-on-surface' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false, className: 'tw:text-on-surface fw-medium' },
                { data: 'arrival_date', name: 'actual_arrival', className: 'ui-tabular-nums tw:text-on-surface-variant' },
                { data: 'item_count', name: 'item_count', orderable: false, searchable: false, className: 'text-center fw-semibold ui-tabular-nums' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: dtLang
        }));

        let historyInit = false;
        let historyTable = null;
        const historyTabBtn = document.querySelector('button[data-bs-target="#history"]');
        const waitingTabBtn = document.querySelector('button[data-bs-target="#waiting"]');
        const exportLinkEl = document.getElementById('inspectionExportLink');

        historyTabBtn?.addEventListener('shown.bs.tab', () => {
            exportLinkEl?.classList.remove('d-none');

            const historyTableEl = document.getElementById('historyTable');
            if (!historyInit && historyTableEl) {
                historyInit = true;
                historyTable = new DataTable(historyTableEl, Object.assign({}, dtOpts, {
                    processing: true,
                    serverSide: true,
                    ajax: {
                        url: '{{ route("qc.inspections.data-history") }}',
                        data: function(d) {
                            d.status = document.getElementById('historyStatusFilter')?.value ?? '';
                            const params = new URL(window.location.href).searchParams;
                            d.start_date = params.get('start_date') || '';
                            d.end_date = params.get('end_date') || '';
                        }
                    },
                    columns: [
                        { data: 'po_number', name: 'po_number', className: 'fw-bold tw:text-on-surface', orderable: false },
                        { data: 'supplier_name', name: 'supplier_name', orderable: false, className: 'tw:text-on-surface fw-medium' },
                        { data: 'inspected_date', name: 'inspected_at', className: 'ui-tabular-nums tw:text-on-surface-variant' },
                        { data: 'status_badge', name: 'status', className: 'text-center' },
                        { data: 'inspector_name', name: 'inspector_name', orderable: false, className: 'tw:text-on-surface-variant' },
                        { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
                    ],
                    language: dtLang
                }));
            }
        });

        waitingTabBtn?.addEventListener('shown.bs.tab', () => {
            exportLinkEl?.classList.add('d-none');
        });

        const updateInspectionFilterState = function() {
            const status = document.getElementById('historyStatusFilter')?.value ?? '';
            const url = new URL(window.location.href);
            const exportUrl = new URL(@json(route('qc.export.inspections')), window.location.origin);

            if (status) {
                url.searchParams.set('status', status);
                exportUrl.searchParams.set('status', status);
            } else {
                url.searchParams.delete('status');
                exportUrl.searchParams.delete('status');
            }

            window.history.replaceState({}, '', url.toString());

            if (historyTable) {
                historyTable.ajax.reload();
            }
        };

        window.AdasiFilterBar.bindLive('#qcHistoryFilterBar', {
            fields: [
                { key: 'status', label: @json(__('qc.copy.status')), selector: '#historyStatusFilter', type: 'select' },
            ],
            onFilterChange: updateInspectionFilterState,
        });

        @if(request('status'))
            if (historyTabBtn) {
                bootstrap.Tab.getOrCreateInstance(historyTabBtn).show();
            }
        @endif
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootQcInspectionsIndex, { once: true });
    } else {
        bootQcInspectionsIndex();
    }
})();
</script>
@endpush
