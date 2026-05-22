@extends('layouts.app')

@section('title', 'Klaim Material — ADASI Portal')
@section('page-title', 'Klaim Material')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Daftar Klaim Material dari ADASI</h5>
    </div>
    <div class="card-body">
        <div class="alert alert-warning small mb-4">
            <i class="bi bi-exclamation-triangle-fill me-1"></i> Daftar di bawah adalah klaim material NG (Not Good) yang diajukan oleh tim Purchasing ADASI. Harap segera merespons klaim yang berstatus <strong>PENDING</strong> sebelum batas waktu (deadline).
        </div>
        
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="claimTable" style="width: 100%;">
                <thead class="table-light">
                    <tr>
                        <th>ID Klaim</th>
                        <th>Nomor PO</th>
                        <th>Tanggal Diajukan</th>
                        <th>Deadline</th>
                        <th class="text-center">Status</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        $('#claimTable').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ route("supplier.claims.index") }}',
            columns: [
                { data: 'claim_id', name: 'id', className: 'fw-medium' },
                { data: 'po_number', name: 'po_number', className: 'fw-bold', orderable: false },
                { data: 'created_date', name: 'created_at' },
                { data: 'deadline_display', name: 'deadline' },
                { data: 'status_badge', name: 'status', className: 'text-center', searchable: false },
                { data: 'action', name: 'action', orderable: false, searchable: false, className: 'text-end' }
            ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
            pageLength: 25,
            order: []
        });
    });
</script>
@endpush
