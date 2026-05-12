@extends('layouts.app')

@section('title', 'Detail Penawaran — ADASI Portal')
@section('page-title', 'Detail Penawaran')

@section('content')
    <div class="mb-3">
        <a href="{{ route('supplier.quotations.period', $quotation->purchaseRequirement->period_id) }}"
            class="text-decoration-none text-muted small">
            <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Permintaan
        </a>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Detail Harga Material</h6>
                    @php
                        $statusBadge = match($quotation->status) {
                            'accepted' => 'bg-primary',
                            'rejected' => 'bg-dark',
                            'submitted' => 'bg-success',
                            'draft' => 'bg-secondary',
                            default => 'bg-secondary',
                        };
                        $statusLabel = match($quotation->status) {
                            'accepted' => 'Diterima',
                            'rejected' => 'Ditolak',
                            'submitted' => 'Terkirim',
                            'draft' => 'Draft',
                            default => ucwords(str_replace('_', ' ', $quotation->status)),
                        };
                    @endphp
                    <span class="badge {{ $statusBadge }} px-3 py-2 text-uppercase">{{ $statusLabel }}</span>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light text-center">
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="25%">Material</th>
                                    <th width="10%">Berat (Kg)</th>
                                    <th width="15%">Harga ({{ $quotation->currency }})</th>
                                    <th width="15%">Amount ({{ $quotation->currency }})</th>
                                    <th width="15%">Est. IDR</th>
                                    <th width="15%">Catatan</th>
                                </tr>
                            </thead>
                            <tbody>
                                @php
                                    $totalAmount = 0;
                                    $totalIdr = 0;
                                    $rate = $quotation->exchange_rate ? $quotation->exchange_rate->rate_to_idr : 1;
                                @endphp
                                @foreach($quotation->items as $index => $item)
                                    @php
                                        $idr = $item->amount * $rate;
                                        $totalAmount += $item->amount;
                                        $totalIdr += $idr;
                                    @endphp
                                    <tr>
                                        <td class="text-center">{{ $index + 1 }}</td>
                                        <td>
                                            <div class="fw-bold">{{ $item->prItem->material_name }}</div>
                                            <div class="text-muted" style="font-size: 0.75rem;">
                                                {{ $item->prItem->shape }}
                                            </div>
                                        </td>
                                        <td class="text-center fw-medium text-primary">
                                            {{ number_format($item->prItem->weight_needed, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->price_per_kg, 4) }}</td>
                                        <td class="text-end fw-medium">{{ number_format($item->amount, 2) }}</td>
                                        <td class="text-end text-muted">{{ number_format($idr, 0, ',', '.') }}</td>
                                        <td>{{ $item->notes ?? '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="table-light fw-bold">
                                <tr>
                                    <td colspan="4" class="text-end">TOTAL</td>
                                    <td class="text-end">{{ number_format($totalAmount, 2) }}</td>
                                    <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3">
                    <h6 class="mb-0 fw-bold">Informasi Penawaran</h6>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Waktu Submit</div>
                        <div class="col-7 fw-medium">
                            {{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}</div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Kurs Dipakai</div>
                        <div class="col-7 fw-medium">
                            @if($quotation->exchange_rate)
                                1 {{ $quotation->currency }} = Rp
                                {{ number_format($quotation->exchange_rate->rate_to_idr, 0, ',', '.') }}
                            @else
                                -
                            @endif
                        </div>
                    </div>
                    <hr>
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Est. Pengiriman</div>
                        <div class="col-7 fw-medium">
                            {{ $quotation->estimated_delivery ? \Carbon\Carbon::parse($quotation->estimated_delivery)->format('d F Y') : '-' }}
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-5 text-muted small">Masa Berlaku</div>
                        <div class="col-7 fw-medium">
                            {{ $quotation->validity_period ? \Carbon\Carbon::parse($quotation->validity_period)->format('d F Y') : '-' }}
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-12 text-muted small mb-1">Syarat Pembayaran</div>
                        <div class="col-12 fw-medium p-2 bg-light rounded">
                            {{ $quotation->payment_terms ?: 'Tidak ada syarat khusus' }}</div>
                    </div>
                    <div class="row">
                        <div class="col-12 text-muted small mb-1">Catatan Umum</div>
                        <div class="col-12 fw-medium p-2 bg-light rounded">
                            {{ $quotation->general_notes ?: 'Tidak ada catatan' }}</div>
                    </div>
                </div>
            </div>

            @if($quotation->status === 'rejected')
                <div class="alert alert-dark small">
                    <i class="bi bi-x-circle-fill me-1"></i> Penawaran ini tidak dipilih oleh tim Purchasing ADASI.
                </div>
            @elseif($quotation->status === 'accepted')
                <div class="alert alert-success small">
                    <i class="bi bi-check-circle-fill me-1"></i> Penawaran ini dipilih oleh tim Purchasing ADASI.
                </div>
            @else
                <div class="alert alert-info small">
                    <i class="bi bi-info-circle-fill me-1"></i> Penawaran Anda sudah terekam dan sedang menunggu evaluasi dari
                    tim Purchasing ADASI.
                </div>
            @endif
        </div>
    </div>
@endsection
