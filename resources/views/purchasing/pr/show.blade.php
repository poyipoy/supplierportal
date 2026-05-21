@extends('layouts.app')

@section('title', 'Detail Permintaan Material — ADASI Portal')
@section('page-title', 'Detail Permintaan Material')

@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">{{ $pr->pr_number ?? 'Draft Permintaan' }}</h6>
                @php
                    $badgeClass = match($pr->status) {
                        'draft' => 'bg-secondary',
                        'submitted' => 'bg-primary',
                        'rejected' => 'bg-danger',
                        'bidding' => 'bg-warning text-dark',
                        'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match($pr->status) {
                        'draft' => 'Draft',
                        'submitted' => 'Submitted',
                        'rejected' => 'Rejected',
                        'bidding' => 'Bidding',
                        'completed' => 'Completed',
                        default => ucwords(str_replace('_', ' ', $pr->status)),
                    };
                @endphp
                <span class="badge {{ $badgeClass }} text-uppercase px-3 py-2">{{ $statusLabel }}</span>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 text-muted small">Periode</div>
                    <div class="col-md-8 fw-medium">{{ $pr->period->name }} ({{ str_pad($pr->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $pr->period->year }})</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-muted small">Tanggal Dibuat</div>
                    <div class="col-md-8 fw-medium">{{ $pr->created_at->format('d F Y, H:i') }}</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-muted small">Dibuat Oleh</div>
                    <div class="col-md-8 fw-medium">{{ $pr->creator->name ?? '-' }}</div>
                </div>
                <div class="row">
                    <div class="col-md-4 text-muted small">Catatan Tambahan</div>
                    <div class="col-md-8 fw-medium">
                        @if($pr->notes)
                            {{ $pr->notes }}
                        @else
                            <em class="text-muted">Tidak ada catatan</em>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Daftar Material ({{ $pr->items->count() }} Item)</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-center" style="font-size: 0.8rem;">
                            <tr>
                                <th>No</th>
                                <th>HS Code</th>
                                <th>Material Name</th>
                                <th>Shape & Dimension (mm)</th>
                                <th>Weight (Kg)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($pr->items as $index => $item)
                                <tr>
                                    <td class="text-center">{{ $index + 1 }}</td>
                                    <td class="text-center">{{ $item->hs_code ?? '-' }}</td>
                                    <td class="fw-medium">{{ $item->material_name }}</td>
                                    <td class="text-center" style="font-size: 0.85rem;">
                                        @if($item->shape)
                                            <span class="badge bg-light text-dark border">{{ $item->shape }}</span><br>
                                            @if($item->thickness) T: {{ $item->thickness }} @endif
                                            @if($item->d_inner) ID: {{ $item->d_inner }} @endif
                                            @if($item->d_outer) OD: {{ $item->d_outer }} @endif
                                            @if($item->width) W: {{ $item->width }} @endif
                                            @if($item->length) L: {{ $item->length }} @endif
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="text-center fw-bold text-primary">{{ number_format($item->weight_needed, 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">Penawaran yang Masuk</h6>
                <span class="badge bg-primary rounded-pill">{{ $quotations->count() }}</span>
            </div>
            <div class="card-body p-0">
                @if($quotations->isEmpty())
                    <div class="alert alert-info mb-0 rounded-0 border-0">
                        <i class="bi bi-info-circle me-1"></i> Belum ada penawaran masuk dari supplier.
                    </div>
                @else
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0" style="font-size: 0.85rem;">
                            <thead class="table-light text-center">
                                <tr>
                                    <th>Supplier</th>
                                    <th>Mata Uang</th>
                                    <th>Total Harga</th>
                                    <th>Estimasi IDR</th>
                                    <th>Est. Pengiriman</th>
                                    <th>Tanggal Diajukan</th>
                                    <th>Status</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($quotations as $quotation)
                                    @php
                                        $statusBadge = match($quotation->status) {
                                            'draft' => 'bg-secondary',
                                            'submitted' => 'bg-primary',
                                            'revision_requested' => 'bg-warning text-dark',
                                            'accepted' => 'bg-success',
                                            'rejected' => 'bg-danger',
                                            default => 'bg-secondary',
                                        };
                                        $statusLabel = match($quotation->status) {
                                            'draft' => 'Draft',
                                            'submitted' => 'Terkirim',
                                            'revision_requested' => 'Perlu Revisi',
                                            'accepted' => 'Diterima',
                                            'rejected' => 'Ditolak',
                                            default => ucwords(str_replace('_', ' ', $quotation->status)),
                                        };
                                        $isLowest = $lowestTotalIdr !== null
                                            && $quotation->total_idr !== null
                                            && abs((float) $quotation->total_idr - (float) $lowestTotalIdr) < 0.01;
                                        $supplierName = $quotation->supplier->supplier->company_name ?? $quotation->supplier->name ?? '-';
                                    @endphp
                                    <tr class="{{ $isLowest ? 'table-success' : '' }}">
                                        <td class="fw-medium">{{ $supplierName }}</td>
                                        <td class="text-center"><span class="badge bg-dark">{{ $quotation->currency }}</span></td>
                                        <td class="text-end">{{ number_format($quotation->total_amount, 2, ',', '.') }}</td>
                                        <td class="text-end fw-bold {{ $isLowest ? 'text-success' : 'text-primary' }}">
                                            @if($quotation->total_idr !== null)
                                                Rp {{ number_format($quotation->total_idr, 0, ',', '.') }}
                                                @if($isLowest)<i class="bi bi-check-circle-fill ms-1"></i>@endif
                                            @else
                                                <span class="text-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="text-center">
                                            {{ $quotation->estimated_delivery ? date('d M Y', strtotime($quotation->estimated_delivery)) : '-' }}
                                        </td>
                                        <td class="text-center">
                                            {{ $quotation->submitted_at ? $quotation->submitted_at->format('d M Y, H:i') : '-' }}
                                        </td>
                                        <td class="text-center">
                                            <span class="badge {{ $statusBadge }} text-uppercase">{{ $statusLabel }}</span>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="{{ route('purchasing.quotations.show', [$quotation->id, \App\Support\PurchasingNavigation::RETURN_URL_KEY => request()->fullUrl()]) }}" class="btn btn-outline-primary">
                                                    <i class="bi bi-eye me-1"></i> Lihat Detail
                                                </a>
                                                @if($submittedQuotationCount >= 2)
                                                    <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.comparison.inter-supplier', ['pr_id' => $pr->id]) }}" class="btn btn-outline-success">
                                                        <i class="bi bi-bar-chart me-1"></i> Bandingkan
                                                    </a>
                                                @endif
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        {{-- Status / Action Card --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Aksi & Status</h6>
            </div>
            <div class="card-body">
                @if($pr->created_by !== auth()->id())
                    <div class="alert alert-info small mb-0">
                        <i class="bi bi-eye-fill me-1"></i> Anda melihat permintaan yang dibuat oleh {{ $pr->creator->name ?? 'purchasing lain' }}. Aksi edit dan hapus hanya tersedia untuk pembuat PR.
                    </div>
                @elseif($pr->status === 'draft')
                    <div class="alert alert-secondary small">
                        <i class="bi bi-info-circle me-1"></i> Permintaan ini masih berstatus draft. Silakan edit dan ajukan jika sudah selesai.
                    </div>
                    <div class="d-grid gap-2">
                        <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requirements.edit', $pr->id) }}" class="btn btn-outline-primary">Edit Draft</a>
                        <form action="{{ route('purchasing.requirements.update', $pr->id) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="return_url" value="{{ request('return_url') }}">
                            <input type="hidden" name="action" value="submitted">
                            <input type="hidden" name="period_id" value="{{ $pr->period_id }}">
                            <input type="hidden" name="notes" value="{{ $pr->notes }}">
                            @foreach($pr->items as $index => $item)
                                <input type="hidden" name="items[{{ $index }}][hs_code]" value="{{ $item->hs_code }}">
                                <input type="hidden" name="items[{{ $index }}][material_name]" value="{{ $item->material_name }}">
                                <input type="hidden" name="items[{{ $index }}][shape]" value="{{ $item->shape }}">
                                <input type="hidden" name="items[{{ $index }}][thickness]" value="{{ $item->thickness }}">
                                <input type="hidden" name="items[{{ $index }}][d_inner]" value="{{ $item->d_inner }}">
                                <input type="hidden" name="items[{{ $index }}][d_outer]" value="{{ $item->d_outer }}">
                                <input type="hidden" name="items[{{ $index }}][width]" value="{{ $item->width }}">
                                <input type="hidden" name="items[{{ $index }}][length]" value="{{ $item->length }}">
                                <input type="hidden" name="items[{{ $index }}][weight_needed]" value="{{ $item->weight_needed }}">
                            @endforeach
                            <button type="button" class="btn btn-primary w-100 btn-submit" style="background-color: var(--adasi-blue);">Ajukan Permintaan</button>
                        </form>
                    </div>
                @elseif($pr->status === 'rejected')
                    <div class="alert alert-danger small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> Permintaan ditolak oleh Admin. Silakan periksa catatan dan revisi.
                    </div>
                    <div class="d-grid gap-2">
                        <a href="{{ \App\Support\PurchasingNavigation::toRoute('purchasing.requirements.edit', $pr->id) }}" class="btn btn-danger">Revisi & Ajukan Ulang</a>
                    </div>
                @else
                    <div class="alert alert-success small mb-0">
                        <i class="bi bi-check-circle-fill me-1"></i> Permintaan sudah diproses dan tidak dapat diedit lagi.
                    </div>
                @endif
                <div class="mt-3 text-center">
                    <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.requirements.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>Kembali ke Daftar</a>
                </div>
            </div>
        </div>

        {{-- Chat Suppliers --}}
        @if($pr->quotations && $pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->count() > 0)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Negosiasi & Chat</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    @foreach($pr->quotations->whereIn('status', ['submitted', 'revision_requested', 'accepted'])->unique('supplier_id') as $quotation)
                        <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $pr->id, 'supplier_id' => $quotation->supplier_id]) }}" method="POST" data-chat-start-form>
                            @csrf
                            <input type="hidden" name="return_url" value="{{ \App\Support\PurchasingNavigation::currentUrlForReturn() }}">
                            <button type="submit" class="btn btn-outline-primary w-100 text-start">
                                <i class="bi bi-chat-dots me-2"></i> {{ $quotation->supplier->supplier->company_name ?? $quotation->supplier->name }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        </div>
        @endif

        {{-- Timeline Card --}}
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">Timeline</h6>
            </div>
            <div class="card-body p-4">
                <div class="position-relative">
                    <div class="position-absolute h-100 border-start" style="left: 10px; top: 0; border-color: #dee2e6 !important;"></div>
                    
                    {{-- Dibuat --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute bg-primary rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 text-primary fw-bold" style="font-size: 0.9rem;">Dibuat (Draft)</h6>
                        <div class="small text-muted">{{ $pr->created_at->format('d M Y, H:i') }}</div>
                    </div>

                    {{-- Diajukan --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute {{ in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed']) ? 'bg-primary' : 'bg-light border' }} rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 {{ in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed']) ? 'text-primary fw-bold' : 'text-muted' }}" style="font-size: 0.9rem;">Diajukan (Submitted)</h6>
                        @if(in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed']))
                            <div class="small text-muted">{{ $pr->updated_at->format('d M Y, H:i') }}</div>
                        @endif
                    </div>

                    {{-- Bidding --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute {{ in_array($pr->status, ['bidding', 'completed']) ? 'bg-warning' : 'bg-light border' }} rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 {{ in_array($pr->status, ['bidding', 'completed']) ? 'text-warning text-dark fw-bold' : 'text-muted' }}" style="font-size: 0.9rem;">Penawaran Supplier (Bidding)</h6>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    $('.btn-submit').on('click', function() {
        const form = $(this).closest('form');
        Swal.fire({
            title: @json('Ajukan Permintaan?'),
            text: @json('Status akan berubah menjadi Submitted dan tidak bisa diedit lagi.'),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: @json('Ya, Ajukan!'),
            cancelButtonText: @json('Batal')
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
