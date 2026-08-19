@extends('layouts.app')

@section('title', 'Material Claim - ADASI Portal')
@section('page-title', 'Material Claim')

@section('content')
<div class="tw-grid tw-gap-6">
    <x-ui.page-header title="Material Claims" description="Submit claims for NG inspections and follow supplier resolution status." eyebrow="Purchasing" />
<x-ui.card padding="none" class="ui-data-table">
    <div class="tw-border-b tw-border-outline-variant tw-px-4 tw-pt-3 shell:tw-px-5">
        <ul class="nav nav-tabs border-bottom-0" id="claimTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-medium px-4 pb-3" id="action-tab" data-bs-toggle="tab" data-bs-target="#action" type="button" role="tab">
                    Perlu Tindakan <span class="badge bg-danger ms-2">{{ $actionCount }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-medium px-4 pb-3" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                    History Claim
                </button>
            </li>
        </ul>
    </div>
    <div class="tw-p-4 shell:tw-p-5">
        <div class="tab-content" id="claimTabsContent">
            {{-- Tab: Perlu Tindakan --}}
            <div class="tab-pane fade show active" id="action" role="tabpanel">
                <x-ui.alert class="tw-mb-4">The PO list below has been inspected by QC with an NG result. Submit a claim to the relevant supplier.</x-ui.alert>
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="actionTable">
                        <thead class="table-light">
                            <tr>
                                <th>Number PO</th>
                                <th>Supplier</th>
                                <th>Inspection Date</th>
                                <th class="text-center">Status PO</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            {{-- Tab: History Claim --}}
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="historyTable">
                        <thead class="table-light">
                            <tr>
                                <th>Claim ID</th>
                                <th>Number PO</th>
                                <th>Supplier</th>
                                <th>Date Submitted</th>
                                <th>
                                    Deadline
                                    <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Deadline for supplier to respond to material claims."></i>
                                </th>
                                <th class="text-center">Claim Status</th>
                                <th class="text-end">Action</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-ui.card>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        var dtLang = {};
        var dtOpts = { pageLength: 25, order: [] };

        $('#actionTable').DataTable(Object.assign({}, dtOpts, {
            processing: true,
            serverSide: true,
            ajax: '{{ route("purchasing.claims.data-action") }}',
            columns: [
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
                { data: 'inspection_date', name: 'inspection_date', orderable: false, searchable: false },
                { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: dtLang
        }));

        var historyInit = false;
        $('button[data-bs-target="#history"]').on('shown.bs.tab', function() {
            if (!historyInit) {
                historyInit = true;
                $('#historyTable').DataTable(Object.assign({}, dtOpts, {
                    processing: true,
                    serverSide: true,
                    ajax: '{{ route("purchasing.claims.data-history") }}',
                    columns: [
                        { data: 'claim_id', name: 'id', className: 'fw-medium' },
                        { data: 'po_number', name: 'po_number', orderable: false },
                        { data: 'supplier_name', name: 'supplier_name', orderable: false },
                        { data: 'created_date', name: 'created_at' },
                        { data: 'deadline_display', name: 'deadline' },
                        { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                        { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
                    ],
                    language: dtLang,
                    drawCallback: function() {
                        window.initAdasiTooltips?.(document.getElementById('historyTable'));
                    }
                }));
            }
        });
    });
</script>
@endpush
