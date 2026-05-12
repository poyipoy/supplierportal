@extends('layouts.app')
@section('title', 'Detail Penawaran — ADASI Portal')
@section('page-title', 'Detail Penawaran')

@section('content')
<div class="mb-3"><a href="{{ route('purchasing.quotations.index') }}" class="text-decoration-none text-muted small"><i class="bi bi-arrow-left me-1"></i>Kembali ke Daftar Penawaran</a></div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Info Penawaran --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Informasi Penawaran</h6>
                @php
                    $sc = match($quotation->status) {
                        'submitted' => 'bg-primary',
                        'accepted' => 'bg-success',
                        'rejected' => 'bg-danger',
                        default => 'bg-secondary'
                    };
                @endphp
                <span class="badge {{ $sc }} text-uppercase px-3 py-2">{{ $quotation->status }}</span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="mb-3"><span class="text-muted small d-block">No. PR</span><span class="fw-bold text-primary">{{ $quotation->purchaseRequirement->pr_number ?? '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Periode</span><span class="fw-medium">{{ $quotation->purchaseRequirement->period->name ?? '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Mata Uang</span><span class="badge bg-dark">{{ $quotation->currency }}</span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3"><span class="text-muted small d-block">Tanggal Diajukan</span><span class="fw-medium">{{ $quotation->submitted_at ? $quotation->submitted_at->format('d F Y, H:i') : '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Estimasi Pengiriman</span><span class="fw-medium">{{ $quotation->estimated_delivery ?? '-' }}</span></div>
                        <div class="mb-3"><span class="text-muted small d-block">Termin Pembayaran</span><span class="fw-medium">{{ $quotation->payment_terms ?? '-' }}</span></div>
                    </div>
                </div>
                @if($quotation->general_notes)
                    <div class="mt-2 p-3 bg-light rounded small"><i class="bi bi-chat-left-text me-1"></i> {{ $quotation->general_notes }}</div>
                @endif
            </div>
        </div>

        {{-- Tabel Item + Harga --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Detail Harga Material ({{ $quotation->items->count() }} Item)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0" style="font-size:.85rem">
                        <thead class="table-light text-center">
                            <tr>
                                <th>No</th>
                                <th class="text-start">Material</th>
                                <th>Berat (Kg)</th>
                                <th>Harga/Kg ({{ $quotation->currency }})</th>
                                <th>Amount ({{ $quotation->currency }})</th>
                                <th>Harga/Kg (IDR)</th>
                                <th>Amount (IDR)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $totalOriginal = 0; $totalIdr = 0; @endphp
                            @foreach($quotation->items as $idx => $item)
                                @php
                                    $weight = $item->prItem ? (float)$item->prItem->weight_needed : 0;
                                    $pricePerKg = (float)$item->price_per_kg;
                                    $amount = (float)$item->amount;
                                    $rateValue = $latestRate ? (float)$latestRate->rate_to_idr : 0;
                                    $priceIdr = $pricePerKg * $rateValue;
                                    $amountIdr = $amount * $rateValue;
                                    $totalOriginal += $amount;
                                    $totalIdr += $amountIdr;
                                @endphp
                                <tr>
                                    <td class="text-center">{{ $idx + 1 }}</td>
                                    <td>
                                        <div class="fw-medium">{{ $item->prItem->material_name ?? '-' }}</div>
                                        @if($item->prItem && $item->prItem->shape)<span class="badge bg-light text-dark border" style="font-size:.65rem">{{ $item->prItem->shape }}</span>@endif
                                    </td>
                                    <td class="text-center">{{ number_format($weight, 2) }}</td>
                                    <td class="text-end fw-bold">{{ number_format($pricePerKg, 2) }}</td>
                                    <td class="text-end">{{ number_format($amount, 2) }}</td>
                                    <td class="text-end text-primary fw-bold">Rp {{ number_format($priceIdr, 0, ',', '.') }}</td>
                                    <td class="text-end text-primary">Rp {{ number_format($amountIdr, 0, ',', '.') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="4" class="text-end">Total:</td>
                                <td class="text-end">{{ number_format($totalOriginal, 2) }}</td>
                                <td></td>
                                <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Info Supplier --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-building me-1"></i> Supplier</h6></div>
            <div class="card-body">
                <h5 class="fw-bold mb-1">{{ $quotation->supplier->name }}</h5>
                <p class="text-muted small mb-2">{{ $quotation->supplier->email }}</p>
                @if($quotation->supplier->supplier)
                    <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>{{ $quotation->supplier->supplier->address ?? '-' }}</div>
                    <div class="small text-muted"><i class="bi bi-telephone me-1"></i>{{ $quotation->supplier->supplier->phone ?? '-' }}</div>
                @endif
            </div>
        </div>

        {{-- Kurs --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-currency-exchange me-1"></i> Kurs Konversi</h6></div>
            <div class="card-body text-center">
                @if($latestRate)
                    <div class="p-3 bg-light rounded">
                        <div class="text-muted small mb-1">{{ $quotation->currency }} → IDR</div>
                        <h4 class="fw-bold text-primary mb-0">Rp {{ number_format($latestRate->rate_to_idr, 0, ',', '.') }}</h4>
                        <div class="text-muted mt-1" style="font-size:.7rem">Berlaku: {{ $latestRate->valid_from->format('d M Y') }}</div>
                    </div>
                @else
                    <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Kurs {{ $quotation->currency }} belum tersedia.</div>
                @endif
            </div>
        </div>

        {{-- Aksi --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Aksi</h6></div>
            <div class="card-body">
                @if($canCreatePo)
                    <a href="{{ route('purchasing.purchase-orders.create', $quotation->id) }}" class="btn btn-primary w-100 mb-2" style="background-color: var(--adasi-blue);">
                        <i class="bi bi-receipt me-1"></i> Buat PO dari Penawaran Ini
                    </a>
                @elseif($quotation->purchaseOrder)
                    <div class="alert alert-success small mb-2"><i class="bi bi-check-circle me-1"></i>PO sudah dibuat: <a href="{{ route('purchasing.purchase-orders.show', $quotation->purchaseOrder->id) }}" class="fw-bold">{{ $quotation->purchaseOrder->po_number }}</a></div>
                @endif
                <a href="{{ route('purchasing.requirements.show', $quotation->purchaseRequirement->id) }}" class="btn btn-outline-secondary w-100 btn-sm">
                    <i class="bi bi-clipboard-data me-1"></i> Lihat PR Terkait
                </a>
            </div>
        </div>

        {{-- Attachment --}}
        @if($quotation->attachments->count() > 0)
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold"><i class="bi bi-paperclip me-1"></i> Lampiran ({{ $quotation->attachments->count() }})</h6></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    @foreach($quotation->attachments as $att)
                        <a href="{{ route('file.download', $att->id) }}" class="list-group-item list-group-item-action py-2 px-3 small d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-file-earmark me-2"></i>{{ $att->file_name }}</span>
                            <i class="bi bi-download text-muted"></i>
                        </a>
                    @endforeach
                </div>
            </div>
        </div>
        @endif
    </div>
</div>
@endsection
