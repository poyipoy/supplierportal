@extends('layouts.app')

@section('title', 'Detail Klaim #' . $claim->id . ' — ADASI Portal')
@section('page-title', 'Klaim Material PO: ' . $claim->purchaseOrder->po_number)

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.claims.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Klaim
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Info Tuntutan Klaim --}}
        <div class="card border-0 shadow-sm mb-4 border-top border-4 border-danger">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="fw-bold mb-0 text-danger"><i class="bi bi-exclamation-octagon me-2"></i>Tuntutan Klaim</h5>
                    @php
                        $badgeClass = match($claim->status) {
                            'pending' => 'bg-warning text-dark',
                            'responded' => 'bg-info',
                            'resolved' => 'bg-success',
                            'escalated' => 'bg-danger',
                            default => 'bg-secondary'
                        };
                    @endphp
                    <span class="badge {{ $badgeClass }} text-uppercase fs-6 px-3 py-2">{{ $claim->status }}</span>
                </div>
                
                <div class="row mb-3">
                    <div class="col-md-3 text-muted small">Tgl Pengajuan</div>
                    <div class="col-md-9 fw-medium">{{ $claim->created_at->format('d F Y') }}</div>
                </div>
                <div class="row mb-4">
                    <div class="col-md-3 text-muted small">Deadline Respons</div>
                    <div class="col-md-9 fw-bold text-danger">{{ $claim->deadline->format('d F Y') }}</div>
                </div>

                <div class="mb-3">
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Deskripsi Masalah (Berdasarkan Laporan QC)</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->description }}</div>
                </div>
                
                <div class="mb-4">
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Resolusi yang Diharapkan ADASI</h6>
                    <div class="p-3 bg-light rounded border">{{ $claim->resolution_expected }}</div>
                </div>

                @if($claim->inspection->attachments->count() > 0)
                    <h6 class="fw-bold small text-muted text-uppercase mb-2">Bukti Foto dari QC ADASI</h6>
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

        {{-- Form Respons Supplier --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Respons Supplier</h6>
            </div>
            <div class="card-body">
                @if($claim->status === 'pending')
                    <form action="{{ route('supplier.claims.respond', $claim->id) }}" method="POST" enctype="multipart/form-data" id="respondForm">
                        @csrf
                        <div class="mb-3">
                            <label class="form-label fw-medium">Tanggapan & Penjelasan <span class="text-danger">*</span></label>
                            <textarea name="supplier_response" class="form-control @error('supplier_response') is-invalid @enderror" rows="5" required placeholder="Tuliskan tanggapan atau kesepakatan resolusi dari pihak Anda..."></textarea>
                            @error('supplier_response') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        </div>

                        <div class="mb-4">
                            <label class="form-label fw-medium">Lampiran Dokumen/Foto Pendukung (Opsional)</label>
                            <input type="file" name="attachments[]" class="form-control" multiple accept=".jpg,.jpeg,.png,.pdf,.doc,.docx,.xls,.xlsx">
                            <div class="form-text small">Upload surat resmi, bukti transfer refund, atau resi pengiriman pengganti (Maks 10MB/file).</div>
                            @error('attachments.*') <div class="text-danger small mt-1">{{ $message }}</div> @enderror
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" id="btnSubmitRespond">
                                <i class="bi bi-send me-1"></i> Kirim Respons
                            </button>
                        </div>
                    </form>
                @else
                    <div class="mb-2 text-muted small">Anda telah merespons klaim ini pada: {{ $claim->updated_at->format('d M Y, H:i') }}</div>
                    <div class="p-3 bg-light rounded border mb-3">
                        {{ $claim->supplier_response }}
                    </div>

                    @if($claim->attachments && $claim->attachments->count() > 0)
                        <h6 class="fw-bold small text-uppercase text-muted mb-2">Dokumen/Foto yang Dilampirkan</h6>
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
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Item Material NG --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Daftar Material Bermasalah</h6>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush">
                    @foreach($claim->inspection->items->where('status', 'ng') as $item)
                        <li class="list-group-item">
                            <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                            @if($item->notes)
                                <span class="text-muted small fst-italic">Catatan QC: {{ $item->notes }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        <div class="alert alert-light border small text-muted">
            <i class="bi bi-info-circle me-1"></i> Jika Anda membutuhkan detail spesifikasi aktual vs spesifikasi yang diminta, Anda dapat mengeceknya dengan Purchasing ADASI terkait.
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('#btnSubmitRespond').on('click', function() {
        const form = $('#respondForm')[0];
        if (!form.checkValidity()) {
            form.reportValidity();
            return;
        }

        Swal.fire({
            title: 'Kirim Respons Klaim?',
            text: "Pastikan tanggapan dan resolusi yang Anda tawarkan sudah sesuai. Respons tidak dapat diubah setelah dikirim.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Kirim!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
