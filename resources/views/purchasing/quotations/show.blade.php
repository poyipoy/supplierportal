@extends('layouts.app')
@section('title', 'Detail Penawaran — ADASI Portal')
@section('page-title', 'Detail Penawaran')

@section('content')
@php
    $relatedPrBaseUrl = route('purchasing.requirements.show', $quotation->purchaseRequirement->id);
    $relatedPrPath = parse_url($relatedPrBaseUrl, PHP_URL_PATH);
    $returnUrl = request(\App\Support\PurchasingNavigation::RETURN_URL_KEY);
    $returnPath = is_string($returnUrl) ? parse_url($returnUrl, PHP_URL_PATH) : null;
    $relatedPrUrl = (
        $returnPath === $relatedPrPath
        && \App\Support\PurchasingNavigation::isSafeUrl($returnUrl)
    )
        ? $returnUrl
        : route('purchasing.requirements.show', [
            $quotation->purchaseRequirement->id,
            \App\Support\PurchasingNavigation::RETURN_URL_KEY => \App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index'),
        ]);
@endphp

<x-breadcrumb :items="[
    'Dashboard' => route('purchasing.dashboard'),
    'Daftar Penawaran' => route('purchasing.quotations.index'),
    'Detail Penawaran' => '#'
]" />
@php
    $validityMeta = \App\Support\StatusHelper::quotationValidityMeta($quotation->validity_period);
@endphp

<div class="mb-3"><a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index') }}" class="text-decoration-none text-muted small"><i class="bi bi-arrow-left me-1"></i>Kembali ke Daftar Penawaran</a></div>

