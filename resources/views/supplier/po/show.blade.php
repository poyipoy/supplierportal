@extends('layouts.app')

@section('title', 'Detail PO: ' . $po->po_number . ' — ADASI Portal')
@section('page-title', 'Detail Purchase Order')

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.purchase-orders.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar PO
    </a>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- PO Info --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">{{ $po->po_number }}</h6>
                @php
                    $badgeClass = match(true) {
                        $po->is_overdue => 'bg-danger',
                        $po->status === 'active' => 'bg-primary',
                        $po->status === 'waiting_qc' => 'bg-warning text-dark',
                        $po->status === 'claim_needed' => 'bg-danger',
                        $po->status === 'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                @endphp
                <span class="badge {{ $badgeClass }} text-uppercase px-3 py-2">{{ $po->is_overdue ? 'Overdue' : ucwords(str_replace('_', ' ', $po->status)) }}</span>
            </div>
            <div class="card-body">
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">No. PR</div>
                    <div class="col-md-8 fw-medium">
                        @php $prs = $po->purchaseRequirements(); @endphp
                        @foreach($prs as $pr)
                            <span class="text-primary me-2">{{ $pr->pr_number ?? '-' }}</span>
                        @endforeach
                        @if($prs->count() > 1)
                            <span class="badge bg-primary bg-opacity-10 text-primary ms-1">{{ $prs->count() }} PR</span>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Tanggal Dibuat</div>
                    <div class="col-md-8 fw-medium">{{ $po->created_at->format('d F Y, H:i') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Estimasi Kedatangan</div>
                    <div class="col-md-8 fw-medium">{{ $po->estimated_arrival ? $po->estimated_arrival->format('d F Y') : '-' }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Actual Arrival</div>
                    <div class="col-md-8 fw-medium">
                        @if($po->actual_arrival)
                            <span class="text-success"><i class="bi bi-check-circle-fill me-1"></i>{{ $po->actual_arrival->format('d F Y') }}</span>
                        @else
                            <span class="text-muted">Belum tiba</span>
                        @endif
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-4 text-muted small">Mata Uang</div>
                    <div class="col-md-8 fw-medium">{{ $po->currency }}</div>
                </div>
            </div>
        </div>

        {{-- Material Table --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Detail Material</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                        <thead class="table-light text-center">
                            <tr>
                                <th>No</th>
                                <th>Material</th>
                                <th>Weight (Kg)</th>
                                <th>Harga/Kg</th>
                                <th>Amount</th>
                                <th>IDR</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $totalAmount = 0; $totalIdr = 0; $no = 1; @endphp
                            @foreach($po->quotations as $quotation)
                                @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                                @if($po->quotations->count() > 1)
                                    <tr class="table-primary">
                                        <td colspan="6" class="fw-bold small ps-3">
                                            <i class="bi bi-folder2 me-1"></i>
                                            {{ $quotation->purchaseRequirement->pr_number ?? 'PR -' }}
                                            <span class="text-muted fw-normal ms-2">
                                                @if($rate)
                                                    • Kurs: 1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}
                                                @endif
                                            </span>
                                        </td>
                                    </tr>
                                @endif
                                @foreach($quotation->items as $item)
                                    @php
                                        $idr = $item->amount * ($rate ? $rate->rate_to_idr : 1);
                                        $totalAmount += $item->amount;
                                        $totalIdr += $idr;
                                    @endphp
                                    <tr>
                                        <td class="text-center">{{ $no++ }}</td>
                                        <td class="fw-medium">{{ $item->prItem->material_name }}</td>
                                        <td class="text-center">{{ number_format($item->prItem->weight_needed, 2) }}</td>
                                        <td class="text-end">{{ number_format($item->price_per_kg, 4) }}</td>
                                        <td class="text-end fw-medium">{{ number_format($item->amount, 2) }}</td>
                                        <td class="text-end">Rp {{ number_format($idr, 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                        <tfoot class="table-light fw-bold">
                            <tr>
                                <td colspan="4" class="text-end">TOTAL</td>
                                <td class="text-end">{{ number_format($totalAmount, 2) }}</td>
                                <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        @php
            $pendingClaim = $po->materialClaims
                ->where('status', 'pending')
                ->sortByDesc('created_at')
                ->first();
            $latestClaim = $po->materialClaims
                ->sortByDesc('created_at')
                ->first();
        @endphp

        @if($pendingClaim || $latestClaim)
            <div class="card border-danger shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                    <h6 class="mb-0 fw-bold text-danger">
                        <i class="bi bi-exclamation-octagon me-2"></i>Klaim Material
                    </h6>
                    <span class="badge {{ $pendingClaim ? 'bg-warning text-dark' : 'bg-danger' }}">
                        {{ $pendingClaim ? 'Perlu Respons' : 'Ada Klaim' }}
                    </span>
                </div>
                <div class="card-body">
                    @if($pendingClaim)
                        <p class="small text-muted mb-3">
                            ADASI mengajukan klaim untuk PO ini. Silakan berikan tanggapan dan lampiran pendukung.
                        </p>
                        <a href="{{ route('supplier.claims.show', $pendingClaim->id) }}" class="btn btn-danger w-100 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-reply me-2"></i> Respons Klaim</span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    @else
                        <p class="small text-muted mb-3">
                            PO ini memiliki riwayat klaim material. Buka detail klaim untuk melihat status dan respons.
                        </p>
                        <a href="{{ route('supplier.claims.show', $latestClaim->id) }}" class="btn btn-outline-danger w-100 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-octagon me-2"></i> Lihat Klaim Material</span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    @endif
                </div>
            </div>
        @endif

        {{-- Document Status (read-only for supplier) --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Status Dokumen Impor</h6>
            </div>
            <div class="card-body">
                @php
                    $docLabels = [
                        'invoice' => 'Invoice',
                        'bl' => 'Bill of Lading',
                        'packing_list' => 'Packing List',
                        'form_e' => 'Form-E',
                    ];
                    $statusLabels = [
                        'pending' => 'Belum Ada',
                        'received' => 'Diterima',
                        'verified' => 'Diverifikasi',
                        'issued' => 'Sudah Diterbitkan',
                        'processing' => 'Sedang Diproses',
                        'done' => 'Selesai'
                    ];
                @endphp
                @foreach($po->documents as $doc)
                    @php
                        $statusBadge = match($doc->status) {
                            'pending' => 'bg-secondary',
                            'received', 'issued', 'processing' => 'bg-info',
                            'verified', 'done' => 'bg-success',
                            default => 'bg-secondary'
                        };
                    @endphp
                    <div class="d-flex justify-content-between align-items-center py-2 {{ !$loop->last ? 'border-bottom' : '' }}">
                        <span class="fw-medium">{{ $docLabels[$doc->doc_type] ?? $doc->doc_type }}</span>
                        <span class="badge {{ $statusBadge }}">{{ $statusLabels[$doc->status] ?? $doc->status }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
