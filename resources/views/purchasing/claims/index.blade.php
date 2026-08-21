@extends('layouts.app')

@section('title', 'Material Claims - ADASI Portal')
@section('page-title', 'Material Claims')

@push('styles')
<style>
    .claim-nav-tabs .nav-link {
        color: var(--md-on-surface-variant);
        font-weight: 600;
        font-size: var(--ui-font-size-sm);
        padding: 0.65rem 1.25rem;
        border: 0;
        border-bottom: 2px solid transparent;
        background: transparent;
        transition: color 0.15s ease, border-color 0.15s ease;
    }

    .claim-nav-tabs .nav-link:hover {
        color: var(--md-primary);
    }

    .claim-nav-tabs .nav-link.active {
        color: var(--md-primary);
        border-bottom-color: var(--md-primary);
        background: transparent;
    }
</style>
@endpush

@section('content')
<div class="tw-grid tw-gap-4">
    {{-- 1. Compact Page Header --}}
    <x-ui.page-header
        title="Material Claims"
        eyebrow="Purchasing"
        description="Submit claims for NG quality inspections and monitor supplier resolution progress."
    />

    {{-- 2. Tabbed Data Card --}}
    <x-ui.card padding="none" class="tw-overflow-hidden">
        <div class="tw-border-b tw-border-outline-variant tw-px-4 tw-pt-1 tw-bg-surface-low">
            <ul class="nav claim-nav-tabs" id="claimTabs" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active d-inline-flex align-items-center gap-2" id="action-tab" data-bs-toggle="tab" data-bs-target="#action" type="button" role="tab" aria-controls="action" aria-selected="true">
                        <span>Action Required</span>
                        @if($actionCount > 0)
                            <span class="ui-status-chip ui-status-chip--error ui-tabular-nums">{{ $actionCount }}</span>
                        @else
                            <span class="ui-status-chip ui-status-chip--neutral ui-tabular-nums">0</span>
                        @endif
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab" aria-controls="history" aria-selected="false">
                        <span>Claim History</span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="tw-p-3.5 shell:p-4">
            <div class="tab-content" id="claimTabsContent">
                {{-- Tab 1: Action Required --}}
                <div class="tab-pane fade show active" id="action" role="tabpanel" aria-labelledby="action-tab" tabindex="0">
                    <x-ui.alert tone="warning" title="Claim initiation required" class="tw-mb-3">The purchase orders below failed QC inspection with an NG result. Submit a formal claim to initiate replacement or compensation.</x-ui.alert>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="actionTable">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">PO Number</th>
                                    <th scope="col">Supplier</th>
                                    <th scope="col">Inspection Date</th>
                                    <th scope="col" class="text-center">PO Status</th>
                                    <th scope="col" class="text-end" style="width: 130px;">Action</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>

                {{-- Tab 2: Claim History --}}
                <div class="tab-pane fade" id="history" role="tabpanel" aria-labelledby="history-tab" tabindex="0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tw-text-ui-sm w-100" id="historyTable">
                            <thead class="table-light">
                                <tr>
                                    <th scope="col">Claim ID</th>
                                    <th scope="col">PO Number</th>
                                    <th scope="col">Supplier</th>
                                    <th scope="col">Date Submitted</th>
                                    <th scope="col">
                                        Response Deadline
                                        <x-ui.icon name="info" class="ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Deadline for supplier to formally respond to material claims." />
                                    </th>
                                    <th scope="col" class="text-center">Claim Status</th>
                                    <th scope="col" class="text-end" style="width: 80px;">Action</th>
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
                { data: 'po_number_display', name: 'po_number', className: 'fw-bold tw-text-on-surface' },
                { data: 'supplier_name', name: 'supplier_name', orderable: false },
                { data: 'inspection_date', name: 'inspection_date', orderable: false, searchable: false, className: 'tw-text-on-surface-variant' },
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
                        { data: 'claim_id', name: 'id', className: 'fw-bold tw-text-on-surface' },
                        { data: 'po_number', name: 'po_number', orderable: false },
                        { data: 'supplier_name', name: 'supplier_name', orderable: false },
                        { data: 'created_date', name: 'created_at', className: 'tw-text-on-surface-variant' },
                        { data: 'deadline_display', name: 'deadline', className: 'tw-text-on-surface-variant' },
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
