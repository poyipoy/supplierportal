@extends('layouts.app')

@section('title', 'Buat Klaim Material — ADASI Portal')
@section('page-title', 'Form Pengajuan Klaim')

@section('content')
<div class="mb-3">
    <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.claims.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Klaim
    </a>
</div>

<form action="{{ route('purchasing.claims.store') }}" method="POST" id="claimForm">
    @csrf
    <input type="hidden" name="return_url" value="{{ request('return_url') }}">
    <input type="hidden" name="inspection_id" value="{{ $inspection->id }}">

    <div class="row g-4">
        <div class="col-lg-7">
            {{-- Form Klaim --}}
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Detail Klaim</h6>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label fw-medium">Deskripsi Masalah <span class="text-danger">*</span></label>
                        <textarea name="description" class="form-control @error('description') is-invalid @enderror" rows="4" required placeholder="Jelaskan secara detail material mana yang bermasalah dan mengapa..."></textarea>
                        @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-medium">Resolusi yang Diharapkan <span class="text-danger">*</span></label>
                        <textarea name="resolution_expected" class="form-control @error('resolution_expected') is-invalid @enderror" rows="3" required placeholder="Contoh: Penggantian barang, refund, dll."></textarea>
                        @error('resolution_expected') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-medium">Batas Waktu Respons (Deadline) <span class="text-danger">*</span></label>
                        <input type="date" name="deadline" class="form-control @error('deadline') is-invalid @enderror" min="{{ date('Y-m-d', strtotime('+1 day')) }}" required>
                        <div class="form-text small text-muted">Beri waktu wajar untuk supplier merespons klaim ini.</div>
                        @error('deadline') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mb-5">
                <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.claims.index') }}" class="btn btn-light">Batal</a>
                <button type="submit" class="btn btn-danger">
                    <i class="bi bi-send me-1"></i> Kirim Klaim ke Supplier
                </button>
            </div>
        </div>

        <div class="col-lg-5">
            {{-- Info Referensi QC --}}
            <div class="card border-0 shadow-sm mb-4 bg-light">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Referensi Inspeksi QC</h6>
                    <a href="{{ route('qc.inspections.show', $inspection->id) }}" target="_blank" class="btn btn-sm btn-outline-info">Detail QC</a>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <div class="text-muted small">Nomor PO</div>
                        <div class="fw-bold">{{ $inspection->purchaseOrder->po_number }}</div>
                    </div>
                    <div class="mb-2">
                        <div class="text-muted small">Supplier</div>
                        <div class="fw-medium">{{ $inspection->purchaseOrder->supplier->name }}</div>
                    </div>
                    <div class="mb-3">
                        <div class="text-muted small">Tgl Inspeksi</div>
                        <div class="fw-medium">{{ $inspection->inspected_at->format('d M Y') }}</div>
                    </div>

                    <h6 class="fw-bold small text-danger text-uppercase mb-2">Item NG (Not Good)</h6>
                    <ul class="list-group list-group-flush border rounded mb-3">
                        @foreach($inspection->items->where('status', 'ng') as $item)
                            <li class="list-group-item bg-transparent py-2 px-3 small">
                                <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                                @if($item->notes)
                                    <span class="text-muted fst-italic">Catatan QC: {{ $item->notes }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    @if($inspection->attachments->count() > 0)
                        <h6 class="fw-bold small text-muted text-uppercase mb-2">Foto Bukti dari QC</h6>
                        <div class="row g-2">
                            @foreach($inspection->attachments as $att)
                                <div class="col-4">
                                    <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm" style="height: 80px;">
                                        <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100" style="object-fit: cover;">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                        <div class="form-text small mt-2">Foto-foto ini akan otomatis dilampirkan ke halaman supplier.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
