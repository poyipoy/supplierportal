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
        <h6 class="mb-0 fw-bold">Ringkasan Penawaran Utama</h6>
    </div>
    <div class="card-body">
        <div class="row mb-3">
            <div class="col-md-3 text-muted small">Supplier</div>
            <div class="col-md-9 fw-medium">{{ $quotation->supplier->name }}</div>
        </div>
        <div class="row mb-3">
            <div class="col-md-3 text-muted small">No. PR</div>
            <div class="col-md-9 fw-medium">{{ $quotation->purchaseRequirement->pr_number ?? '-' }}</div>
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

{{-- Konsolidasi: Quotation Tambahan --}}
@if($otherQuotations->count() > 0)
<div class="card border-0 shadow-sm mb-4 border-start border-4 border-primary">
    <div class="card-header bg-white py-3">
        <div class="d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold"><i class="bi bi-layers me-2 text-primary"></i>Gabungkan PR Lain ke PO Ini</h6>
            <span class="badge bg-primary rounded-pill">{{ $otherQuotations->count() }} tersedia</span>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Centang penawaran lain dari <strong>{{ $quotation->supplier->name }}</strong> ({{ $quotation->currency }}) yang ingin digabungkan ke dalam satu Purchase Order.
        </p>
        <div class="list-group list-group-flush">
            @foreach($otherQuotations as $oq)
                @php
                    $oqItems = [];
                    foreach ($oq->items as $i) {
                        $oqItems[] = [
                            'material' => $i->prItem->material_name,
                            'weight' => (float)$i->prItem->weight_needed,
                            'price' => (float)$i->price_per_kg,
                            'amount' => (float)$i->amount,
                            'rate' => (float)($oq->exchange_rate?->rate_to_idr ?? 0),
                        ];
                    }
                @endphp
                <label class="list-group-item d-flex align-items-center gap-3 py-3 consolidate-item" for="oq_{{ $oq->id }}" style="cursor: pointer;">
                    <input type="checkbox" class="form-check-input consolidate-check" id="oq_{{ $oq->id }}" value="{{ $oq->id }}" data-items='@json($oqItems)'>
                    <div class="flex-grow-1">
                        <div class="fw-medium">{{ $oq->purchaseRequirement->pr_number ?? '-' }}</div>
                        <div class="text-muted small">
                            {{ $oq->purchaseRequirement->period->name ?? '-' }} • {{ $oq->items->count() }} item
                            @if($oq->exchange_rate)
                                • Kurs: Rp {{ number_format($oq->exchange_rate->rate_to_idr, 0, ',', '.') }}
                            @endif
                        </div>
                    </div>
                    <div class="text-end">
                        @php
                            $oqTotal = $oq->items->sum('amount');
                            $oqRate = $oq->exchange_rate;
                            $oqIdr = $oqTotal * ($oqRate ? $oqRate->rate_to_idr : 1);
                        @endphp
                        <div class="fw-bold small">{{ number_format($oqTotal, 2) }} {{ $oq->currency }}</div>
                        <div class="text-muted small">≈ Rp {{ number_format($oqIdr, 0, ',', '.') }}</div>
                    </div>
                </label>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- Material Breakdown --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">Breakdown Material</h6>
        <span class="badge bg-primary rounded-pill" id="totalItemCount">{{ $quotation->items->count() }} item</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                <thead class="table-light text-center">
                    <tr>
                        <th>No</th>
                        <th>No. PR</th>
                        <th>Material</th>
                        <th>Berat (Kg)</th>
                        <th>Harga/Kg ({{ $quotation->currency }})</th>
                        <th>Amount ({{ $quotation->currency }})</th>
                        <th>Est. IDR</th>
                    </tr>
                </thead>
                <tbody id="materialTableBody">
                    @php $totalAmount = 0; $totalIdr = 0; $no = 1; @endphp
                    @foreach($quotation->items as $item)
                        @php
                            $idr = $item->amount * ($rate ? $rate->rate_to_idr : 1);
                            $totalAmount += $item->amount;
                            $totalIdr += $idr;
                        @endphp
                        <tr>
                            <td class="text-center">{{ $no++ }}</td>
                            <td class="fw-medium text-primary">{{ $quotation->purchaseRequirement->pr_number ?? '-' }}</td>
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
                        <td colspan="5" class="text-end">GRAND TOTAL</td>
                        <td class="text-end" id="grandTotalAmount">{{ number_format($totalAmount, 2) }} {{ $quotation->currency }}</td>
                        <td class="text-end text-primary" id="grandTotalIdr">Rp {{ number_format($totalIdr, 0, ',', '.') }}</td>
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
    {{-- Primary quotation always included --}}
    <input type="hidden" name="quotation_ids[]" value="{{ $quotation->id }}">
    {{-- Additional quotations added dynamically --}}
    <div id="additionalQuotationInputs"></div>

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

