@extends('layouts.app')

@section('title', 'QC Inspections - ADASI Portal')
@section('page-title', 'QC Inspections')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white pt-3 pb-0 border-bottom-0 d-flex justify-content-between align-items-start">
        <ul class="nav nav-tabs border-bottom-0" id="inspectionTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-medium px-4 pb-3" id="waiting-tab" data-bs-toggle="tab" data-bs-target="#waiting" type="button" role="tab">
                    Waiting for Inspection <span class="badge bg-warning text-dark ms-2">{{ $waitingCount }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-medium px-4 pb-3" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                    Inspection History <span class="badge bg-secondary ms-2">{{ $historyCount }}</span>
                </button>
            </li>
        </ul>
        <a href="{{ route('qc.export.inspections', request()->all()) }}" class="btn btn-success btn-sm align-self-center d-none" id="inspectionExportLink">
            <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
        </a>
    </div>
    <div class="card-body border-top">
        <div class="tab-content" id="inspectionTabsContent">
            {{-- Tab: Waiting for Inspection --}}
            <div class="tab-pane fade show active" id="waiting" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="waitingTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>PO No.</th>
                                <th>Supplier</th>
                                <th>Date Material Arrived</th>
                                <th>Amount Item</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            {{-- Tab: Inspection History --}}
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="bg-light border rounded-3 p-3 mb-3">
                    <div class="row g-2 align-items-end">
                        <div class="col-12 col-md-4 col-lg-3">
                            <label for="historyStatusFilter" class="form-label small fw-semibold text-muted mb-1">
                                Search Inspection Status
                            </label>
                            <select id="historyStatusFilter" class="form-select form-select-sm">
                                <option value="">All Status</option>
                                <option value="ok" @selected(request('status') === 'ok')>OK</option>
                                <option value="ng" @selected(request('status') === 'ng')>NG (Not Good)</option>
                            </select>
                        </div>
                        <div class="col-12 col-md-auto">
                            <button type="button" class="btn btn-sm btn-outline-secondary w-100" id="resetHistoryStatusFilter">
                                <i class="bi bi-x-circle me-1"></i> Reset
                            </button>
                        </div>
                        <div class="col-12 col-md">
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="historyTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>PO No.</th>
                                <th>Supplier</th>
                                <th>Inspection Date</th>
                                <th class="text-center">Status</th>
                                <th>Inspected By</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
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
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
                { data: 'arrival_date', name: 'actual_arrival' },
                { data: 'item_count', name: 'item_count', orderable: false, searchable: false },
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
                        { data: 'po_number', name: 'po_number', className: 'fw-bold', orderable: false },
                        { data: 'supplier_name', name: 'supplier_name', orderable: false },
                        { data: 'inspected_date', name: 'inspected_at' },
                        { data: 'status_badge', name: 'status', className: 'text-center' },
                        { data: 'inspector_name', name: 'inspector_name', orderable: false },
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
            }

            window.history.replaceState({}, '', url);
            $('#inspectionExportLink').attr('href', exportUrl.toString());
        };

        updateInspectionFilterState();

        $('#historyStatusFilter').on('change', function() {
            updateInspectionFilterState();
            if (historyTable) {
                historyTable.ajax.reload(null, true);
            }
        });

        $('#resetHistoryStatusFilter').on('click', function() {
            $('#historyStatusFilter').val('').trigger('change');
        });
    });
</script>
@endpush
