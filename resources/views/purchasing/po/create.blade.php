@extends('layouts.app')

@section('title', 'Buat Purchase Order — ADASI Portal')
@section('page-title', 'Buat Purchase Order')

@section('content')
<div class="mb-3">
    <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index') }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali
    </a>
</div>

{{-- Summary Card --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">Ringkasan Penawaran yang Dipilih</h6>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-3 text-muted small">Supplier</div>
            <div class="col-md-9 fw-medium">{{ $quotation->supplier->name }}</div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3 text-muted small">Periode</div>
            <div class="col-md-9 fw-medium">{{ $quotation->purchaseRequirement->period->name }}</div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3 text-muted small">Mata Uang</div>
            <div class="col-md-9 fw-medium">{{ $quotation->currency }}</div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3 text-muted small">Kurs Dipakai</div>
            <div class="col-md-9 fw-medium">
                @if($rate)
                    1 {{ $quotation->currency }} = Rp {{ number_format($rate->rate_to_idr, 0, ',', '.') }}
                @else
                    <span class="text-danger">Kurs tidak tersedia</span>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Material Breakdown --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">Breakdown Material</h6>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="table-light text-center">
                    <tr>
                        <th>No</th>
                        <th>Material</th>
                        <th>Berat (Kg)</th>
                        <th>Harga/Kg ({{ $quotation->currency }})</th>
                        <th>Amount ({{ $quotation->currency }})</th>
                        <th>Est. IDR</th>
                    </tr>
                </thead>
                <tbody>
                    @php $totalAmount = 0; $totalIdr = 0; @endphp
                    @foreach($quotation->items as $index => $item)
                        @php
                            $idr = $item->amount * ($rate ? $rate->rate_to_idr : 1);
                            $totalAmount += $item->amount;
                            $totalIdr += $idr;
                        @endphp
                        <tr>
                            <td class="text-center">{{ $index + 1 }}</td>
                            <td>{{ $item->prItem->material_name }}</td>
                            <td class="text-center">{{ number_format($item->prItem->weight_needed, 2) }}</td>
                            <td class="text-end">{{ number_format($item->price_per_kg, 4) }}</td>
                            <td class="text-end fw-medium">{{ number_format($item->amount, 2) }}</td>
                            <td class="text-end">Rp {{ number_format($idr, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="table-light fw-bold">
                    <tr>
                        <td colspan="4" class="text-end">GRAND TOTAL</td>
                        <td class="text-end">{{ number_format($totalAmount, 2) }} {{ $quotation->currency }}</td>
                        <td class="text-end text-primary">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

{{-- PO Form --}}
<form action="{{ route('purchasing.purchase-orders.store') }}" method="POST" id="poForm">
    @csrf
    <input type="hidden" name="return_url" value="{{ request('return_url') }}">
    <input type="hidden" name="quotation_id" value="{{ $quotation->id }}">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">Informasi Purchase Order</h6>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Estimasi Kedatangan Material <span class="text-danger">*</span></label>
                    <input type="date" name="estimated_arrival" class="form-control @error('estimated_arrival') is-invalid @enderror" value="{{ old('estimated_arrival') }}" required>
                    @error('estimated_arrival') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-12">
                    <label class="form-label fw-medium">Catatan PO</label>
                    <textarea name="notes" class="form-control" rows="2" placeholder="Opsional...">{{ old('notes') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-5">
        <a href="{{ \App\Support\PurchasingNavigation::backUrl('purchasing.quotations.index') }}" class="btn btn-light">Batal</a>
        <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" id="btnCreatePo">
            <i class="bi bi-check-circle me-1"></i> Buat Purchase Order
        </button>
    </div>
</form>

@endsection

@push('scripts')
<script>
    $('#btnCreatePo').on('click', function() {
        Swal.fire({
            title: 'Buat Purchase Order?',
            html: 'PO akan dibuat dan penawaran supplier lain pada PR yang sama akan otomatis <strong>ditolak</strong>.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Ya, Buat PO!',
            cancelButtonText: 'Batal'
        }).then((result) => {
            if (result.isConfirmed) {
                $('#poForm').submit();
            }
        });
    });
</script>
@endpush
