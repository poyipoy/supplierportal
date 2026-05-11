@extends('layouts.app')

@section('title', 'Daftar Permintaan: ' . $period->name . ' — ADASI Portal')
@section('page-title', 'Permintaan Material' . ': ' . $period->name)

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.quotations.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Periode
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3">
        <h5 class="mb-0 fw-semibold">Daftar Permintaan Pembelian</h5>
    </div>
    <div class="card-body">
        <form method="GET" action="{{ route('supplier.quotations.period', $period->id) }}" class="row g-3 align-items-end mb-4" id="quotationFilterForm">
            <div class="col-md-7">
                <label class="form-label small fw-bold">Nomor PR</label>
                <div class="position-relative">
                    <input type="text"
                           name="pr_number"
                           value="{{ request('pr_number') }}"
                           class="form-control form-control-sm pe-5"
                           id="prNumberFilter"
                           placeholder="Cari nomor PR... (REQ/MM/YYYY/XXX)">
                    <button type="button"
                            class="btn btn-sm btn-link text-muted position-absolute top-50 end-0 translate-middle-y {{ request('pr_number') ? '' : 'd-none' }}"
                            id="clearPrNumber"
                            aria-label="Hapus filter nomor PR"
                            style="text-decoration:none;">&times;</button>
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-bold">Status Penawaran</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="unresponded" {{ request('status') === 'unresponded' ? 'selected' : '' }}>Belum Direspons</option>
                    <option value="draft" {{ request('status') === 'draft' ? 'selected' : '' }}>Draft</option>
                    <option value="submitted" {{ request('status') === 'submitted' ? 'selected' : '' }}>Terkirim</option>
                    <option value="rejected" {{ request('status') === 'rejected' ? 'selected' : '' }}>Ditolak</option>
                </select>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-sm btn-primary flex-fill" style="background-color: var(--adasi-blue);">
                    <i class="bi bi-search"></i>
                </button>
                <a href="{{ route('supplier.quotations.period', $period->id) }}" class="btn btn-sm btn-light border flex-fill">Reset</a>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle" id="prTable">
                <thead class="table-light">
                    <tr>
                        <th>No</th>
                        <th>Nomor PR</th>
                        <th>Tanggal Diajukan</th>
                        <th>Jumlah Item</th>
                        <th>Status Penawaran Saya</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($requirements as $index => $pr)
                        @php
                            $quotation = $pr->quotations->first();
                            $status = $quotation ? $quotation->status : 'unresponded';
                        @endphp
                        <tr>
                            <td>{{ $index + 1 }}</td>
                            <td class="fw-medium">{{ $pr->pr_number ?? '-' }}</td>
                            <td>{{ $pr->updated_at->format('d M Y, H:i') }}</td>
                            <td>{{ $pr->items->count() }} Item</td>
                            <td>
                                @if($status === 'unresponded')
                                    <span class="badge bg-danger">Belum Direspons</span>
                                @elseif($status === 'draft')
                                    <span class="badge bg-secondary">Draft</span>
                                @elseif($status === 'submitted')
                                    <span class="badge bg-success">Terkirim ({{ $quotation->submitted_at->format('d M Y H:i') }})</span>
                                @elseif($status === 'rejected')
                                    <span class="badge bg-dark">Ditolak</span>
                                @endif
                            </td>
                            <td class="text-end">
                                @if($status === 'unresponded')
                                    <a href="{{ route('supplier.quotations.create', $pr->id) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-pencil-square me-1"></i> Buat Penawaran
                                    </a>
                                @elseif($status === 'draft')
                                    <a href="{{ route('supplier.quotations.create', $pr->id) }}" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil me-1"></i> Lanjutkan
                                    </a>
                                @elseif($status === 'submitted' || $status === 'rejected')
                                    <a href="{{ route('supplier.quotations.show', $quotation->id) }}" class="btn btn-sm btn-outline-success">
                                        <i class="bi bi-eye me-1"></i> Lihat
                                    </a>
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
        const filterForm = document.getElementById('quotationFilterForm');
        const prNumberFilter = document.getElementById('prNumberFilter');
        const clearPrNumber = document.getElementById('clearPrNumber');
        let debounceTimer;

        prNumberFilter.addEventListener('input', function() {
            clearPrNumber.classList.toggle('d-none', this.value.length === 0);
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => filterForm.submit(), 400);
        });

        clearPrNumber.addEventListener('click', function() {
            prNumberFilter.value = '';
            clearPrNumber.classList.add('d-none');
            filterForm.submit();
        });

        $('#prTable').DataTable({
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/id.json' },
            pageLength: 25,
            ordering: false
        });
    });
</script>
@endpush
