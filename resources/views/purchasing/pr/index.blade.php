@extends('layouts.app')

@section('title', 'Daftar Permintaan Material — ADASI Portal')
@section('page-title', 'Permintaan Material')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">Daftar Permintaan Material</h5>
        <div class="d-flex gap-2">
            <a href="{{ route('purchasing.export.requirements', request()->all()) }}" class="btn btn-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i> Export Excel
            </a>
            <a href="{{ route('purchasing.requirements.create') }}" class="btn btn-primary btn-sm" style="background-color: var(--adasi-blue); border-color: var(--adasi-blue);">
                <i class="bi bi-plus-circle me-1"></i> Buat Permintaan Baru
            </a>
        </div>
    </div>
    <div class="card-body">
        
        {{-- Filter Form --}}
        <form method="GET" action="{{ route('purchasing.requirements.index') }}" class="row g-3 mb-4">
            <div class="col-md-4">
                <label for="period_id" class="form-label small fw-medium">Filter Periode</label>
                <select name="period_id" id="period_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Periode</option>
                    @foreach($periods as $period)
                        <option value="{{ $period->id }}" {{ request('period_id') == $period->id ? 'selected' : '' }}>
                            {{ $period->name }} ({{ str_pad($period->month, 2, '0', STR_PAD_LEFT) }}/{{ $period->year }})
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label for="status" class="form-label small fw-medium">Filter Status</label>
                <select name="status" id="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="draft" {{ request('status') == 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="submitted" {{ request('status') == 'submitted' ? 'selected' : '' }}>Submitted</option>
                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                    <option value="bidding" {{ request('status') == 'bidding' ? 'selected' : '' }}>Bidding</option>
                    <option value="completed" {{ request('status') == 'completed' ? 'selected' : '' }}>Completed</option>
                </select>
            </div>
            <div class="col-md-4 d-flex align-items-end">
                <a href="{{ route('purchasing.requirements.index') }}" class="btn btn-light btn-sm w-100">Reset Filter</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th>No. PR</th>
                        <th>Periode</th>
                        <th>Dibuat Oleh</th>
                        <th>Jumlah Item</th>
                        <th>Status</th>
                        <th>Tanggal Dibuat</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requirements as $index => $pr)
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td class="fw-medium">{{ $pr->pr_number ?? '-' }}</td>
                            <td>{{ $pr->period->name }}</td>
                            <td>{{ $pr->creator->name ?? '-' }}</td>
                            <td>{{ $pr->items->count() }} Item</td>
                            <td>
                                @php
                                    $badgeClass = match($pr->status) {
                                        'draft' => 'bg-secondary',
                                        'submitted' => 'bg-primary',
                                        'rejected' => 'bg-danger',
                                        'bidding' => 'bg-warning text-dark',
                                        'completed' => 'bg-success', // Can use dark green if preferred
                                        default => 'bg-secondary'
                                    };
                                    $statusLabel = match($pr->status) {
                                        'draft' => 'Draft',
                                        'submitted' => 'Submitted',
                                        'rejected' => 'Rejected',
                                        'bidding' => 'Bidding',
                                        'completed' => 'Completed',
                                        default => ucwords(str_replace('_', ' ', $pr->status)),
                                    };
                                @endphp
                                <span class="badge {{ $badgeClass }} text-uppercase" style="font-size: 0.7rem;">
                                    {{ $statusLabel }}
                                </span>
                            </td>
                            <td>{{ $pr->created_at->format('d M Y, H:i') }}</td>
                            <td class="text-end">
                                <a href="{{ route('purchasing.requirements.show', $pr->id) }}" class="btn btn-sm btn-outline-info" title="Lihat Detail">
                                    <i class="bi bi-eye"></i>
                                </a>
                                @if($pr->created_by === auth()->id() && in_array($pr->status, ['draft', 'rejected']))
                                    <a href="{{ route('purchasing.requirements.edit', $pr->id) }}" class="btn btn-sm btn-outline-primary" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form action="{{ route('purchasing.requirements.destroy', $pr->id) }}" method="POST" class="d-inline delete-form">
                                        @csrf
                                        @method('DELETE')
                                        <button type="button" class="btn btn-sm btn-outline-danger btn-delete" title="Hapus">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $(document).ready(function() {
        // Initialize DataTable
        $('#prTable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json',
            },
            pageLength: 25,
            ordering: false // Let backend handle ordering or enable if needed
        });

        // SweetAlert Delete Confirmation
        $('.btn-delete').on('click', function() {
            const form = $(this).closest('form');
            Swal.fire({
                title: @json('Yakin ingin menghapus?'),
                text: @json('Permintaan material ini akan dihapus permanen!'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: @json('Ya, hapus!'),
                cancelButtonText: @json('Batal')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
