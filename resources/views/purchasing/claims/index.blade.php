@extends('layouts.app')

@section('title', 'Klaim Material — ADASI Portal')
@section('page-title', 'Klaim Material')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white pt-3 pb-0 border-bottom-0">
        <ul class="nav nav-tabs border-bottom-0" id="claimTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-medium px-4 pb-3" id="action-tab" data-bs-toggle="tab" data-bs-target="#action" type="button" role="tab">
                    Perlu Tindakan <span class="badge bg-danger ms-2">{{ $actionCount }}</span>
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-medium px-4 pb-3" id="history-tab" data-bs-toggle="tab" data-bs-target="#history" type="button" role="tab">
                    Riwayat Klaim
                </button>
            </li>
        </ul>
    </div>
    <div class="card-body border-top">
        <div class="tab-content" id="claimTabsContent">
            {{-- Tab: Perlu Tindakan --}}
            <div class="tab-pane fade show active" id="action" role="tabpanel">
                <div class="alert alert-info small mb-4">
                    <i class="bi bi-info-circle-fill me-1"></i> Daftar PO di bawah ini telah diinspeksi oleh QC dan berstatus NG (Not Good). Silakan ajukan klaim kepada supplier terkait.
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="actionTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>Nomor PO</th>
                                <th>Supplier</th>
                                <th>Tanggal Inspeksi</th>
                                <th class="text-center">Status PO</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            {{-- Tab: Riwayat Klaim --}}
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="historyTable" style="width: 100%;">
                        <thead class="table-light">
                            <tr>
                                <th>ID Klaim</th>
                                <th>Nomor PO</th>
                                <th>Supplier</th>
                                <th>Tanggal Diajukan</th>
                                <th>
                                    Deadline
                                    <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Batas waktu supplier merespons klaim material."></i>
                                </th>
                                <th class="text-center">Status Klaim</th>
                                <th class="text-end">Aksi</th>
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
        var dtLang = { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' };
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
