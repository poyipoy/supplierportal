@extends('layouts.app')

@section('title', 'Detail PO: ' . $po->po_number . ' — ADASI Portal')
@section('page-title', 'Detail Purchase Order')

@section('content')
<div class="mb-3">
    <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.purchase-orders.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar PO
    </a>
</div>

{{-- ═══════════ SECTION A — Info PO ═══════════ --}}
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">{{ $po->po_number }}</h6>
                @php
                    $badgeClass = match(true) {
                        $po->is_overdue => 'bg-danger',
                        $po->status === 'active' => 'bg-primary',
                        $po->status === 'waiting_qc' => 'bg-warning text-dark',
                        $po->status === 'claim_needed' => 'bg-danger',
                        $po->status === 'completed' => 'bg-success',
                        $po->status === 'cancelled' => 'bg-danger',
                        default => 'bg-secondary'
                    };
                @endphp
                <div>
                    <span class="badge {{ $badgeClass }} text-uppercase px-3 py-2 me-2">{{ $po->is_overdue ? 'Overdue' : str_replace('_', ' ', $po->status) }}</span>
                    <a href="{{ route('purchasing.pdf.purchase-order', $po->id) }}" class="btn btn-sm btn-outline-danger" target="_blank" title="Cetak Purchase Order">
                        <i class="bi bi-file-earmark-pdf"></i> Cetak PDF
                    </a>
                </div>
            </div>
            <div class="card-body">
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Supplier</div>
                    <div class="col-md-8 fw-medium">{{ $po->supplier->name }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">No. PR</div>
                    <div class="col-md-8 fw-medium">
                        @php $prs = $po->purchaseRequirements(); @endphp
                        @foreach($prs as $pr)
                            <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requirements.show', $pr->id) }}" class="text-primary text-decoration-none me-2">
                                {{ $pr->pr_number ?? '-' }}
                                <i class="bi bi-box-arrow-up-right ms-1" style="font-size: .7rem;"></i>
                            </a>
                        @endforeach
                        @if($prs->count() > 1)
                            <span class="badge bg-primary bg-opacity-10 text-primary ms-1">{{ $prs->count() }} PR digabung</span>
                        @endif
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Periode</div>
                    <div class="col-md-8 fw-medium">
                        @php $periods = $prs->map(fn($pr) => $pr->period->name ?? '-')->unique(); @endphp
                        {{ $periods->implode(', ') }}
                    </div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Tanggal Dibuat</div>
                    <div class="col-md-8 fw-medium">{{ $po->created_at->format('d F Y, H:i') }}</div>
                </div>
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Est. Kedatangan</div>
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
                <div class="row mb-2">
                    <div class="col-md-4 text-muted small">Mata Uang</div>
                    <div class="col-md-8 fw-medium">{{ $po->currency }}</div>
                </div>
                @if($po->notes)
                <div class="row">
                    <div class="col-md-4 text-muted small">Catatan</div>
                    <div class="col-md-8">{{ $po->notes }}</div>
                </div>
                @endif
            </div>
        </div>

        {{-- Material Table — Grouped per Quotation/PR --}}
        <div class="card border-0 shadow-sm mt-4">
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
                                <th>Spesifikasi</th>
                                <th>Berat (Kg)</th>
                                <th>Harga/Kg</th>
                                <th>Amount</th>
                                <th>IDR</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php $globalNo = 1; $grandTotalAmount = 0; $grandTotalIdr = 0; @endphp
                            @foreach($po->quotations as $quotation)
                                @php $rate = $quotationRates[$quotation->id] ?? null; @endphp
                                @if($po->quotations->count() > 1)
                                    <tr class="table-primary">
                                        <td colspan="7" class="fw-bold small ps-3">
                                            <i class="bi bi-folder2 me-1"></i>
                                            {{ $quotation->purchaseRequirement->pr_number ?? 'PR -' }}
                                            <span class="text-muted fw-normal ms-2">
                                                ({{ $quotation->purchaseRequirement->period->name ?? '-' }})
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
                                        $grandTotalAmount += $item->amount;
                                        $grandTotalIdr += $idr;
                                    @endphp
                                    <tr>
                                        <td class="text-center">{{ $globalNo++ }}</td>
                                        <td class="fw-medium">{{ $item->prItem->material_name }}</td>
                                        <td class="text-center" style="font-size: 0.8rem;">
                                            @if($item->prItem->shape)
                                                <span class="badge bg-light text-dark border">{{ $item->prItem->shape }}</span><br>
                                                <span class="text-muted">{{ $item->prItem->dimension_label }}</span>
                                            @else
                                                -
                                            @endif
                                        </td>
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
                                <td colspan="5" class="text-end">GRAND TOTAL</td>
                                <td class="text-end">{{ number_format($grandTotalAmount, 2) }}</td>
                                <td class="text-end text-primary">Rp {{ number_format($grandTotalIdr, 0, ',', '.') }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        @php
            $latestInspection = $po->qcInspections->sortByDesc('inspected_at')->first();
            $latestNgInspection = $po->qcInspections
                ->where('status', 'ng')
                ->sortByDesc('inspected_at')
                ->first();
            $activeClaim = $po->materialClaims
                ->whereIn('status', ['pending', 'responded', 'escalated'])
                ->sortByDesc('created_at')
                ->first();
        @endphp

        @if($latestInspection)
            <div class="card border-0 shadow-sm mt-4">
                <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-bold">Laporan Inspeksi QC</h6>
                    <span class="badge {{ $latestInspection->status === 'ng' ? 'bg-danger' : 'bg-success' }} text-uppercase px-3 py-2">
                        {{ $latestInspection->status }}
                    </span>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="text-muted small">Tanggal Inspeksi</div>
                            <div class="fw-medium">{{ $latestInspection->inspected_at?->format('d M Y, H:i') ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Diinspeksi Oleh</div>
                            <div class="fw-medium">{{ $latestInspection->inspector->name ?? '-' }}</div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-muted small">Item NG</div>
                            <div class="fw-medium text-danger">{{ $latestInspection->items->where('status', 'ng')->count() }} item</div>
                        </div>
                    </div>

                    @if($latestInspection->items->where('status', 'ng')->count() > 0)
                        <h6 class="fw-bold small text-danger text-uppercase mb-2">Material Bermasalah</h6>
                        <ul class="list-group list-group-flush border rounded mb-3">
                            @foreach($latestInspection->items->where('status', 'ng') as $item)
                                <li class="list-group-item py-2 px-3 small">
                                    <span class="fw-bold d-block">{{ $item->prItem->material_name }}</span>
                                    @if($item->notes)
                                        <span class="text-muted fst-italic">Catatan QC: {{ $item->notes }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif

                    @if($latestInspection->attachments->count() > 0)
                        <h6 class="fw-bold small text-muted text-uppercase mb-2">Foto Bukti QC</h6>
                        <div class="row g-2">
                            @foreach($latestInspection->attachments as $att)
                                <div class="col-6 col-md-4 col-lg-3">
                                    <a href="{{ route('attachments.show', $att->id) }}" target="_blank" class="d-block border rounded overflow-hidden shadow-sm bg-light" style="height: 120px;">
                                        <img src="{{ route('attachments.show', $att->id) }}" alt="{{ $att->file_name }}" class="w-100 h-100" style="object-fit: cover;">
                                    </a>
                                </div>
                            @endforeach
                        </div>
                    @elseif($latestInspection->status === 'ng')
                        <div class="alert alert-warning small mb-0">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Inspeksi berstatus NG, tetapi foto bukti QC belum tersedia.
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    {{-- ═══════════ SECTION C — Timeline ═══════════ --}}
    <div class="col-lg-4">
        {{-- Chat & Action Card --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Negosiasi & Chat</h6>
            </div>
            <div class="card-body">
                <form action="{{ route('purchasing.conversations.start.po', $po->id) }}" method="POST" data-chat-start-form>
                    @csrf
                    <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                    <button type="submit" class="btn btn-primary w-100 text-start d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-chat-dots me-2"></i> Chat dengan Supplier</span>
                        <i class="bi bi-chevron-right"></i>
                    </button>
                </form>
                <div class="mt-3 text-muted small text-center">
                    Gunakan fitur ini untuk komunikasi terkait pengiriman atau komplain PO ini.
                </div>
            </div>
        </div>

        @if($po->status === 'claim_needed')
            <div class="card border-danger shadow-sm mb-4">
                <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
                    <h6 class="mb-0 fw-bold text-danger">
                        <i class="bi bi-exclamation-octagon me-2"></i>Klaim Material
                    </h6>
                    <span class="badge bg-danger">NG</span>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        PO ini membutuhkan tindak lanjut klaim karena hasil inspeksi QC berstatus NG.
                    </p>

                    @if($activeClaim)
                        <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.claims.show', $activeClaim->id) }}" class="btn btn-danger w-100 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-exclamation-octagon me-2"></i> Lihat Klaim Material</span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    @elseif($latestNgInspection)
                        <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.claims.create', $latestNgInspection->id) }}" class="btn btn-danger w-100 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-plus-circle me-2"></i> Ajukan Klaim Material</span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    @else
                        <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.claims.index') }}" class="btn btn-outline-danger w-100 d-flex justify-content-between align-items-center">
                            <span><i class="bi bi-folder2-open me-2"></i> Buka Menu Klaim</span>
                            <i class="bi bi-chevron-right"></i>
                        </a>
                    @endif
                </div>
            </div>
        @endif

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Timeline</h6>
            </div>
            <div class="card-body p-4">
                <div class="position-relative">
                    <div class="position-absolute h-100 border-start" style="left: 10px; top: 0; border-color: #dee2e6 !important;"></div>

                    {{-- PO Created --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute bg-primary rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 text-primary fw-bold" style="font-size: 0.85rem;">PO Dibuat</h6>
                        <div class="small text-muted">{{ $po->created_at->format('d M Y, H:i') }}</div>
                    </div>

                    {{-- Document Updates --}}
                    @foreach($po->documents->sortBy('updated_at') as $doc)
                        @if($doc->status !== 'pending')
                        <div class="position-relative mb-4 ps-4">
                            <div class="position-absolute bg-info rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                            <h6 class="mb-1 text-info fw-bold" style="font-size: 0.85rem;">
                                @php
                                    $docLabels = [
                                        'invoice' => 'Invoice',
                                        'bl' => 'Bill of Lading',
                                        'packing_list' => 'Packing List',
                                        'form_e' => 'Form-E',
                                    ];
                                @endphp
                                {{ $docLabels[$doc->doc_type] ?? $doc->doc_type }}: {{ ucfirst($doc->status) }}
                            </h6>
                            <div class="small text-muted">{{ $doc->updated_at->format('d M Y, H:i') }}</div>
                        </div>
                        @endif
                    @endforeach

                    {{-- Est. Arrival --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute {{ $po->estimated_arrival && $po->estimated_arrival->isPast() ? 'bg-warning' : 'bg-secondary' }} rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 {{ $po->estimated_arrival && $po->estimated_arrival->isPast() ? 'text-warning fw-bold' : 'text-muted' }}" style="font-size: 0.85rem;">Estimasi Kedatangan</h6>
                        <div class="small text-muted">{{ $po->estimated_arrival ? $po->estimated_arrival->format('d M Y') : '-' }}</div>
                    </div>

                    {{-- Actual Arrival --}}
                    <div class="position-relative ps-4">
                        <div class="position-absolute {{ $po->actual_arrival ? 'bg-success' : 'bg-light border' }} rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 {{ $po->actual_arrival ? 'text-success fw-bold' : 'text-muted' }}" style="font-size: 0.85rem;">Material Tiba</h6>
                        @if($po->actual_arrival)
                            <div class="small text-muted">{{ $po->actual_arrival->format('d M Y') }}</div>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Confirm Arrival --}}
        @if(in_array($po->status, ['active', 'overdue']) && !$po->actual_arrival)
            <form action="{{ route('purchasing.purchase-orders.confirm-arrival', $po->id) }}" method="POST" id="arrivalForm">
                @csrf
                <button type="button" class="btn btn-success w-100 mb-3 py-2 fw-semibold shadow-sm" id="btnConfirmArrival">               <i class="bi bi-box-seam me-1"></i> Konfirmasi Material Tiba
                </button>
            </form>
        @endif
    </div>
</div>

{{-- ═══════════ SECTION B — Tracking Dokumen Impor ═══════════ --}}
<div class="card border-0 shadow-sm mb-5">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Tracking Dokumen Impor</h6>
        <span class="badge bg-light text-dark border px-3 py-2" id="docProgressBadge">{{ $completedDocs }}/{{ $totalDocs }} Dokumen Selesai</span>
    </div>
    <div class="card-body">
        {{-- Progress Bar --}}
        <div class="progress mb-4" style="height: 8px;">
            <div class="progress-bar bg-success" role="progressbar" id="docProgressBar"
                 style="width: {{ $totalDocs > 0 ? ($completedDocs/$totalDocs*100) : 0 }}%"></div>
        </div>

        @if($allDocsComplete)
            <div class="alert alert-success d-flex align-items-center mb-4" id="allDocsAlert">
                <i class="bi bi-check-circle-fill me-2 fs-5"></i>
                <span class="fw-medium">Semua dokumen impor lengkap ✅</span>
            </div>
        @endif

        <div class="row g-3">
            @php
                $docConfig = [
                    'invoice' => ['label' => 'Invoice', 'icon' => 'bi-receipt', 'statuses' => ['pending' => 'Belum Ada', 'received' => 'Diterima', 'verified' => 'Diverifikasi']],
                    'bl' => ['label' => 'Bill of Lading', 'icon' => 'bi-truck', 'statuses' => ['pending' => 'Belum Ada', 'issued' => 'Sudah Diterbitkan', 'done' => 'Diterima']],
                    'packing_list' => ['label' => 'Packing List', 'icon' => 'bi-list-check', 'statuses' => ['pending' => 'Belum Ada', 'received' => 'Diterima', 'verified' => 'Diverifikasi']],
                    'form_e' => ['label' => 'Form-E', 'icon' => 'bi-file-earmark-text', 'statuses' => ['pending' => 'Belum Ada', 'processing' => 'Sedang Diproses', 'done' => 'Selesai']],
                ];
            @endphp

            @foreach($po->documents as $doc)
                @php
                    $config = $docConfig[$doc->doc_type] ?? ['label' => $doc->doc_type, 'icon' => 'bi-file', 'statuses' => []];
                    $statusLabel = $config['statuses'][$doc->status] ?? $doc->status;
                    $statusBadge = match($doc->status) {
                        'pending' => 'bg-secondary',
                        'received', 'issued', 'processing' => 'bg-info',
                        'verified', 'done' => 'bg-success',
                        default => 'bg-secondary'
                    };
                @endphp
                <div class="col-md-6 col-lg-3">
                    <div class="card border h-100" id="doc-card-{{ $doc->id }}">
                        <div class="card-body text-center py-4">
                            <i class="bi {{ $config['icon'] }} fs-2 mb-2 d-block {{ $doc->status === 'pending' ? 'text-muted' : 'text-primary' }}"></i>
                            <h6 class="fw-bold mb-2">{{ $config['label'] }}</h6>
                            <span class="badge {{ $statusBadge }} mb-2 doc-status-badge" id="doc-badge-{{ $doc->id }}" data-status="{{ $doc->status }}">{{ $statusLabel }}</span>
                            <div class="small text-muted mb-3" id="doc-date-{{ $doc->id }}">
                                {{ $doc->status !== 'pending' ? $doc->updated_at->format('d M Y, H:i') : '' }}
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary btn-update-doc"
                                    data-doc-id="{{ $doc->id }}"
                                    data-doc-type="{{ $doc->doc_type }}"
                                    data-doc-label="{{ $config['label'] }}"
                                    data-doc-status="{{ $doc->status }}"
                                    data-doc-statuses='@json($config['statuses'])'>
                                <i class="bi bi-pencil-square me-1"></i> Update Status
                            </button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Update Document Modal --}}
<div class="modal fade" id="updateDocModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title fw-bold" id="modalDocTitle">Update Status Dokumen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="modalDocId">
                <div class="mb-3">
                    <label class="form-label fw-medium">Status Baru</label>
                    <select class="form-select" id="modalDocStatus"></select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" id="btnSaveDocStatus">
                    <span class="spinner-border spinner-border-sm d-none me-1" id="docSpinner"></span>
                    Simpan
                </button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    // Open modal
    $('.btn-update-doc').on('click', function() {
        const docId = $(this).data('doc-id');
        const docLabel = $(this).data('doc-label');
        const currentStatus = $(this).data('doc-status');
        const statuses = $(this).data('doc-statuses');

        $('#modalDocId').val(docId);
        $('#modalDocTitle').text('Update: ' + docLabel);

        const select = $('#modalDocStatus');
        select.empty();
        for (const [key, label] of Object.entries(statuses)) {
            select.append(`<option value="${key}" ${key === currentStatus ? 'selected' : ''}>${label}</option>`);
        }

        const modal = new bootstrap.Modal(document.getElementById('updateDocModal'));
        modal.show();
    });

    // Save via AJAX
    $('#btnSaveDocStatus').on('click', function() {
        const docId = $('#modalDocId').val();
        const newStatus = $('#modalDocStatus').val();

        $('#docSpinner').removeClass('d-none');
        $(this).prop('disabled', true);

        $.ajax({
            url: '/purchasing/po-documents/' + docId,
            method: 'PUT',
            data: {
                _token: '{{ csrf_token() }}',
                status: newStatus
            },
            success: function(res) {
                if (res.success) {
                    const statusLabels = {
                        'pending': @json('Belum Ada'), 'received': @json('Diterima'), 'verified': @json('Diverifikasi'),
                        'issued': @json('Sudah Diterbitkan'), 'processing': @json('Sedang Diproses'), 'done': @json('Selesai')
                    };
                    const statusClasses = {
                        'pending': 'bg-secondary', 'received': 'bg-info', 'verified': 'bg-success',
                        'issued': 'bg-info', 'processing': 'bg-info', 'done': 'bg-success'
                    };

                    const badge = $('#doc-badge-' + docId);
                    badge.text(statusLabels[res.doc.status] || res.doc.status);
                    badge.attr('class', 'badge mb-2 doc-status-badge ' + (statusClasses[res.doc.status] || 'bg-secondary'));
                    badge.attr('data-status', res.doc.status);
                    $('#doc-date-' + docId).text(res.doc.updated_at);

                    $(`.btn-update-doc[data-doc-id="${docId}"]`).data('doc-status', res.doc.status);

                    const completedStatuses = ['received', 'verified', 'done'];
                    let completed = 0;
                    const total = {{ $totalDocs }};
                    completed = $('.doc-status-badge').filter(function() {
                        return completedStatuses.includes($(this).attr('data-status'));
                    }).length;

                    const pct = total > 0 ? (completed / total * 100) : 0;
                    $('#docProgressBar').css('width', pct + '%');
                    $('#docProgressBadge').text(completed + '/' + total + ' ' + @json('Dokumen Selesai'));

                    if (completed === total) {
                        if ($('#allDocsAlert').length === 0) {
                            $('.progress').after('<div class="alert alert-success d-flex align-items-center mb-4" id="allDocsAlert"><i class="bi bi-check-circle-fill me-2 fs-5"></i><span class="fw-medium">Semua dokumen impor lengkap ✅</span></div>');
                        }
                    }

                    bootstrap.Modal.getInstance(document.getElementById('updateDocModal')).hide();

                    Swal.fire({
                        icon: 'success',
                        title: @json('Berhasil!'),
                        text: res.message,
                        timer: 1500,
                        showConfirmButton: false
                    });
                }
            },
            error: function(xhr) {
                Swal.fire(@json('Error'), @json('Gagal memperbarui status dokumen.'), 'error');
            },
            complete: function() {
                $('#docSpinner').addClass('d-none');
                $('#btnSaveDocStatus').prop('disabled', false);
            }
        });
    });

    // Confirm Arrival
    $('#btnConfirmArrival').on('click', function() {
        Swal.fire({
            title: @json('Konfirmasi Material Tiba?'),
            text: @json('Tanggal kedatangan akan diset hari ini dan QC akan dinotifikasi.'),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#198754',
            cancelButtonColor: '#6c757d',
            confirmButtonText: @json('Ya, Konfirmasi!'),
            cancelButtonText: @json('Batal')
        }).then((result) => {
            if (result.isConfirmed) {
                $('#arrivalForm').submit();
            }
        });
    });
</script>
@endpush
