@extends('layouts.app')

@section('title', 'Detail Permintaan Material — ADASI Portal')
@section('page-title', __('Detail Permintaan Material'))

@section('content')
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">{{ $pr->pr_number ?? __('Draft Permintaan') }}</h6>
                @php
                    $badgeClass = match($pr->status) {
                        'draft' => 'bg-secondary',
                        'submitted' => 'bg-primary',
                        'approved' => 'bg-success',
                        'rejected' => 'bg-danger',
                        'bidding' => 'bg-warning text-dark',
                        'completed' => 'bg-success',
                        default => 'bg-secondary'
                    };
                    $statusLabel = match($pr->status) {
                        'draft' => __('Draft'),
                        'submitted' => __('Submitted'),
                        'approved' => __('Approved'),
                        'rejected' => __('Rejected'),
                        'bidding' => __('Bidding'),
                        'completed' => __('Completed'),
                        default => __(ucwords(str_replace('_', ' ', $pr->status))),
                    };
                @endphp
                <span class="badge {{ $badgeClass }} text-uppercase px-3 py-2">{{ $statusLabel }}</span>
            </div>
            <div class="card-body">
                <div class="row mb-3">
                    <div class="col-md-4 text-muted small">{{ __('Periode') }}</div>
                    <div class="col-md-8 fw-medium">{{ $pr->period->name }} ({{ str_pad($pr->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $pr->period->year }})</div>
                </div>
                <div class="row mb-3">
                    <div class="col-md-4 text-muted small">{{ __('Tanggal Dibuat') }}</div>
                    <div class="col-md-8 fw-medium">{{ $pr->created_at->format('d F Y, H:i') }}</div>
                </div>
                <div class="row">
                    <div class="col-md-4 text-muted small">{{ __('Catatan Tambahan') }}</div>
                    <div class="col-md-8 fw-medium">
                        @if($pr->notes)
                            {{ $pr->notes }}
                        @else
                            <em class="text-muted">{{ __('Tidak ada catatan') }}</em>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">{{ __('Daftar Material') }} ({{ $pr->items->count() }} {{ __('Item') }})</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light text-center" style="font-size: 0.8rem;">
                            <tr>
                                <th>{{ __('No') }}</th>
                                <th>{{ __('HS Code') }}</th>
                                <th>{{ __('Material Name') }}</th>
                                <th>{{ __('Shape & Dimension (mm)') }}</th>
                                <th>{{ __('Weight (Kg)') }}</th>
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
    </div>

    <div class="col-lg-4">
        {{-- Status / Action Card --}}
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">{{ __('Aksi & Status') }}</h6>
            </div>
            <div class="card-body">
                @if($pr->status === 'draft')
                    <div class="alert alert-secondary small">
                        <i class="bi bi-info-circle me-1"></i> {{ __('Permintaan ini masih berstatus draft. Silakan edit dan ajukan jika sudah selesai.') }}
                    </div>
                    <div class="d-grid gap-2">
                        <a href="{{ route('purchasing.requirements.edit', $pr->id) }}" class="btn btn-outline-primary">{{ __('Edit Draft') }}</a>
                        <form action="{{ route('purchasing.requirements.update', $pr->id) }}" method="POST">
                            @csrf
                            @method('PUT')
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
                            <button type="button" class="btn btn-primary w-100 btn-submit" style="background-color: var(--adasi-blue);">{{ __('Ajukan Permintaan') }}</button>
                        </form>
                    </div>
                @elseif($pr->status === 'rejected')
                    <div class="alert alert-danger small">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i> {{ __('Permintaan ditolak oleh Admin. Silakan periksa catatan dan revisi.') }}
                    </div>
                    <div class="d-grid gap-2">
                        <a href="{{ route('purchasing.requirements.edit', $pr->id) }}" class="btn btn-danger">{{ __('Revisi & Ajukan Ulang') }}</a>
                    </div>
                @else
                    <div class="alert alert-success small mb-0">
                        <i class="bi bi-check-circle-fill me-1"></i> {{ __('Permintaan sudah diproses dan tidak dapat diedit lagi.') }}
                    </div>
                @endif
                <div class="mt-3 text-center">
                    <a href="{{ route('purchasing.requirements.index') }}" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left me-1"></i>{{ __('Kembali ke Daftar') }}</a>
                </div>
            </div>
        </div>

        {{-- Chat Suppliers --}}
        @if($pr->quotations && $pr->quotations->whereIn('status', ['submitted', 'accepted'])->count() > 0)
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <h6 class="mb-0 fw-bold">{{ __('Negosiasi & Chat') }}</h6>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    @foreach($pr->quotations->whereIn('status', ['submitted', 'accepted'])->unique('supplier_id') as $quotation)
                        <form action="{{ route('purchasing.conversations.start.pr', ['pr_id' => $pr->id, 'supplier_id' => $quotation->supplier_id]) }}" method="POST" data-chat-start-form>
                            @csrf
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
                <h6 class="mb-0 fw-bold">{{ __('Timeline') }}</h6>
            </div>
            <div class="card-body p-4">
                <div class="position-relative">
                    <div class="position-absolute h-100 border-start" style="left: 10px; top: 0; border-color: #dee2e6 !important;"></div>
                    
                    {{-- Dibuat --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute bg-primary rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 text-primary fw-bold" style="font-size: 0.9rem;">{{ __('Dibuat') }} ({{ __('Draft') }})</h6>
                        <div class="small text-muted">{{ $pr->created_at->format('d M Y, H:i') }}</div>
                    </div>

                    {{-- Diajukan --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute {{ in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed']) ? 'bg-primary' : 'bg-light border' }} rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 {{ in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed']) ? 'text-primary fw-bold' : 'text-muted' }}" style="font-size: 0.9rem;">{{ __('Diajukan') }} ({{ __('Submitted') }})</h6>
                        @if(in_array($pr->status, ['submitted', 'rejected', 'bidding', 'completed']))
                            <div class="small text-muted">{{ $pr->updated_at->format('d M Y, H:i') }}</div>
                        @endif
                    </div>

                    {{-- Bidding --}}
                    <div class="position-relative mb-4 ps-4">
                        <div class="position-absolute {{ in_array($pr->status, ['bidding', 'completed']) ? 'bg-warning' : 'bg-light border' }} rounded-circle" style="width: 20px; height: 20px; left: 0; top: 0;"></div>
                        <h6 class="mb-1 {{ in_array($pr->status, ['bidding', 'completed']) ? 'text-warning text-dark fw-bold' : 'text-muted' }}" style="font-size: 0.9rem;">{{ __('Penawaran Supplier') }} ({{ __('Bidding') }})</h6>
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
            title: @json(__('Ajukan Permintaan?')),
            text: @json(__('Status akan berubah menjadi Submitted dan tidak bisa diedit lagi.')),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: @json(__('Ya, Ajukan!')),
            cancelButtonText: @json(__('Batal'))
        }).then((result) => {
            if (result.isConfirmed) {
                form.submit();
            }
        });
    });
</script>
@endpush
