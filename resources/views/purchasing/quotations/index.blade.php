@extends('layouts.app')
@section('title', 'Daftar Penawaran — ADASI Portal')
@section('page-title', 'Penawaran Supplier')

@section('content')
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-semibold">Daftar Penawaran Masuk</h5>
        <span class="badge bg-primary">{{ $quotations->total() }} Penawaran</span>
    </div>
    <div class="card-body">
        {{-- Filter --}}
        <form method="GET" action="{{ route('purchasing.quotations.index') }}" class="row g-3 mb-4">
            <div class="col-md-3">
                <label class="form-label small fw-medium">Periode</label>
                <select name="period_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Periode</option>
                    @foreach($periods as $period)
                        <option value="{{ $period->id }}" {{ request('period_id') == $period->id ? 'selected' : '' }}>{{ $period->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-medium">Supplier</label>
                <select name="supplier_id" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Supplier</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->id }}" {{ request('supplier_id') == $supplier->id ? 'selected' : '' }}>{{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium">Status</label>
                <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua Status</option>
                    <option value="submitted" {{ request('status') == 'submitted' ? 'selected' : '' }}>Submitted</option>
                    <option value="accepted" {{ request('status') == 'accepted' ? 'selected' : '' }}>Accepted</option>
                    <option value="rejected" {{ request('status') == 'rejected' ? 'selected' : '' }}>Rejected</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-medium">Mata Uang</label>
                <select name="currency" class="form-select form-select-sm" onchange="this.form.submit()">
                    <option value="">Semua</option>
                    <option value="USD" {{ request('currency') == 'USD' ? 'selected' : '' }}>USD</option>
                    <option value="JPY" {{ request('currency') == 'JPY' ? 'selected' : '' }}>JPY</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <a href="{{ route('purchasing.quotations.index') }}" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
            </div>
        </form>

        {{-- Tabel --}}
        <div class="table-responsive">
            <table class="table table-hover align-middle" style="font-size:.85rem">
                <thead class="table-light">
                    <tr>
                        <th width="4%">No</th>
                        <th>Supplier</th>
                        <th>No. PR</th>
                        <th>Periode</th>
                        <th class="text-center">Mata Uang</th>
                        <th class="text-center">Jumlah Item</th>
                        <th class="text-center">Status</th>
                        <th>Tanggal Kirim</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($quotations as $i => $q)
                    <tr>
                        <td>{{ $quotations->firstItem() + $i }}</td>
                        <td class="fw-medium">
                            <div class="d-flex align-items-center gap-2">
                                <div class="bg-primary bg-opacity-10 rounded-circle d-flex align-items-center justify-content-center" style="width:32px;height:32px;">
                                    <i class="bi bi-building text-primary small"></i>
                                </div>
                                {{ $q->supplier->name }}
                            </div>
                        </td>
                        <td><span class="fw-bold text-primary">{{ $q->purchaseRequirement->pr_number ?? '-' }}</span></td>
                        <td>{{ $q->purchaseRequirement->period->name ?? '-' }}</td>
                        <td class="text-center"><span class="badge bg-dark">{{ $q->currency }}</span></td>
                        <td class="text-center">{{ $q->items->count() }}</td>
                        <td class="text-center">
                            @php
                                $sc = match($q->status) {
                                    'submitted' => 'bg-primary',
                                    'accepted' => 'bg-success',
                                    'rejected' => 'bg-danger',
                                    default => 'bg-secondary'
                                };
                            @endphp
                            <span class="badge {{ $sc }} text-uppercase" style="font-size:.65rem">{{ $q->status }}</span>
                        </td>
                        <td>{{ $q->submitted_at ? $q->submitted_at->format('d M Y, H:i') : '-' }}</td>
                        <td class="text-end">
                            <a href="{{ route('purchasing.quotations.show', $q->id) }}" class="btn btn-sm btn-outline-info py-0 px-2">
                                <i class="bi bi-eye me-1"></i>Detail
                            </a>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-inbox" style="font-size:2rem"></i><p class="mt-2 mb-0">Belum ada penawaran masuk.</p></td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $quotations->links() }}</div>
    </div>
</div>
@endsection
