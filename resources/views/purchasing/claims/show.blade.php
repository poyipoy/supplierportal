@extends('layouts.app')

@section('title', 'Detail Klaim #' . $claim->id . ' — ADASI Portal')
@section('page-title', 'Detail Klaim Material')

@section('content')
<div class="mb-3">
    <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.claims.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Klaim
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Detail Klaim --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Klaim #{{ $claim->id }}</h6>
                @php
                    $badgeClass = match($claim->status) {
                        'pending' => 'bg-warning text-dark',
                        'responded' => 'bg-info',
                        'resolved' => 'bg-success',
                        'escalated' => 'bg-danger',
                        default => 'bg-secondary'
                    };
                @endphp
                <span class="badge {{ $badgeClass }} text-uppercase px-3 py-2">{{ $claim->status }}</span>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Nomor PO</div>
                    <div class="col-md-9 fw-bold">{{ $claim->purchaseOrder->po_number }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Supplier</div>
                    <div class="col-md-9 fw-medium">{{ $claim->purchaseOrder->supplier->name }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Diajukan Oleh</div>
                    <div class="col-md-9 fw-medium">{{ $claim->submitter->name }} <span class="text-muted small">({{ $claim->created_at->format('d M Y, H:i') }})</span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Batas Waktu</div>
                    <div class="col-md-9 fw-medium text-danger">{{ $claim->deadline->format('d F Y') }}</div>
                </div>
                
                <hr>

                <div class="mb-4">
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Deskripsi Masalah</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->description }}</div>
                </div>
                
                <div class="mb-4">
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Resolusi Diharapkan</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->resolution_expected }}</div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Lampiran Bukti QC</h6>
                    <div class="row g-2">
                        @foreach($claim->inspection->attachments as $att)
                            <div class="col-4 col-md-3 col-lg-2">
                                <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm" style="height: 100px;">
                                    <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100" style="object-fit: cover;">
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Tanggapan Supplier --}}
        @if($claim->status !== 'pending')
        <div class="card border-0 shadow-sm mb-4 border-start border-4 border-info">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Tanggapan Supplier</h6>
            </div>
            <div class="card-body">
                <div class="mb-2 text-muted small">Direspons pada: {{ $claim->updated_at->format('d M Y, H:i') }}</div>
                <div class="p-3 bg-light rounded border mb-3">
                    {{ $claim->supplier_response ?? 'Tidak ada teks tanggapan.' }}
                </div>

                @if($claim->attachments && $claim->attachments->count() > 0)
                    <h6 class="fw-bold small text-uppercase text-muted mb-2">Dokumen/Foto Resolusi</h6>
                    <div class="row g-2">
                        @foreach($claim->attachments as $att)
                            <div class="col-4 col-md-3 col-lg-2">
                                <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded text-center py-3 text-decoration-none shadow-sm h-100 bg-white">
                                    <i class="bi bi-file-earmark-text fs-3 text-primary d-block mb-1"></i>
                                    <span class="small text-truncate d-block px-2">{{ $att->file_name }}</span>
                                </a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
        @endif

    </div>

    <div class="col-lg-4">
        {{-- Action Card --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Aksi Klaim</h6>
            </div>
            <div class="card-body">
                @if($claim->status === 'pending')
                    <div class="alert alert-warning small">
                        <i class="bi bi-hourglass-split me-1"></i> Menunggu respons dari pihak supplier. Deadline: {{ $claim->deadline->format('d M Y') }}
                    </div>
                @elseif($claim->status === 'responded')
                    <div class="alert alert-info small">
                        <i class="bi bi-reply-fill me-1"></i> Supplier telah memberikan tanggapan. Apakah solusi bisa diterima?
                    </div>
                    <form action="{{ route('purchasing.claims.resolve', $claim->id) }}" method="POST">
                        @csrf
                        <button type="submit" class="btn btn-success w-100 mb-2">
                            <i class="bi bi-check2-circle me-1"></i> Tandai Selesai (Resolved)
                        </button>
                    </form>
                    <button type="button" class="btn btn-outline-danger w-100 disabled" title="Fitur Eskalasi belum aktif">
                        <i class="bi bi-exclamation-triangle me-1"></i> Eskalasi
                    </button>
                @elseif($claim->status === 'resolved')
                    <div class="alert alert-success small mb-0">
                        <i class="bi bi-check-circle-fill me-1"></i> Klaim ini telah dinyatakan selesai dan terselesaikan.
                    </div>
                @endif
            </div>
        </div>

        {{-- Referensi QC --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Item Material NG</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <li class="list-group-item small">
                            <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                            @if($item->notes)
                                <span class="text-muted fst-italic">{{ $item->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
                <div class="p-3 text-center border-top">
                    <a href="{{ route('qc.inspections.show', $claim->inspection_id) }}" target="_blank" class="btn btn-sm btn-light w-100">Lihat Detail Laporan QC</a>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
