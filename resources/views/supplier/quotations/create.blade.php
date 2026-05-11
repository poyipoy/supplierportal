@extends('layouts.app')

@section('title', 'Form Penawaran Harga — ADASI Portal')
@section('page-title', 'Form Penawaran Harga')

@section('content')
<div class="mb-3">
    <a href="{{ route('supplier.quotations.period', $pr->period_id) }}" class="text-decoration-none text-muted small">
        <i class="bi bi-arrow-left me-1"></i> Kembali ke Daftar Permintaan
    </a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white py-3">
        <h6 class="mb-0 fw-bold">Detail Permintaan Pembelian</h6>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-2 text-muted small">Periode</div>
            <div class="col-md-10 fw-medium">{{ $pr->period->name }} ({{ str_pad($pr->period->month, 2, '0', STR_PAD_LEFT) }}/{{ $pr->period->year }})</div>
        </div>
        <div class="row mt-2">
            <div class="col-md-2 text-muted small">Catatan PR</div>
            <div class="col-md-10">{{ $pr->notes ?? '-' }}</div>
        </div>
    </div>
</div>

<form id="quotationForm" action="{{ route('supplier.quotations.store', $pr->id) }}" method="POST">
    @csrf
    <input type="hidden" name="action" id="formAction" value="draft">

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h6 class="mb-0 fw-bold">Pengisian Harga Material</h6>
            <div class="d-flex align-items-center">
                <label for="currency" class="form-label mb-0 me-2 small fw-medium">Mata Uang:</label>
                <select name="currency" id="currency" class="form-select form-select-sm" style="width: 100px;">
                    <option value="USD" {{ old('currency', $quotation->currency ?? 'USD') == 'USD' ? 'selected' : '' }}>USD</option>
                    <option value="JPY" {{ old('currency', $quotation->currency ?? 'JPY') == 'JPY' ? 'selected' : '' }}>JPY</option>
                </select>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-bordered align-middle mb-0" style="font-size: 0.85rem;">
                    <thead class="table-light text-center">
                        <tr>
                            <th width="5%">No</th>
                            <th width="20%">Material & Spesifikasi</th>
                            <th width="10%">Weight (Kg)</th>
                            <th width="15%">Harga per-KG (<span class="currency-label">USD</span>) <span class="text-danger">*</span></th>
                            <th width="15%">Amount (<span class="currency-label">USD</span>)</th>
                            <th width="15%">Est. IDR</th>
                            <th width="20%">Catatan Item</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($pr->items as $index => $item)
                            @php
                                $qItem = null;
                                if ($quotation) {
                                    $qItem = $quotation->items->where('pr_item_id', $item->id)->first();
                                }
                                $oldPrice = old("items.{$index}.price_per_kg", $qItem ? $qItem->price_per_kg : '');
                                $oldNotes = old("items.{$index}.notes", $qItem ? $qItem->notes : '');
                            @endphp
                            <tr>
                                <td class="text-center">{{ $index + 1 }}</td>
                                <td>
                                    <div class="fw-bold">{{ $item->material_name }}</div>
                                    <div class="text-muted" style="font-size: 0.75rem;">
                                        @if($item->hs_code) HS: {{ $item->hs_code }} | @endif
                                        {{ $item->shape }}
                                        @if($item->thickness) T:{{ $item->thickness }} @endif
                                        @if($item->d_inner) ID:{{ $item->d_inner }} @endif
                                        @if($item->d_outer) OD:{{ $item->d_outer }} @endif
                                        @if($item->width) W:{{ $item->width }} @endif
                                        @if($item->length) L:{{ $item->length }} @endif
                                    </div>
                                    <input type="hidden" name="items[{{ $index }}][pr_item_id]" value="{{ $item->id }}">
                                    <input type="hidden" class="item-weight" value="{{ $item->weight_needed }}">
                                </td>
                                <td class="text-center fw-medium text-primary">{{ number_format($item->weight_needed, 2) }}</td>
                                <td>
                                    <input type="number" step="0.0001" name="items[{{ $index }}][price_per_kg]" class="form-control form-control-sm price-input text-end" value="{{ $oldPrice }}" required>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm amount-display text-end bg-light" readonly>
                                </td>
                                <td>
                                    <input type="text" class="form-control form-control-sm idr-display text-end bg-light" readonly>
                                </td>
                                <td>
                                    <input type="text" name="items[{{ $index }}][notes]" class="form-control form-control-sm" value="{{ $oldNotes }}" placeholder="Opsional">
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-bold">
                        <tr>
                            <td colspan="4" class="text-end">TOTAL</td>
                            <td class="text-end" id="totalAmount">0.00</td>
                            <td class="text-end text-primary" id="totalIdr">0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white py-3">
            <h6 class="mb-0 fw-bold">Informasi Tambahan</h6>
        </div>
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Estimasi Waktu Pengiriman <span class="text-danger">*</span></label>
                    <input type="date" name="estimated_delivery" class="form-control" value="{{ old('estimated_delivery', $quotation->estimated_delivery ?? '') }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Masa Berlaku Penawaran</label>
                    <input type="date" name="validity_period" class="form-control" value="{{ old('validity_period', $quotation->validity_period ?? '') }}">
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <label class="form-label fw-medium">Syarat Pembayaran</label>
                    <textarea name="payment_terms" class="form-control" rows="2" placeholder="Contoh: TT 30 Days">{{ old('payment_terms', $quotation->payment_terms ?? '') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-medium">Catatan Umum</label>
                    <textarea name="general_notes" class="form-control" rows="2" placeholder="Opsional...">{{ old('general_notes', $quotation->general_notes ?? '') }}</textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mb-5">
        <button type="button" class="btn btn-secondary" onclick="submitForm('draft')">Simpan Draft</button>
        <button type="button" class="btn btn-primary" style="background-color: var(--adasi-blue);" onclick="confirmSubmit()">Kirim Penawaran Final</button>
    </div>
</form>

{{-- Info Kurs untuk JS --}}
<div id="exchangeRates" data-usd="{{ $usdRate->rate_to_idr ?? 1 }}" data-jpy="{{ $jpyRate->rate_to_idr ?? 1 }}" class="d-none"></div>

@endsection

@push('scripts')
<script>
    const rates = {
        'USD': parseFloat($('#exchangeRates').data('usd')),
        'JPY': parseFloat($('#exchangeRates').data('jpy'))
    };

    function calculateRow(row) {
        const weight = parseFloat(row.find('.item-weight').val()) || 0;
        const price = parseFloat(row.find('.price-input').val()) || 0;
        const currency = $('#currency').val();
        const rate = rates[currency];

        const amount = weight * price;
        const idr = amount * rate;

        row.find('.amount-display').val(amount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        row.find('.idr-display').val(Math.round(idr).toLocaleString('id-ID'));

        return { amount, idr };
    }

    function calculateTotal() {
        let totalAmount = 0;
        let totalIdr = 0;

        $('tbody tr').each(function() {
            const rowTotals = calculateRow($(this));
            totalAmount += rowTotals.amount;
            totalIdr += rowTotals.idr;
        });

        $('#totalAmount').text(totalAmount.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}));
        $('#totalIdr').text('Rp ' + Math.round(totalIdr).toLocaleString('id-ID'));
    }

    $('#currency').change(function() {
        $('.currency-label').text($(this).val());
        calculateTotal();
    });

    $('.price-input').on('input', function() {
        calculateTotal();
    });

    $(document).ready(function() {
        calculateTotal(); // initial calculation if pre-filled
    });

    function submitForm(action) {
        $('#formAction').val(action);
        $('#quotationForm').submit();
    }

    function confirmSubmit() {
        // Validate required fields visually
        let isValid = true;
        $('#quotationForm').find('input[required]').each(function() {
            if (!$(this).val()) {
                isValid = false;
                $(this).addClass('is-invalid');
            } else {
                $(this).removeClass('is-invalid');
            }
        });

        if (!isValid) {
            Swal.fire(@json('Error'), @json('Mohon lengkapi semua field yang wajib diisi (Harga & Estimasi Waktu).'), 'error');
            return;
        }

        Swal.fire({
            title: @json('Kirim Penawaran Final?'),
            text: @json('Penawaran yang sudah dikirim tidak dapat diubah lagi.'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--adasi-blue)',
            cancelButtonColor: '#6c757d',
            confirmButtonText: @json('Ya, Kirim!'),
            cancelButtonText: @json('Batal')
        }).then((result) => {
            if (result.isConfirmed) {
                $('#formAction').val('submitted');
                $('#quotationForm').submit();
            }
        });
    }
</script>
@endpush
