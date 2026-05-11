@extends('layouts.app')
@section('title', 'Dashboard Supplier — ADASI Portal')
@section('page-title', 'Dashboard Supplier')

@section('content')
{{-- Card Statistik --}}
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">PERIODE AKTIF</div><h3 class="fw-bold mb-0">{{ $periodeAktif }}</h3></div>
                <div class="bg-primary bg-opacity-10 rounded-circle p-3"><i class="bi bi-calendar-event text-primary fs-4"></i></div>
            </div></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">BELUM DIRESPONS</div><h3 class="fw-bold mb-0 text-danger">{{ $belumDirespons }}</h3></div>
                <div class="bg-danger bg-opacity-10 rounded-circle p-3"><i class="bi bi-exclamation-circle text-danger fs-4"></i></div>
            </div></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">PENAWARAN TERKIRIM</div><h3 class="fw-bold mb-0 text-success">{{ $penawaranTerkirim }}</h3></div>
                <div class="bg-success bg-opacity-10 rounded-circle p-3"><i class="bi bi-send-check text-success fs-4"></i></div>
            </div></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">PO DITERIMA</div><h3 class="fw-bold mb-0 text-info">{{ $poDiterima }}</h3></div>
                <div class="bg-info bg-opacity-10 rounded-circle p-3"><i class="bi bi-receipt text-info fs-4"></i></div>
            </div></div>
        </div>
    </div>
</div>

{{-- Tabel + Pengumuman --}}
<div class="row g-4">
    <div class="col-lg-8">
        {{-- PR Belum Direspons --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-exclamation-triangle text-danger me-1"></i> Permintaan Belum Direspons</h6>
                <a href="{{ route('supplier.quotations.index') }}" class="btn btn-sm btn-light">Lihat Semua</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>No. PR</th><th>Periode</th><th>Jumlah Item</th><th>Tanggal</th><th></th></tr></thead>
                        <tbody>
                            @forelse($prBelumRespons as $pr)
                            <tr>
                                <td class="fw-bold">{{ $pr->pr_number ?? '-' }}</td>
                                <td>{{ $pr->period->name }}</td>
                                <td>{{ $pr->items->count() }} Item</td>
                                <td>{{ $pr->created_at->format('d M Y') }}</td>
                                <td class="text-end"><a href="{{ route('supplier.quotations.create', $pr->id) }}" class="btn btn-sm btn-primary py-0"><i class="bi bi-pencil-square me-1"></i>Buat Penawaran</a></td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-4 d-block mb-2"></i>Semua permintaan sudah direspons!</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- PO Terbaru --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Purchase Order Terbaru</h6>
                <a href="{{ route('supplier.purchase-orders.index') }}" class="btn btn-sm btn-light">Semua PO</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>No. PO</th><th>Periode</th><th>Status</th><th>Tanggal</th><th></th></tr></thead>
                        <tbody>
                            @forelse($poTerbaru as $po)
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ optional(optional($po->quotation)->purchaseRequirement)->period->name ?? '-' }}</td>
                                <td>@php $c=match($po->status){'active'=>'bg-primary','waiting_qc'=>'bg-warning text-dark','completed'=>'bg-success','claim_needed'=>'bg-danger',default=>'bg-secondary'};@endphp<span class="badge {{ $c }} text-uppercase" style="font-size:.65rem">{{ ucwords(str_replace('_', ' ', $po->status)) }}</span></td>
                                <td>{{ $po->created_at->format('d M Y') }}</td>
                                <td class="text-end"><a href="{{ route('supplier.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info py-0"><i class="bi bi-eye"></i></a></td>
                            </tr>
                            @empty<tr><td colspan="5" class="text-center text-muted py-3">Belum ada PO.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    {{-- Pengumuman --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-megaphone text-primary me-1"></i> Pengumuman ADASI</h6></div>
            <div class="card-body p-0">
                @forelse($announcements as $ann)
                    <div class="p-3 border-bottom">
                        <h6 class="mb-1 small fw-bold"><a href="{{ route('supplier.announcements.show', $ann->id) }}" class="text-decoration-none">{{ $ann->title }}</a></h6>
                        <div class="text-muted small mb-2">{{ Str::limit($ann->content, 80) }}</div>
                        <small class="text-muted" style="font-size:.7rem"><i class="bi bi-clock me-1"></i>{{ $ann->published_at->diffForHumans() }}</small>
                    </div>
                @empty
                    <div class="p-4 text-center text-muted small">Belum ada pengumuman baru.</div>
                @endforelse
            </div>
            @if($announcements->count() > 0)
            <div class="card-footer bg-white text-center"><a href="{{ route('supplier.announcements.index') }}" class="small text-decoration-none fw-bold">Lihat Semua Pengumuman</a></div>
            @endif
        </div>
        <div class="card border-0 shadow-sm bg-primary text-white">
            <div class="card-body text-center py-4">
                <i class="bi bi-truck" style="font-size:2.5rem;opacity:.7"></i>
                <h5 class="mt-3 fw-bold">Selamat Datang!</h5>
                <p class="mb-0 small" style="opacity:.8">{{ auth()->user()->name }}</p>
                <p class="mb-0 small" style="opacity:.6">Portal Pengadaan Material Impor ADASI</p>
            </div>
        </div>
    </div>
</div>
@endsection
