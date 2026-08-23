@extends('layouts.app')
@section('uses-datatables', true)

@section('title', 'QC Inspections - ADASI Portal')
@section('page-title', 'QC Inspections')

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- Breadcrumb & Compact Page Header --}}
    <x-ui.breadcrumb :items="[
        'Dashboard' => route('qc.dashboard'),
        'QC Inspections' => null,
    ]" />

    <x-ui.page-header
        title="QC Inspections"
        eyebrow="Quality Control"
        description="Process arrived shipments waiting for quality evaluation and review completed inspection history."
    >
        <x-slot:actions>
            <x-ui.button
                :href="route('qc.export.inspections', request()->all())"
                variant="outline"
                size="sm"
                class="d-none"
                id="inspectionExportLink"
                data-async-export
            >
                <x-ui.icon name="file-spreadsheet" />
                <span>Export History</span>
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Tabs Shell --}}
    <section class="tw-overflow-hidden tw-rounded-ui-md tw-border tw-border-outline-variant tw-bg-surface" aria-label="Inspection queues">
        <div class="tw-flex tw-items-center tw-justify-between tw-border-b tw-border-outline-variant tw-bg-surface-low tw-px-4 tw-pt-3">
            <ul class="nav nav-tabs border-bottom-0" id="inspectionTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link active fw-semibold px-4 tw-py-2.5 d-inline-flex align-items-center gap-2"
                        id="waiting-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#waiting"
                        type="button"
                        role="tab"
                        aria-controls="waiting"
                        aria-selected="true"
                    >
                            <x-ui.icon name="clock" size="sm" />
                        <span>Waiting for Inspection</span>
                        <span class="ui-status-chip ui-status-chip--warning ui-tabular-nums">{{ $waitingCount }}</span>
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button
                        class="nav-link fw-semibold px-4 tw-py-2.5 d-inline-flex align-items-center gap-2"
                        id="history-tab"
                        data-bs-toggle="tab"
                        data-bs-target="#history"
                        type="button"
                        role="tab"
                        aria-controls="history"
                        aria-selected="false"
                    >
                            <x-ui.icon name="history" size="sm" />
                        <span>Inspection History</span>
                        <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">{{ $historyCount }}</span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="p-4">
            <div class="tab-content" id="inspectionTabsContent">
                {{-- Tab 1: Waiting for Inspection --}}
                <div class="tab-pane fade show active" id="waiting" role="tabpanel" aria-labelledby="waiting-tab" tabindex="0">
                    <x-ui.alert tone="info" class="tw-mb-3">Shipments in this queue have arrived at the warehouse and require physical QC inspection.</x-ui.alert>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="waitingTable">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">PO Number</th>
                                    <th scope="col">Supplier Name</th>
                                    <th scope="col">Date Material Arrived</th>
                                    <th scope="col" class="text-center">Total Items</th>
                                    <th scope="col" class="text-end" style="width: 140px;">Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                {{-- Tab 2: Inspection History --}}
                <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
                    {{-- Filter Toolbar --}}
                    <x-ui.toolbar class="tw-mb-3">
                        <div class="row g-2 align-items-end tw-w-full">
                            <div class="col-12 col-md-4 col-lg-3">
                                <label for="historyStatusFilter" class="form-label small fw-semibold tw-text-on-surface mb-1">
                                    Inspection Outcome Status
                                </label>
                                <select id="historyStatusFilter" class="form-select form-select-sm">
                                    <option value="">All Statuses (OK &amp; NG)</option>
                                    <option value="ok" @selected(request('status') === 'ok')>OK (Pass)</option>
                                    <option value="ng" @selected(request('status') === 'ng')>NG (Defective / Claim)</option>
                                </select>
                            </div>
                            <div class="col-12 col-md-auto">
                                <x-ui.button type="button" variant="outline" size="sm" id="resetHistoryStatusFilter">
                            <x-ui.icon name="rotate-ccw" size="sm" />
                                    <span>Reset Filter</span>
                                </x-ui.button>
                            </div>
                        </div>
                    </x-ui.toolbar>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-xs w-100" id="historyTable">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">PO Number</th>
                                    <th scope="col">Supplier Name</th>
                                    <th scope="col">Inspection Date</th>
                                    <th scope="col" class="text-center">Status</th>
                                    <th scope="col">Inspected By</th>
                                    <th scope="col" class="text-end" style="width: 110px;">Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var dtLang = {};
        var dtOpts = { pageLength: 25, order: [] };

        $('#waitingTable').DataTable(Object.assign({}, dtOpts, {
            processing: true,
            serverSide: true,
            ajax: '{{ route("qc.inspections.data-waiting") }}',
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false, className: 'tw-text-on-surface fw-medium' },
                { data: 'arrival_date', name: 'actual_arrival', className: 'ui-tabular-nums tw-text-on-surface-variant' },
                { data: 'item_count', name: 'item_count', orderable: false, searchable: false, className: 'text-center fw-semibold ui-tabular-nums' },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: dtLang
        }));

        var historyInit = false;
        var historyTable = null;
        $('button[data-bs-target="#history"]').on('shown.bs.tab', function() {
            $('#inspectionExportLink').removeClass('d-none');

            if (!historyInit) {
                historyInit = true;
                historyTable = $('#historyTable').DataTable(Object.assign({}, dtOpts, {
                    processing: true,
                    serverSide: true,
                    ajax: {
                        url: '{{ route("qc.inspections.data-history") }}',
                        data: function(d) {
                            d.status = $('#historyStatusFilter').val();
                        }
                    },
                    columns: [
                        { data: 'po_number', name: 'po_number', className: 'fw-bold tw-text-on-surface', orderable: false },
                        { data: 'supplier_name', name: 'supplier_name', orderable: false, className: 'tw-text-on-surface fw-medium' },
                        { data: 'inspected_date', name: 'inspected_at', className: 'ui-tabular-nums tw-text-on-surface-variant' },
                        { data: 'status_badge', name: 'status', className: 'text-center' },
                        { data: 'inspector_name', name: 'inspector_name', orderable: false, className: 'tw-text-on-surface-variant' },
                        { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
                    ],
                    language: dtLang
                }));
            }
        });

        $('button[data-bs-target="#waiting"]').on('shown.bs.tab', function() {
            $('#inspectionExportLink').addClass('d-none');
        });

        const updateInspectionFilterState = function() {
            const status = $('#historyStatusFilter').val();
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
            $('#inspectionExportLink').attr('href', exportUrl.toString());

            if (historyTable) {
                historyTable.ajax.reload();
            }
        };

        $('#historyStatusFilter').on('change', updateInspectionFilterState);
        $('#resetHistoryStatusFilter').on('click', function() {
            $('#historyStatusFilter').val('');
            updateInspectionFilterState();
        });

        @if(request('status'))
            const historyTabTrigger = document.querySelector('button[data-bs-target="#history"]');
            if (historyTabTrigger) {
                bootstrap.Tab.getOrCreateInstance(historyTabTrigger).show();
            }
        @endif
    });
</script>
@endpush
