@extends('layouts.app')
@section('title', 'Supplier Dashboard - ADASI Portal')
@section('page-title', 'Dashboard Supplier')

@section('content')
{{-- Greeting Card --}}
<div class="card border-0 shadow-sm mb-4 text-white overflow-hidden position-relative animate-fade-in" style="background: linear-gradient(135deg, #1F5FA6 0%, #15457a 100%);">
    <div class="position-absolute top-0 end-0 h-100 w-50 opacity-25" style="background: radial-gradient(circle at top right, #ffffff, transparent);"></div>
    <div class="card-body p-4 position-relative z-1">
        <div class="row align-items-center">
            <div class="col-md-8">
                <h4 class="fw-bold mb-2">Selamat datang, {{ auth()->user()->supplier->company_name ?? auth()->user()->name }}! 👋</h4>
                <p class="mb-0 text-white-50">Here is a summary of your current material quotation activity and performance.</p>
            </div>
            <div class="col-md-4 text-end d-none d-md-block">
                <i class="bi bi-building fs-1 text-white opacity-50"></i>
            </div>
        </div>
    </div>
</div>

{{-- Insight & Alerts --}}
@if($belumDirespons > 0)
<div class="row mb-4 animate-fade-in">
    <div class="col-12">
        <div class="alert alert-info border-0 shadow-sm d-flex align-items-center gap-3 mb-0" style="background-color: #eef2ff; border-left: 4px solid #4f46e5 !important;">
            <div class="bg-primary bg-opacity-10 rounded-circle p-2 d-flex align-items-center justify-content-center" style="width: 48px; height: 48px;">
                <i class="bi bi-info-circle-fill fs-4 text-primary"></i>
            </div>
            <div>
                <h6 class="fw-bold mb-1 text-dark">Peluang Quotation</h6>
                <p class="mb-0 text-muted small">
                    There are <span class="text-primary fw-semibold">{{ $belumDirespons }} active requisitions (PR)</span> that you have not quoted yet. Submit your best price soon!
                </p>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Card Statistik --}}
<div class="row g-4 mb-4">
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-primary">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">ACTIVE PERIODS</div><h3 class="fw-bold mb-0">{{ $periodeAktif }}</h3></div>
                <div class="bg-primary bg-opacity-10 rounded-circle p-3"><i class="bi bi-calendar-event text-primary fs-4"></i></div>
            </div></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-danger">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">NOT RESPONDED</div><h3 class="fw-bold mb-0 text-danger">{{ $belumDirespons }}</h3></div>
                <div class="bg-danger bg-opacity-10 rounded-circle p-3"><i class="bi bi-exclamation-circle text-danger fs-4"></i></div>
            </div></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-success">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">SUBMITTED QUOTATIONS</div><h3 class="fw-bold mb-0 text-success">{{ $penawaranTerkirim }}</h3></div>
                <div class="bg-success bg-opacity-10 rounded-circle p-3"><i class="bi bi-send-check text-success fs-4"></i></div>
            </div></div>
        </div>
    </div>
    <div class="col-md-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100 border-start border-4 border-info">
            <div class="card-body"><div class="d-flex justify-content-between align-items-center">
                <div><div class="text-muted small fw-medium mb-1">RECEIVED PO</div><h3 class="fw-bold mb-0 text-info">{{ $poDiterima }}</h3></div>
                <div class="bg-info bg-opacity-10 rounded-circle p-3"><i class="bi bi-receipt text-info fs-4"></i></div>
            </div></div>
        </div>
    </div>
</div>

{{-- Tabel + Announcement --}}
<div class="row g-4">
    <div class="col-lg-8">
        {{-- PR Not Responded --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-exclamation-triangle text-danger me-1"></i> Unresponsive Requisitions</h6>
                <a href="{{ route('supplier.quotations.index') }}" class="btn btn-sm btn-light">View All</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>PR No.</th><th>Period</th><th>Amount Item</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                            @forelse($prBelumRespons as $pr)
                            <tr>
                                <td class="fw-bold">{{ $pr->pr_number ?? '-' }}</td>
                                <td>{{ $pr->period->name }}</td>
                                <td>{{ $pr->items->count() }} Item</td>
                                <td>{{ $pr->created_at->format('d M Y') }}</td>
                                <td class="text-end"><a href="{{ route('supplier.quotations.create', $pr->id) }}" class="btn btn-sm btn-primary py-0"><i class="bi bi-pencil-square me-1"></i>Create Quotation</a></td>
                            </tr>
                            @empty
                            <tr><td colspan="5" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success fs-4 d-block mb-2"></i>All requisitions have been responded to!</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        {{-- PO Terbaru --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Latest Purchase Orders</h6>
                <a href="{{ route('supplier.purchase-orders.index') }}" class="btn btn-sm btn-light">All PO</a>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light"><tr><th>PO No.</th><th>Period</th><th>Status</th><th>Date</th><th></th></tr></thead>
                        <tbody>
                            @forelse($poTerbaru as $po)
                            @php
                                $pendingClaim = $po->materialClaims
                                    ->where('status', 'pending')
                                    ->sortByDesc('created_at')
                                    ->first();
                                $latestClaim = $po->materialClaims
                                    ->sortByDesc('created_at')
                                    ->first();
                            @endphp
                            <tr>
                                <td class="fw-bold">{{ $po->po_number }}</td>
                                <td>{{ $po->quotations->map(fn($q) => optional(optional($q->purchaseRequisition)->period)->name)->filter()->first() ?? '-' }}</td>
                                <td><x-status-badge type="po" :status="$po->status" :is-overdue="$po->is_overdue" /></td>
                                <td>{{ $po->created_at->format('d M Y') }}</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1 justify-content-end flex-wrap">
                                        @if($pendingClaim)
                                            <a href="{{ route('supplier.claims.show', $pendingClaim->id) }}" class="btn btn-sm btn-danger" title="Claim Response">
                                                <i class="bi bi-reply"></i>
                                            </a>
                                        @elseif($latestClaim)
                                            <a href="{{ route('supplier.claims.show', $latestClaim->id) }}" class="btn btn-sm btn-outline-danger" title="View Claim">
                                                <i class="bi bi-exclamation-octagon"></i>
                                            </a>
                                        @endif
                                        <a href="{{ route('supplier.purchase-orders.show', $po->id) }}" class="btn btn-sm btn-outline-info" title="Details"><i class="bi bi-eye"></i></a>
                                    </div>
                                </td>
                            </tr>
                            @empty<tr><td colspan="5" class="text-center text-muted py-3">No PO.</td></tr>@endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    {{-- Announcement --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-megaphone text-primary me-1"></i> Announcement ADASI</h6></div>
            <div class="card-body p-0">
                @forelse($announcements as $ann)
                    <div class="p-3 border-bottom">
                        <h6 class="mb-1 small fw-bold"><a href="{{ route('supplier.announcements.show', $ann->id) }}" class="text-decoration-none">{{ $ann->title }}</a></h6>
                        <div class="text-muted small mb-2">{{ Str::limit($ann->content, 80) }}</div>
                        <small class="text-muted" style="font-size:.7rem"><i class="bi bi-clock me-1"></i>{{ $ann->published_at->diffForHumans() }}</small>
                    </div>
                @empty
                    <div class="p-4 text-center text-muted small">No new announcements.</div>
                @endforelse
            </div>
            @if($announcements->count() > 0)
            <div class="card-footer bg-white text-center"><a href="{{ route('supplier.announcements.index') }}" class="small text-decoration-none fw-bold">View All Announcement</a></div>
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