@php
    $primaryItemsData = [];
    foreach ($quotation->items as $i) {
        $primaryItemsData[] = [
            'pr_number' => $quotation->purchaseRequirement->pr_number ?? '-',
            'material' => $i->prItem->material_name,
            'weight' => (float)$i->prItem->weight_needed,
            'price' => (float)$i->price_per_kg,
            'amount' => (float)$i->amount,
            'rate' => (float)($rate?->rate_to_idr ?? 0),
        ];
    }
@endphp

@push('scripts')
<script>
    // Store primary quotation items for the table
    const primaryItems = @json($primaryItemsData);
    const currency = @json($quotation->currency);

    function formatNumber(num, decimals) {
        return Number(num).toLocaleString('id-ID', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    }

    function rebuildTable() {
        let allItems = [];

        // Primary quotation items
        primaryItems.forEach(item => allItems.push(item));

        // Additional checked quotation items
        $('.consolidate-check:checked').each(function() {
            const items = $(this).data('items');
            const prLabel = $(this).closest('.consolidate-item').find('.fw-medium').text();
            items.forEach(item => {
                allItems.push({
                    pr_number: prLabel,
                    material: item.material,
                    weight: item.weight,
                    price: item.price,
                    amount: item.amount,
                    rate: item.rate,
                });
            });
        });

        // Rebuild table body
        let html = '';
        let totalAmount = 0;
        let totalIdr = 0;
        allItems.forEach((item, i) => {
            const idr = item.amount * (item.rate || 1);
            totalAmount += item.amount;
            totalIdr += idr;
            html += `<tr>
                <td class="text-center">${i + 1}</td>
                <td class="fw-medium text-primary">${item.pr_number}</td>
                <td>${item.material}</td>
                <td class="text-center">${formatNumber(item.weight, 2)}</td>
                <td class="text-end">${formatNumber(item.price, 4)}</td>
                <td class="text-end fw-medium">${formatNumber(item.amount, 2)}</td>
                <td class="text-end">Rp ${formatNumber(idr, 0)}</td>
            </tr>`;
        });

        $('#materialTableBody').html(html);
        $('#grandTotalAmount').text(formatNumber(totalAmount, 2) + ' ' + currency);
        $('#grandTotalIdr').text('Rp ' + formatNumber(totalIdr, 0));
        $('#totalItemCount').text(allItems.length + ' item');

        // Update hidden inputs for additional quotation_ids
        $('#additionalQuotationInputs').empty();
        $('.consolidate-check:checked').each(function() {
            $('#additionalQuotationInputs').append(
                `<input type="hidden" name="quotation_ids[]" value="${$(this).val()}">`
            );
        });
    }

    // Listen for checkbox changes
    $(document).on('change', '.consolidate-check', function() {
        rebuildTable();
    });

    // Submit confirmation
    $('#btnCreatePo').on('click', function() {
        const checkedCount = $('.consolidate-check:checked').length;
        const totalPr = 1 + checkedCount;
        const prMsg = totalPr > 1
            ? `PO akan dibuat dengan menggabungkan <strong>${totalPr} PR</strong>. `
            : '';

        Swal.fire({
            title: 'Buat Purchase Order?',
            html: prMsg + 'Penawaran supplier lain pada PR yang sama akan otomatis <strong>ditolak</strong>.',
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