<div class="row g-4">
    <div class="col-lg-8">
        {{-- Info Penawaran --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Informasi Penawaran</h6>
                <span class="badge {{ $quotation->statusBadgeClass() }} text-uppercase px-3 py-2">{{ $quotation->statusLabel() }}</span>
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
                        <div class="mb-3"><span class="text-muted small d-block">Estimasi Pengiriman</span><span class="fw-medium">{{ $quotation->estimated_delivery ? $quotation->estimated_delivery->format('d F Y') : '-' }}</span></div>
                        <div class="mb-3">
                            <span class="text-muted small d-block">
                                Masa Berlaku Penawaran
                                <i class="bi bi-info-circle ms-1" data-bs-toggle="tooltip" data-bs-title="Penawaran kadaluarsa tidak bisa dibuat PO sebelum supplier mengirim revisi."></i>
                            </span>
                            @if($quotation->validity_period)
                                <span class="fw-medium">{{ $quotation->validity_period->format('d F Y') }}</span>
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'] . ' ms-1', $validityMeta['label'], $validityMeta['description']) !!}
                            @else
                                {!! \App\Support\StatusHelper::badgeWithTooltip($validityMeta['class'], $validityMeta['label'], $validityMeta['description']) !!}
                            @endif
                        </div>
                        <div class="mb-3"><span class="text-muted small d-block">Termin Pembayaran</span><span class="fw-medium">{{ $quotation->payment_terms ?? '-' }}</span></div>
                    </div>
                </div>
                @if($quotation->status === 'revision_requested')
                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-arrow-repeat me-1"></i>
                        Revisi sudah diminta. Supplier perlu mengirim ulang penawaran dengan masa berlaku baru.
                    </div>
                @elseif($quotation->isExpired())
                    <div class="alert alert-danger small mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Masa berlaku penawaran sudah kadaluarsa. Minta supplier mengirim penawaran ulang sebelum membuat PO.
                    </div>
                @endif
                @if($quotation->general_notes)
                    <div class="mt-2 p-3 bg-light rounded small"><i class="bi bi-chat-left-text me-1"></i> {{ $quotation->general_notes }}</div>
                @endif
                @if($quotation->reviewer_notes)
                    <div class="mt-2 p-3 bg-warning bg-opacity-10 border border-warning rounded small">
                        <div class="fw-semibold mb-1"><i class="bi bi-pencil-square me-1"></i> Catatan Review</div>
                        {{ $quotation->reviewer_notes }}
                    </div>
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
                                <th>Qty</th>
                                <th>Berat/Unit (Kg)</th>
                                <th>Total Berat (Kg)</th>
                                <th>Harga/Kg ({{ $quotation->currency }})</th>
                                <th>Amount ({{ $quotation->currency }})</th>
                                <th>Harga/Kg (IDR)</th>
                                <th>Amount (IDR)</th>
                                <th>MTC</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $totalOriginal = 0;
                                $totalIdr = 0;
                                $rateValue = $quotationRate ? (float) $quotationRate->rate_to_idr : null;
                            @endphp
                            @foreach($quotation->items as $idx => $item)
                                @php
                                    $quantity = $item->prItem ? $item->prItem->quantity_value : 1;
                                    $weight = $item->prItem ? (float)$item->prItem->weight_needed : 0;
                                    $totalWeight = $item->prItem ? (float)$item->prItem->total_weight : $weight;
                                    $pricePerKg = (float)$item->price_per_kg;
                                    $amount = (float)$item->amount;
                                    $priceIdr = $rateValue !== null ? $pricePerKg * $rateValue : null;
                                    $amountIdr = $rateValue !== null ? $amount * $rateValue : null;
                                    $totalOriginal += $amount;
                                    $totalIdr += $amountIdr ?? 0;
                                @endphp
                                <tr>
                                    <td class="text-center">{{ $idx + 1 }}</td>
                                    <td>
                                        <div class="fw-medium">{{ $item->prItem->material_name ?? '-' }}</div>
                                        @if($item->prItem && $item->prItem->shape)
                                            <span class="badge bg-light text-dark border" style="font-size:.65rem">{{ $item->prItem->shape }}</span>
                                            <div class="text-muted small">{{ $item->prItem->dimension_label }}</div>
                                        @endif
                                    </td>
                                    <td class="text-center fw-medium">{{ number_format($quantity, 0) }}</td>
                                    <td class="text-center">{{ number_format($weight, 2) }}</td>
                                    <td class="text-center fw-medium text-primary">{{ number_format($totalWeight, 2) }}</td>
                                    <td class="text-end fw-bold">{{ number_format($pricePerKg, 2) }}</td>
                                    <td class="text-end">{{ number_format($amount, 2) }}</td>
                                    <td class="text-end text-primary fw-bold">
                                        {{ $priceIdr !== null ? 'Rp ' . number_format($priceIdr, 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="text-end text-primary">
                                        {{ $amountIdr !== null ? 'Rp ' . number_format($amountIdr, 0, ',', '.') : '-' }}
                                    </td>
                                    <td class="text-center">
                                        @if($item->attachments->isNotEmpty())
                                            @foreach($item->attachments as $attachment)
                                                <a href="{{ route('attachments.show', $attachment->id) }}" class="btn btn-sm btn-outline-primary mb-1" target="_blank" title="{{ $attachment->file_name }}">
                                                    <i class="bi bi-paperclip"></i>
                                                </a>
                                            @endforeach
                                        @else
                                            <span class="text-muted">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-bold">
                                <td colspan="6" class="text-end">Total:</td>
                                <td class="text-end">{{ number_format($totalOriginal, 2) }}</td>
                                <td></td>
                                <td class="text-end text-primary">{{ $rateValue !== null ? 'Rp ' . number_format($totalIdr, 0, ',', '.') : '-' }}</td>
                                <td></td>
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
                <h5 class="fw-bold mb-1">{{ $supplierDisplayName }}</h5>
                <p class="text-muted small mb-2">{{ $quotation->supplier->email }}</p>
                @if($quotation->supplier->supplier)
                    <div class="small text-muted mb-1"><i class="bi bi-geo-alt me-1"></i>{{ $quotation->supplier->supplier->address ?? '-' }}</div>
                    <div class="small text-muted"><i class="bi bi-telephone me-1"></i>{{ $quotation->supplier->supplier->phone ?? '-' }}</div>
                @endif
            </div>
        </div>

        {{-- Negosiasi & Chat --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold"><i class="bi bi-chat-dots me-1"></i> Negosiasi & Chat</h6>
            </div>
            <div class="card-body">
                @if($chatAvailable)
                    <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $quotation->purchaseRequirement->id, 'supplier_id' => $quotation->supplier_id]) }}" method="POST" data-chat-start-form>
                        @csrf
                        <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                        <button type="submit" class="btn btn-primary w-100 text-start d-flex justify-content-between align-items-center gap-2" style="background-color: var(--adasi-blue);">
                            <span class="text-truncate"><i class="bi bi-chat-dots me-2"></i> Chat dengan {{ $supplierDisplayName }}</span>
                            <i class="bi bi-chevron-right flex-shrink-0"></i>
                        </button>
                    </form>
                    <div class="mt-3 text-muted small">
                        Gunakan chat ini untuk klarifikasi harga, lead time, masa berlaku penawaran, atau dokumen pendukung sebelum membuat PO.
                    </div>
                @else
                    <div class="alert alert-secondary small mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Chat tersedia setelah penawaran dikirim supplier atau sudah diterima.
                    </div>
                @endif
            </div>
        </div>

        {{-- Kurs --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-currency-exchange me-1"></i> Kurs Konversi
                    <i class="bi bi-info-circle ms-1 text-muted" data-bs-toggle="tooltip" data-bs-title="Total histori memakai kurs snapshot penawaran ini, bukan kurs terbaru."></i>
                </h6>
            </div>
            <div class="card-body text-center">
                @if($quotationRate)
                    <div class="p-3 bg-light rounded">
                        <div class="text-muted small mb-1">{{ $quotation->currency }} → IDR</div>
                        <h4 class="fw-bold text-primary mb-0">Rp {{ number_format($quotationRate->rate_to_idr, 0, ',', '.') }}</h4>
                        <div class="text-muted mt-1" style="font-size:.7rem">Kurs penawaran: {{ $quotationRate->valid_from->format('d M Y') }}</div>
                        @if($latestRate && $latestRate->id !== $quotationRate->id)
                            <div class="text-muted mt-2 pt-2 border-top" style="font-size:.7rem">
                                Kurs terbaru: Rp {{ number_format($latestRate->rate_to_idr, 0, ',', '.') }}<br>
                                <span>Tidak dipakai untuk total histori.</span>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="alert alert-warning small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Kurs penawaran {{ $quotation->currency }} belum tersedia.</div>
                @endif
            </div>
        </div>

        {{-- Aksi --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3"><h6 class="mb-0 fw-bold">Aksi</h6></div>
            <div class="card-body">
                @if($quotation->status === 'submitted' && $quotation->purchaseOrders->isEmpty() && !$quotation->isExpired())
                    <form action="{{ route('purchasing.quotations.accept', $quotation->id) }}" method="POST" class="mb-2">
                        @csrf
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle me-1"></i> Terima Penawaran
                        </button>
                    </form>

                    <form action="{{ route('purchasing.quotations.request-revision', $quotation->id) }}" method="POST" class="mb-2" id="requestRevisionForm">
                        @csrf
                        <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                        <label for="revisionNote" class="form-label small fw-medium">Catatan Revisi</label>
                        <textarea name="revision_note" id="revisionNote" class="form-control form-control-sm mb-2" rows="3" maxlength="1000" required placeholder="Contoh: Mohon revisi harga, lead time, MTC, atau syarat pembayaran.">{{ old('revision_note') }}</textarea>
                        <button type="submit" class="btn btn-warning w-100 fw-semibold text-dark">
                            <i class="bi bi-arrow-repeat me-1"></i> Minta Revisi
                        </button>
                    </form>

                    <form action="{{ route('purchasing.quotations.reject', $quotation->id) }}" method="POST" class="mb-3">
                        @csrf
                        <label class="form-label small fw-medium">Catatan Penolakan</label>
                        <textarea name="reviewer_notes" class="form-control form-control-sm mb-2" rows="3" maxlength="1000" required placeholder="Wajib diisi jika penawaran ditolak.">{{ old('reviewer_notes') }}</textarea>
                        <button type="submit" class="btn btn-outline-danger w-100">
                            <i class="bi bi-x-circle me-1"></i> Tolak Penawaran
                        </button>
                    </form>
                @endif

                @if($canCreatePo)
                    <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.create', $quotation->id) }}" class="btn btn-primary w-100 mb-2" style="background-color: var(--adasi-blue);">
                        <i class="bi bi-receipt me-1"></i> Buat PO dari Penawaran Ini
                    </a>
                @elseif($quotation->status === 'submitted' && $quotation->isExpired())
                    @if($canRequestRevision)
                        <form action="{{ route('purchasing.quotations.request-revision', $quotation->id) }}" method="POST" class="mb-2" id="requestRevisionForm">
                            @csrf
                            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                            <div class="alert alert-warning small mb-2">
                                <i class="bi bi-clock-history me-1"></i>
                                Masa berlaku sudah lewat. Minta supplier mengirim ulang penawaran sebelum membuat PO.
                            </div>
                            <label for="revisionNote" class="form-label small fw-medium">Catatan Revisi</label>
                            <textarea name="revision_note" id="revisionNote" class="form-control form-control-sm mb-2" rows="3" maxlength="1000" placeholder="Contoh: Mohon update masa berlaku, lead time, dan harga terbaru.">{{ old('revision_note') }}</textarea>
                            <button type="submit" class="btn btn-warning w-100 fw-semibold text-dark">
                                <i class="bi bi-arrow-repeat me-1"></i> Minta Revisi Penawaran
                            </button>
                        </form>
                    @else
                        <button type="button" class="btn btn-outline-danger w-100 mb-2" disabled>
                            <i class="bi bi-lock me-1"></i> Penawaran Kadaluarsa
                        </button>
                    @endif
                @elseif($quotation->status === 'revision_requested')
                    <div class="alert alert-warning small mb-2">
                        <i class="bi bi-hourglass-split me-1"></i>
                        Menunggu supplier mengirim ulang penawaran revisi.
                    </div>
                @elseif($quotation->first_purchase_order)
                    <div class="alert alert-success small mb-2"><i class="bi bi-check-circle me-1"></i>PO sudah dibuat: <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.purchase-orders.show', $quotation->first_purchase_order->id) }}" class="fw-bold">{{ $quotation->first_purchase_order->po_number }}</a></div>
                @endif
                <a href="{{ $relatedPrUrl }}" class="btn btn-outline-secondary w-100 btn-sm">
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
                        <a href="{{ route('attachments.show', $att->id) }}" class="list-group-item list-group-item-action py-2 px-3 small d-flex justify-content-between align-items-center" target="_blank">
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

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('requestRevisionForm');
        if (!form) return;

        form.addEventListener('submit', (event) => {
            event.preventDefault();

            Swal.fire({
                title: @json('Minta Revisi Penawaran?'),
                text: @json('Supplier akan mendapat notifikasi dan penawaran dibuka kembali untuk submit ulang.'),
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: 'var(--adasi-blue)',
                cancelButtonColor: '#6c757d',
                confirmButtonText: @json('Ya, Minta Revisi'),
                cancelButtonText: @json('Batal')
            }).then((result) => {
                if (result.isConfirmed) {
                    form.submit();
                }
            });
        });
    });
</script>
@endpush
